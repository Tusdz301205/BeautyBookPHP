<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\MediaFile;
use App\Models\ReviewPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class ReviewPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_upload_requires_an_owned_unreviewed_completed_booking(): void
    {
        $customer = $this->actor('customer');
        $booking = $this->booking($customer);
        $pending = $this->booking($customer, 'CONFIRMED');

        $this->upload($pending)->assertUnprocessable();
        $this->actor('owner');
        $this->upload($booking)->assertForbidden();
        $this->actor('customer');
        $other = $this->booking($customer);
        $other->update(['customer_profile_id' => null]);
        $this->upload($other)->assertForbidden();

        $this->postJson('/api/v1/reviews', $this->payload($booking))->assertCreated()->assertJsonPath('photos', []);
        $this->upload($booking)->assertUnprocessable();
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk()->assertJsonPath('review.photos', []);
        $this->assertDatabaseCount('review_photos', 0);
    }

    public function test_upload_uses_file_content_and_rejects_over_size_and_staging_limit(): void
    {
        $booking = $this->booking($this->actor('customer'));
        $this->upload($booking, UploadedFile::fake()->createWithContent('claimed.png', '<svg></svg>'))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($booking, UploadedFile::fake()->createWithContent('truncated.png', substr($this->png(), 0, -12)))
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($booking, UploadedFile::fake()->createWithContent('large.png', str_pad($this->png(), ReviewPhoto::MAX_BYTES + 1, 'x')))
            ->assertUnprocessable()->assertJsonValidationErrors('file');

        $photo = $this->upload($booking)->assertCreated()->json();
        $media = MediaFile::findOrFail($photo['mediaId']);
        $this->assertSame('local', $media->disk);
        $this->assertSame('PRIVATE', $media->visibility);
        $this->assertNull($media->original_name);
        Storage::disk('local')->assertExists($media->path);
        for ($i = 1; $i < ReviewPhoto::MAX_PENDING; $i++) {
            $this->upload($booking)->assertCreated();
        }
        $this->upload($booking)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('review_photos', ReviewPhoto::MAX_PENDING);
        $this->assertCount(ReviewPhoto::MAX_PENDING, Storage::disk('local')->allFiles('review-photos'));
    }

    public function test_submit_rechecks_state_and_rejects_foreign_duplicate_and_excess_photos(): void
    {
        $customer = $this->actor('customer');
        $booking = $this->booking($customer);
        $other = $this->booking($customer);
        $id = $this->upload($booking)->assertCreated()->json('id');
        $wrong = $this->upload($other)->assertCreated()->json('id');
        $this->postJson('/api/v1/reviews', $this->payload($booking) + ['photoIds' => [$id, $wrong]])
            ->assertUnprocessable()->assertJsonValidationErrors('photoIds');
        $this->postJson('/api/v1/reviews', $this->payload($booking) + ['photoIds' => [$id, (int) $id]])
            ->assertUnprocessable()->assertJsonValidationErrors('photoIds.1');
        $this->postJson('/api/v1/reviews', $this->payload($booking) + ['photoIds' => range(1, 6)])
            ->assertUnprocessable()->assertJsonValidationErrors('photoIds');
        $booking->update(['status' => 'CONFIRMED']);
        $this->postJson('/api/v1/reviews', $this->payload($booking) + ['photoIds' => [$id]])->assertUnprocessable();
        $booking->update(['status' => 'COMPLETED', 'customer_profile_id' => null]);
        $this->postJson('/api/v1/reviews', $this->payload($booking) + ['photoIds' => [$id]])->assertForbidden();
        $this->assertDatabaseHas('review_photos', ['id' => $id, 'review_id' => null]);
        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_review_and_photo_links_rollback_together_then_retry_with_the_same_ids(): void
    {
        $booking = $this->booking($this->actor('customer'));
        $first = $this->upload($booking)->assertCreated()->json('id');
        $second = $this->upload($booking)->assertCreated()->json('id');
        $payload = $this->payload($booking) + ['photoIds' => [$second, $first]];
        $simulateFailure = true;
        ReviewPhoto::updated(function (ReviewPhoto $photo) use ($first, &$simulateFailure) {
            if ($simulateFailure && (string) $photo->id === $first) throw new RuntimeException('Synthetic failure during review attachment');
        });

        try {
            $this->postJson('/api/v1/reviews', $payload)->assertServerError();
        } finally {
            $simulateFailure = false;
        }
        $this->assertDatabaseCount('reviews', 0);
        $this->assertSame(2, ReviewPhoto::whereNull('review_id')->count());
        $this->assertCount(2, Storage::disk('local')->allFiles('review-photos'));
        $this->postJson('/api/v1/reviews', $payload)->assertCreated()
            ->assertJsonPath('photos.0.id', $second)->assertJsonPath('photos.1.id', $first);
        $this->postJson('/api/v1/reviews', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_public_bytes_and_review_payload_follow_moderation_and_keep_anonymous_metadata_safe(): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => 'platform'], [
            'value' => json_encode(['allowAnonymousReview' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $booking = $this->booking($this->actor('customer'));
        $photo = $this->upload($booking)->assertCreated()->json();
        $unused = $this->upload($booking)->assertCreated()->json();
        $publicUrl = '/media/'.$photo['mediaId'];
        $this->get($publicUrl)->assertNotFound();
        $this->get($photo['contentUrl'])->assertOk();
        $this->actor('owner');
        $this->get($photo['contentUrl'])->assertNotFound();

        $this->actor('customer');
        $review = $this->postJson('/api/v1/reviews', $this->payload($booking) + ['isAnonymous' => true, 'photoIds' => [$photo['id']]])
            ->assertCreated()->assertJsonPath('customerName', 'Ẩn danh')->assertJsonCount(1, 'photos')->json();
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertOk()->assertJsonPath('review.photos.0.id', $photo['id']);
        $reviews = "/api/v1/reviews/business/{$booking->business_id}";
        $public = $this->getJson($reviews)->assertOk()->assertJsonPath('data.0.customerName', 'Ẩn danh')
            ->assertJsonPath('data.0.customerAvatar', null)->assertJsonPath('data.0.photos.0.contentUrl', $publicUrl)->json('data.0.photos.0');
        $this->assertSame(['id', 'mediaId', 'contentUrl'], array_keys($public));
        $file = $this->get($publicUrl)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $file->headers->get('Cache-Control'));
        $this->assertSame($this->png(), $file->getContent());
        $this->get('/media/'.$unused['mediaId'])->assertNotFound();

        $this->actor('owner');
        $this->postJson("/api/v1/reviews/{$review['id']}/report", ['reason' => 'Cần kiểm duyệt', 'severity' => 'LOW'])
            ->assertOk()->assertJsonPath('status', 'REPORTED');
        $this->getJson($reviews)->assertOk()->assertJsonCount(0, 'data');
        $this->get($publicUrl)->assertNotFound();
        $this->get($photo['contentUrl'])->assertOk();
        $this->deleteJson('/api/v1/media/'.$photo['mediaId'])->assertNotFound();
        $this->actor('owner2');
        $this->get($photo['contentUrl'])->assertForbidden();

        $this->actor('admin');
        $moderate = "/api/v1/reviews/{$review['id']}/moderate";
        $this->patchJson($moderate, ['status' => 'APPROVED', 'reason' => 'Đã kiểm duyệt'])->assertOk();
        $this->get($publicUrl)->assertOk();
        $this->patchJson($moderate, ['status' => 'HIDDEN', 'reason' => 'Đã ẩn'])->assertOk();
        $this->getJson($reviews)->assertOk()->assertJsonCount(0, 'data');
        $this->get($publicUrl)->assertNotFound();
        $this->get($photo['contentUrl'])->assertOk();
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'review-photo-test'),
            'beautybook_access_expires' => time() + 900,
        ])->withHeader('Authorization', 'Bearer review-photo-test');

        return $user;
    }

    private function booking(User $customer, string $status = 'COMPLETED'): Booking
    {
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();

        return Booking::create([
            'business_id' => $branch->business_id, 'branch_id' => $branch->id,
            'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'REVIEW-PHOTO-'.uniqid(), 'status' => $status,
            'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(),
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000,
        ]);
    }

    private function upload(Booking $booking, ?UploadedFile $file = null): TestResponse
    {
        return $this->post("/api/v1/reviews/booking/{$booking->id}/photos", [
            'file' => $file ?? UploadedFile::fake()->createWithContent('private-name.png', $this->png()),
        ], ['Accept' => 'application/json']);
    }

    private function payload(Booking $booking): array
    {
        return ['bookingId' => $booking->id, 'overallRating' => 5, 'comment' => 'Trải nghiệm rất tốt.'];
    }

    private function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da63fcff1f0002eb01f569769cac0000000049454e44ae426082');
    }
}
