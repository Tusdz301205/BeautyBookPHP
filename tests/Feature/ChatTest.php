<?php

namespace Tests\Feature;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Business;
use App\Models\BranchServiceOffering;
use App\Models\User;
use App\Services\Identity\RoleAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_customer_and_scoped_operators_share_one_conversation_with_reloadable_history_and_read_state(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->assertOk()->json('id');
        // Opening again from the same booking, or from the operator side, lands in the same conversation.
        $this->assertSame($conversation, $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id'));

        $first = (string) Str::uuid();
        $sent = $this->send('customer', $conversation, $first, 'Cho mình hỏi về lịch hẹn', $booking->id)->assertCreated()->json();
        $this->assertSame(1, $sent['sequence']);
        $this->assertFalse($sent['fromBranch']);
        $this->assertSame($booking->booking_code, $sent['booking']['bookingCode']);
        // A retry of the same client message returns the same message; changing its text under the same key is refused.
        $this->assertSame($sent['id'], $this->send('customer', $conversation, $first, 'Cho mình hỏi về lịch hẹn', $booking->id)->json('id'));
        $this->send('customer', $conversation, $first, 'Nội dung khác', $booking->id)->assertStatus(409);
        $this->assertSame(1, DB::table('chat_messages')->count());

        foreach (['owner', 'receptionist'] as $operator) {
            $this->actor($operator);
            $list = $this->getJson('/api/v1/chat/branch/conversations')->assertOk()->json();
            $this->assertSame([$conversation], collect($list['items'])->pluck('id')->all(), $operator);
            $this->assertSame(1, $list['items'][0]['unreadCount']);
            $this->assertSame('Khách hàng Demo', $list['items'][0]['title']);
            $history = $this->getJson("/api/v1/chat/branch/conversations/{$conversation}/messages")->assertOk()->json();
            $this->assertSame(['Cho mình hỏi về lịch hẹn'], collect($history['items'])->pluck('body')->all());
            $this->assertTrue($history['canSend']);
        }

        $reply = $this->send('receptionist', $conversation, (string) Str::uuid(), 'Chào bạn, lịch đã được xác nhận.')->assertCreated()->json();
        $this->assertSame(2, $reply['sequence']);
        $this->assertTrue($reply['fromBranch']);
        $this->send('owner', $conversation, (string) Str::uuid(), 'Chủ cơ sở cũng trả lời được.')->assertCreated();

        $this->actor('customer');
        $this->assertSame(2, $this->getJson('/api/v1/chat/customer/conversations')->json('items.0.unreadCount'));
        // Reload: full history, then only what is new after a cursor, then older pages before a cursor.
        $all = $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages")->assertOk()->json();
        $this->assertSame([1, 2, 3], collect($all['items'])->pluck('sequence')->all());
        $this->assertSame([3], collect($this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages?after=2")->json('items'))->pluck('sequence')->all());
        $this->assertSame([1], collect($this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages?before=2")->json('items'))->pluck('sequence')->all());
        $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages?after=1&before=3")->assertStatus(400);
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/read", ['sequence' => 9])->assertUnprocessable();
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/read", ['sequence' => 3])->assertOk()->assertJsonPath('sequence', 3);
        $this->assertSame(0, $this->getJson('/api/v1/chat/customer/conversations')->json('items.0.unreadCount'));
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/read", ['sequence' => 1])->assertOk()->assertJsonPath('sequence', 3);

        $this->actor('receptionist');
        $this->assertSame(3, $this->getJson("/api/v1/chat/branch/conversations/{$conversation}/messages")->json('peerReadSequence'));
    }

    public function test_a_message_that_arrives_between_the_last_poll_and_our_send_is_returned_by_the_next_cursor_poll_and_stays_unread_until_loaded(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');
        $this->send('customer', $conversation, (string) Str::uuid(), 'Mình muốn đổi giờ')->assertCreated();
        $this->send('receptionist', $conversation, (string) Str::uuid(), 'Dạ được ạ')->assertCreated();

        // Session A (customer) polls up to sequence 2 and acknowledges it.
        $this->actor('customer');
        $cursor = collect($this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages")->json('items'))->max('sequence');
        $this->assertSame(2, $cursor);
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/read", ['sequence' => $cursor])->assertOk();

        // Session B (branch) replies (3) after that poll; session A then sends (4) before polling again.
        $this->send('owner', $conversation, (string) Str::uuid(), '10h sáng mai nhé')->assertCreated()->assertJsonPath('sequence', 3);
        $mine = $this->send('customer', $conversation, (string) Str::uuid(), 'Ok, cảm ơn')->assertCreated()->assertJsonPath('sequence', 4)->json();
        $this->assertSame(1, $this->getJson('/api/v1/chat/customer/conversations')->json('items.0.unreadCount'), 'the owner reply is unread: nobody has loaded it');

        // Polling from the last *polled* cursor returns both, in order; polling from our own send would skip 3.
        $page = $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages?after={$cursor}")->assertOk();
        $this->assertSame([3, 4], collect($page->json('items'))->pluck('sequence')->all());
        $this->assertSame($mine['id'], $page->json('items.1.id'));
        $this->assertSame([], $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages?after=4")->json('items'));
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/read", ['sequence' => 4])->assertOk();
        $this->assertSame(0, $this->getJson('/api/v1/chat/customer/conversations')->json('items.0.unreadCount'));

        // The branch session sees the customer's message and that it has been read.
        $this->actor('owner');
        $branch = $this->getJson("/api/v1/chat/branch/conversations/{$conversation}/messages?after=3")->assertOk();
        $this->assertSame([4], collect($branch->json('items'))->pluck('sequence')->all());
        $this->assertSame(4, $branch->json('peerReadSequence'));
    }

    private function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da63fcff1f0002eb01f569769cac0000000049454e44ae426082');
    }

    private function uploadImage(string $actor, string $conversation, ?string $content = null)
    {
        $this->actor($actor);
        $scope = $actor === 'customer' ? 'customer' : 'branch';

        return $this->post("/api/v1/chat/{$scope}/conversations/{$conversation}/images", ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('private-name.png', $content ?? $this->png())], ['Accept' => 'application/json']);
    }

    public function test_private_images_are_uploaded_claimed_by_one_message_and_readable_only_by_members_with_current_access(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');

        $image = $this->uploadImage('customer', $conversation)->assertCreated()->json();
        $this->assertSame(['image/png', strlen($this->png())], [$image['mimeType'], $image['sizeBytes']]);
        $media = DB::table('media_files')->where('entity_type', 'ChatAttachment')->first();
        $this->assertSame(['PRIVATE', 'local', (int) $conversation], [$media->visibility, $media->disk, (int) $media->entity_id]);
        $this->assertStringNotContainsString('private-name', $media->path);
        $path = "/api/v1/chat/customer/conversations/{$conversation}/images/{$image['id']}";

        // Unsent: only the uploader reads it. Another account in the conversation and strangers do not.
        $this->actor('customer');
        $response = $this->get($path)->assertOk();
        $this->assertSame($this->png(), $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->actor('owner');
        $this->get("/api/v1/chat/branch/conversations/{$conversation}/images/{$image['id']}")->assertNotFound();

        // An image that is not an image, an oversized one and a missing file are refused; the unused-upload cap is 10.
        $this->uploadImage('customer', $conversation, '<svg onload="x"></svg>')->assertUnprocessable();
        $this->uploadImage('customer', $conversation, substr($this->png(), 0, -12))->assertUnprocessable();
        $this->actor('customer');
        $this->post("/api/v1/chat/customer/conversations/{$conversation}/images", [], ['Accept' => 'application/json'])->assertUnprocessable();

        // Another sender cannot attach it, and the same sender sends it exactly once.
        $clientId = (string) Str::uuid();
        $this->actor('owner');
        $this->postJson("/api/v1/chat/branch/conversations/{$conversation}/messages", ['clientMessageId' => (string) Str::uuid(), 'attachmentId' => (int) $image['id']])->assertUnprocessable();
        $this->actor('customer');
        $sent = $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/messages", ['clientMessageId' => $clientId, 'attachmentId' => (int) $image['id']])->assertCreated()->json();
        $this->assertSame($image['id'], $sent['attachment']['id']);
        $this->assertSame($sent['id'], $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/messages", ['clientMessageId' => $clientId, 'attachmentId' => (int) $image['id']])->assertCreated()->json('id'));
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/messages", ['clientMessageId' => (string) Str::uuid(), 'attachmentId' => (int) $image['id']])->assertUnprocessable();
        $second = $this->uploadImage('customer', $conversation)->assertCreated()->json();
        $this->actor('customer');
        $this->postJson("/api/v1/chat/customer/conversations/{$conversation}/messages", ['clientMessageId' => $clientId, 'attachmentId' => (int) $second['id']])->assertStatus(409);

        // Once sent, every member of the conversation reads it; history lists the image metadata; the branch push says "image".
        foreach (['owner', 'receptionist'] as $operator) {
            $this->actor($operator);
            $this->get("/api/v1/chat/branch/conversations/{$conversation}/images/{$image['id']}")->assertOk();
            $this->assertSame($image['id'], $this->getJson("/api/v1/chat/branch/conversations/{$conversation}/messages")->json('items.0.attachment.id'));
        }
        $this->assertSame(1, DB::table('notifications')->where('type', 'CHAT_MESSAGE')->where('body', 'Đã gửi một ảnh')->where('user_id', User::where('email', 'owner@beautybook.local')->value('id'))->count());
        // Strangers, other customers and staff outside the branch scope are refused; missing and foreign ids are 404.
        $this->actor('owner2');
        $this->get("/api/v1/chat/branch/conversations/{$conversation}/images/{$image['id']}")->assertNotFound();
        $this->actor('staff');
        $this->get("/api/v1/chat/branch/conversations/{$conversation}/images/{$image['id']}")->assertForbidden();
        $other = $this->customer('khach-khac');
        $this->actingAs($other)->withSession($this->sessionFor($other));
        $this->get("/api/v1/chat/customer/conversations/{$conversation}/images/{$image['id']}")->assertNotFound();
        $this->actor('customer');
        $this->get("/api/v1/chat/customer/conversations/{$conversation}/images/999999")->assertNotFound();

        // Access is checked against the current state: a closed account can still read history but cannot upload; bytes gone = 404, not a leak.
        User::where('email', 'customer@beautybook.local')->update(['is_active' => false]);
        $this->uploadImage('owner', $conversation)->assertStatus(409);
        $media = \App\Models\MediaFile::find(DB::table('chat_attachments')->where('id', $image['id'])->value('media_file_id'));
        \Illuminate\Support\Facades\Storage::disk('local')->delete($media->path);
        $this->actor('owner');
        $this->get("/api/v1/chat/branch/conversations/{$conversation}/images/{$image['id']}")->assertNotFound();
    }

    public function test_image_bytes_are_served_only_when_their_size_and_hash_match_the_database_record(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');
        $image = $this->uploadImage('customer', $conversation)->assertCreated()->json();
        $path = "/api/v1/chat/customer/conversations/{$conversation}/images/{$image['id']}";
        $attachment = DB::table('chat_attachments')->where('id', $image['id'])->first();
        $this->assertSame(hash('sha256', $this->png()), $attachment->sha256);
        $media = \App\Models\MediaFile::findOrFail($attachment->media_file_id);
        $disk = \Illuminate\Support\Facades\Storage::disk($media->disk);

        $this->actor('customer');
        $this->get($path)->assertOk();

        // The stored object is replaced: same length but other bytes, longer (even by a megabyte), shorter, empty. Nothing is served.
        foreach (['swapped' => strrev($this->png()), 'longer' => $this->png().str_repeat('x', 1024 * 1024), 'one byte more' => $this->png().'x', 'shorter' => substr($this->png(), 0, -1), 'empty' => ''] as $case => $bytes) {
            $disk->put($media->path, $bytes);
            $response = $this->get($path);
            $response->assertNotFound();
            $this->assertNotSame($bytes, $response->getContent(), $case);
        }
        $disk->put($media->path, $this->png());
        $this->get($path)->assertOk();

        // The database record is the authority: a hash or size that does not describe the object, or is malformed, serves nothing.
        foreach ([['sha256' => hash('sha256', 'other')], ['sha256' => strtoupper($attachment->sha256)], ['sha256' => 'zz'], ['size_bytes' => $attachment->size_bytes + 1], ['size_bytes' => 0]] as $tamper) {
            DB::table('chat_attachments')->where('id', $image['id'])->update($tamper);
            $this->get($path)->assertNotFound();
        }
        DB::table('chat_attachments')->where('id', $image['id'])->update(['sha256' => $attachment->sha256, 'size_bytes' => $attachment->size_bytes]);
        $this->get($path)->assertOk();
        // The media row and the attachment row must agree on the size too.
        $media->forceFill(['file_size' => $media->file_size + 1])->save();
        $this->get($path)->assertNotFound();
        $media->forceFill(['file_size' => strlen($this->png())])->save();
        $this->get($path)->assertOk();
    }

    public function test_at_most_ten_unsent_uploads_per_sender_and_conversation(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach (range(1, 10) as $_) $this->uploadImage('customer', $conversation)->assertCreated();
        $this->uploadImage('customer', $conversation)->assertUnprocessable();
        $this->assertSame(10, DB::table('chat_attachments')->count());
        $this->assertSame(10, DB::table('media_files')->where('entity_type', 'ChatAttachment')->count());
    }

    public function test_accounts_outside_the_conversation_are_denied_in_both_scopes(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');
        $this->send('customer', $conversation, (string) Str::uuid(), 'Riêng tư')->assertCreated();
        $path = "/api/v1/chat/branch/conversations/{$conversation}";

        // Another business's owner and a receptionist of another branch of the same business see nothing.
        $otherBranch = Branch::where('business_id', $booking->business_id)->whereKeyNot($booking->branch_id)->firstOrFail();
        $stranger = $this->operator('receptionist-other', RoleName::Receptionist, Business::findOrFail($booking->business_id), $otherBranch);
        foreach (['owner2' => null, $stranger->email => $stranger] as $name => $user) {
            $user ? $this->actingAs($user)->withSession($this->sessionFor($user)) : $this->actor((string) $name);
            $this->getJson('/api/v1/chat/branch/conversations')->assertOk()->assertJsonPath('total', 0);
            $this->getJson("{$path}/messages")->assertNotFound();
            $this->postJson("{$path}/messages", ['clientMessageId' => (string) Str::uuid(), 'body' => 'xin chào'])->assertNotFound();
            $this->postJson("{$path}/read", ['sequence' => 0])->assertNotFound();
            $this->postJson('/api/v1/chat/branch/conversations', ['bookingId' => $booking->id])->assertNotFound();
        }
        // Staff, platform admin and the customer role cannot use the branch scope at all.
        foreach (['staff', 'admin', 'customer'] as $actor) {
            $this->actor($actor);
            $this->getJson('/api/v1/chat/branch/conversations')->assertForbidden();
            $this->getJson("{$path}/messages")->assertForbidden();
        }
        // Operators cannot use the customer scope; a different customer cannot read this conversation.
        foreach (['owner', 'receptionist', 'admin'] as $actor) {
            $this->actor($actor);
            $this->getJson('/api/v1/chat/customer/conversations')->assertForbidden();
            $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages")->assertForbidden();
        }
        $other = $this->customer('khach-khac');
        $this->actingAs($other)->withSession($this->sessionFor($other));
        $this->getJson("/api/v1/chat/customer/conversations/{$conversation}/messages")->assertNotFound();
        $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->assertNotFound();
        $this->getJson('/api/v1/chat/nobody/conversations')->assertNotFound();
        $this->assertSame(1, DB::table('chat_messages')->count());
    }

    public function test_validation_and_a_closed_account_make_the_conversation_read_only_and_each_message_notifies_only_the_peers(): void
    {
        $booking = $this->booking();
        $this->actor('customer');
        $conversation = $this->postJson('/api/v1/chat/customer/conversations', ['bookingId' => $booking->id])->json('id');
        $path = "/api/v1/chat/customer/conversations/{$conversation}/messages";
        $this->postJson($path, ['clientMessageId' => 'not-a-uuid', 'body' => 'x'])->assertUnprocessable();
        $this->postJson($path, ['clientMessageId' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($path, ['clientMessageId' => (string) Str::uuid(), 'body' => str_repeat('x', 4001)])->assertUnprocessable();
        $this->postJson($path, ['clientMessageId' => (string) Str::uuid(), 'body' => '   '])->assertUnprocessable();
        // A booking from another customer cannot be shared into this conversation.
        $foreign = $this->booking($this->customer('khach-khac'));
        $this->postJson($path, ['clientMessageId' => (string) Str::uuid(), 'body' => 'x', 'bookingId' => $foreign->id])->assertUnprocessable();

        $this->send('customer', $conversation, (string) Str::uuid(), 'Có ai không?')->assertCreated();
        $owner = User::where('email', 'owner@beautybook.local')->value('id');
        $receptionist = User::where('email', 'receptionist@beautybook.local')->value('id');
        $recipients = DB::table('notifications')->where('type', 'CHAT_MESSAGE')->pluck('user_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame(collect([$owner, $receptionist])->sort()->values()->all(), $recipients, 'owner and branch receptionist, never the sender or other staff');
        $this->assertSame(2, DB::table('notification_outbox')->whereIn('user_id', [$owner, $receptionist])->count());

        $this->send('owner', $conversation, (string) Str::uuid(), 'Có ạ')->assertCreated();
        $customer = User::where('email', 'customer@beautybook.local')->value('id');
        $this->assertSame(1, DB::table('notifications')->where('type', 'CHAT_MESSAGE')->where('user_id', $customer)->count());

        User::whereKey($customer)->update(['is_active' => false]);
        $this->actor('owner');
        $this->assertFalse($this->getJson("/api/v1/chat/branch/conversations/{$conversation}/messages")->json('canSend'));
        $this->send('owner', $conversation, (string) Str::uuid(), 'Còn đó không?')->assertStatus(409);
    }

    private function send(string $actor, string $conversation, string $clientId, string $body, ?int $bookingId = null)
    {
        $this->actor($actor);
        $scope = in_array($actor, ['customer'], true) ? 'customer' : 'branch';

        return $this->postJson("/api/v1/chat/{$scope}/conversations/{$conversation}/messages", ['clientMessageId' => $clientId, 'body' => $body] + ($bookingId ? ['bookingId' => $bookingId] : []));
    }

    private function sessionFor(User $user): array
    {
        return ['workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id, 'beautybook_access_hash' => hash('sha256', 'chat-fixture'), 'beautybook_access_expires' => time() + 900];
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession($this->sessionFor($user))->withHeader('Authorization', 'Bearer chat-fixture');

        return $user;
    }

    private function operator(string $name, RoleName $role, $business, $branch): User
    {
        $user = User::forceCreate(['name' => $name, 'email' => $name.'@beautybook.local', 'password' => bcrypt('BeautyBook123!'), 'account_kind' => AccountKind::Operational, 'is_active' => true, 'email_verified_at' => now()]);
        app(RoleAssignmentService::class)->grant($user, $role, $business, $branch);

        return $user;
    }

    private function customer(string $name): User
    {
        $user = User::forceCreate(['name' => $name, 'email' => $name.'@beautybook.local', 'password' => bcrypt('BeautyBook123!'), 'account_kind' => AccountKind::Customer, 'is_active' => true, 'email_verified_at' => now()]);
        app(RoleAssignmentService::class)->grant($user, RoleName::Customer);
        $user->customerProfile()->firstOrCreate([], []);
        $this->withHeader('Authorization', 'Bearer chat-fixture');

        return $user->fresh();
    }

    private function booking(?User $customer = null): Booking
    {
        $offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
        $customer ??= User::where('email', 'customer@beautybook.local')->firstOrFail();
        $start = now('UTC')->addDay();

        return Booking::create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'CHAT-'.bin2hex(random_bytes(4)), 'status' => 'CONFIRMED', 'appointment_start_at' => $start, 'appointment_end_at' => $start->copy()->addHour(),
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000,
        ]);
    }
}
