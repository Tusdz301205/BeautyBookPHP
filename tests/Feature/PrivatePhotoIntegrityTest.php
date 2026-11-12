<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * media-storage.ts get(): a private photo is served only if the object holds exactly the size and SHA-256 recorded when it was
 * uploaded, whichever reader asks (review photo or result photo). Photos recorded before hashes were kept stay readable on
 * their size alone; the current permission check still decides who may see what is served.
 */
class PrivatePhotoIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession(['workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id, 'beautybook_access_hash' => hash('sha256', 'integrity-fixture'),
            'beautybook_access_expires' => time() + 900])->withHeader('Authorization', 'Bearer integrity-fixture');

        return $user;
    }

    private function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da63fcff1f0002eb01f569769cac0000000049454e44ae426082');
    }

    /** A review photo uploaded by the customer. @return array{0:array,1:MediaFile} */
    private function reviewPhoto(): array
    {
        $customer = $this->actor('customer');
        $branch = Branch::where('slug', 'tan-tao')->firstOrFail();
        $booking = Booking::create(['business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'INTEG-'.uniqid(), 'status' => 'COMPLETED', 'appointment_start_at' => now()->subDay(), 'appointment_end_at' => now()->subDay()->addHour(), 'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000]);
        $photo = $this->post("/api/v1/reviews/booking/{$booking->id}/photos", ['file' => UploadedFile::fake()->createWithContent('x.png', $this->png())], ['Accept' => 'application/json'])->assertCreated()->json();

        return [$photo, MediaFile::findOrFail($photo['mediaId'])];
    }

    /** A result photo uploaded by the business owner. @return array{0:int,1:MediaFile} */
    private function resultPhoto(): array
    {
        $this->actor('owner');
        $offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
        $id = (int) $this->post('/api/v1/media/result-photos/upload', ['purpose' => 'RESULT', 'businessId' => $offering->business_id, 'branchId' => $offering->branch_id,
            'file' => UploadedFile::fake()->createWithContent('result.png', $this->png())], ['Accept' => 'application/json'])->assertCreated()->json('id');

        return [$id, MediaFile::findOrFail($id)];
    }

    private function replace(MediaFile $media, string $bytes): void
    {
        Storage::disk($media->disk)->put($media->path, $bytes);
    }

    public function test_a_new_review_photo_records_its_size_and_hash_and_is_served_while_the_object_is_intact(): void
    {
        [$photo, $media] = $this->reviewPhoto();
        $this->assertSame([strlen($this->png()), hash('sha256', $this->png())], [(int) $media->file_size, $media->sha256]);

        $response = $this->get($photo['contentUrl'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->png(), $response->getContent());
    }

    public function test_a_review_photo_whose_object_was_replaced_is_never_served(): void
    {
        [$photo, $media] = $this->reviewPhoto();
        $same = $this->png();
        $same[strlen($same) - 1] = $same[strlen($same) - 1] === "\x00" ? "\x01" : "\x00";   // one byte different, same length
        $this->replace($media, $same);
        $this->get($photo['contentUrl'])->assertNotFound();

        $this->replace($media, $this->png().str_repeat('A', 64));                                // longer object
        $this->get($photo['contentUrl'])->assertNotFound();

        $this->replace($media, $this->png());                                                      // the real bytes are back: served again
        $this->get($photo['contentUrl'])->assertOk();
        Storage::disk($media->disk)->delete($media->path);                                       // gone altogether
        $this->get($photo['contentUrl'])->assertNotFound();
    }

    public function test_a_review_photo_recorded_before_hashes_were_kept_is_still_read_on_its_size(): void
    {
        [$photo, $media] = $this->reviewPhoto();
        MediaFile::whereKey($media->id)->update(['sha256' => null]);

        $this->get($photo['contentUrl'])->assertOk();
        $this->replace($media, $this->png().'x');                                                  // size no longer agrees
        $this->get($photo['contentUrl'])->assertNotFound();
    }

    public function test_the_current_permission_still_decides_who_sees_a_verified_photo(): void
    {
        [$photo] = $this->reviewPhoto();
        $this->get($photo['contentUrl'])->assertOk();
        $this->actor('owner');
        $this->get($photo['contentUrl'])->assertNotFound();
        $this->actor('owner2');
        $this->get($photo['contentUrl'])->assertNotFound();
    }

    public function test_a_result_photo_is_verified_the_same_way(): void
    {
        [$id, $media] = $this->resultPhoto();
        $this->assertSame([strlen($this->png()), hash('sha256', $this->png())], [(int) $media->file_size, $media->sha256]);
        $this->get("/api/v1/media/{$id}/content")->assertOk()->assertHeader('Content-Type', 'image/png');

        $same = $this->png();
        $same[strlen($same) - 1] = $same[strlen($same) - 1] === "\x00" ? "\x01" : "\x00";
        $this->replace($media, $same);
        $this->get("/api/v1/media/{$id}/content")->assertNotFound();

        MediaFile::whereKey($id)->update(['sha256' => null]);
        $this->replace($media, $this->png());
        $this->get("/api/v1/media/{$id}/content")->assertOk();
    }

    public function test_a_consent_proof_document_is_verified_the_same_way(): void
    {
        $this->actor('owner');
        $offering = BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))->orderBy('id')->firstOrFail();
        $pdf = "%PDF-1.4\n%%EOF\n";
        $id = (int) $this->post('/api/v1/media/result-photos/upload', ['purpose' => 'CONSENT_PROOF', 'businessId' => $offering->business_id, 'branchId' => $offering->branch_id,
            'file' => UploadedFile::fake()->createWithContent('proof.pdf', $pdf)], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $media = MediaFile::findOrFail($id);
        $this->assertSame(hash('sha256', $pdf), $media->sha256);
        $this->get("/api/v1/media/{$id}/content")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->replace($media, str_replace('1.4', '1.5', $pdf));
        $this->get("/api/v1/media/{$id}/content")->assertNotFound();
    }
}
