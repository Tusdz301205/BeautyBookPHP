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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ResultPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTE = '/api/v1/media/result-photos';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('local');
    }

    public function test_only_the_scoped_owner_can_manage_result_media_and_wrong_branch_files_are_rejected(): void
    {
        $offering = $this->offering();
        $this->actor('owner');
        $file = $this->upload($offering)->assertCreated()->json('id');
        $proof = $this->upload($offering, 'CONSENT_PROOF')->assertCreated()->json('id');
        $photo = $this->createExternal($offering, $file, $proof);
        $this->get('/media/'.$file)->assertNotFound();
        $this->get('/media/'.$proof)->assertNotFound();
        $this->get("/api/v1/media/{$file}/content")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get("/api/v1/media/{$proof}/content")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->deleteJson('/api/v1/media/'.$proof)->assertNotFound();

        foreach (['owner2', 'receptionist', 'admin', 'customer'] as $actor) {
            $this->actor($actor);
            $this->upload($offering)->assertForbidden();
            $this->getJson(self::ROUTE.'/manage/'.$offering->id)->assertForbidden();
            $this->get("/api/v1/media/{$proof}/content")->assertForbidden();
            $this->capture($photo)->assertForbidden();
        }

        $this->actor('owner');
        $sibling = Branch::where('business_id', $offering->business_id)->whereKeyNot($offering->branch_id)->firstOrFail();
        $wrongBranchFile = $this->upload($offering, branchId: $sibling->id)->assertCreated()->json('id');
        $this->postJson(self::ROUTE, [
            'serviceId' => $offering->id, 'mediaId' => $wrongBranchFile, 'consentSubjectReference' => 'Fixture subject', 'proofMediaId' => $proof,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('result_photos', 1);
    }

    public function test_booking_provenance_derives_the_customer_and_checks_the_exact_purchased_item(): void
    {
        $offering = $this->offering();
        $other = $this->offering($offering->id);
        $customer = $this->actor('customer');
        $start = now('UTC')->addDay();
        $end = $start->copy()->addHour();
        $booking = Booking::create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id,
            'customer_profile_id' => $customer->customerProfile->id, 'created_by_user_id' => $customer->id,
            'booking_code' => 'RESULT-BOOKING-FIXTURE', 'status' => 'CONFIRMED',
            'appointment_start_at' => $start, 'appointment_end_at' => $end,
            'source' => 'ONLINE_WEB', 'currency' => 'VND', 'total_amount' => 100000,
        ]);
        $item = $booking->services()->create([
            'business_id' => $offering->business_id, 'branch_id' => $offering->branch_id,
            'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
            'price_at_booking' => 100000, 'currency' => 'VND', 'duration_minutes' => 60, 'service_name_snapshot' => 'Fixture service',
            'item_start_at' => $start, 'item_end_at' => $end, 'occupied_start_at' => $start, 'occupied_end_at' => $end,
        ]);

        $this->actor('owner');
        $file = $this->upload($offering)->assertCreated()->json('id');
        $body = ['mediaId' => $file, 'bookingId' => $booking->id, 'bookingServiceId' => $item->id];
        $this->postJson(self::ROUTE, $body + ['serviceId' => $other->id])->assertUnprocessable();
        $this->postJson(self::ROUTE, [...$body, 'serviceId' => $offering->id, 'bookingServiceId' => $item->id + 999])->assertUnprocessable();
        $this->postJson(self::ROUTE, $body + ['serviceId' => $offering->id, 'consentSubjectId' => $customer->customerProfile->id])->assertUnprocessable();
        $photo = $this->postJson(self::ROUTE, $body + ['serviceId' => $offering->id])->assertCreated()
            ->assertJsonPath('consentSubjectId', (string) $customer->customerProfile->id)
            ->assertJsonPath('bookingServiceId', (string) $item->id)->assertJsonPath('proofMediaId', null)->json();
        $this->capture($photo)->assertOk();
    }

    public function test_external_provenance_requires_typed_private_evidence_in_the_same_scope(): void
    {
        $offering = $this->offering();
        $sibling = Branch::where('business_id', $offering->business_id)->whereKeyNot($offering->branch_id)->firstOrFail();
        $this->actor('owner');
        $file = $this->upload($offering)->assertCreated()->json('id');
        $body = ['serviceId' => $offering->id, 'mediaId' => $file, 'consentSubjectReference' => 'Fixture subject'];
        $this->postJson(self::ROUTE, $body)->assertUnprocessable();
        $this->postJson(self::ROUTE, $body + ['proofMediaId' => $file])->assertUnprocessable();
        $wrongProof = $this->upload($offering, 'CONSENT_PROOF', $sibling->id)->assertCreated()->json('id');
        $this->postJson(self::ROUTE, $body + ['proofMediaId' => $wrongProof])->assertUnprocessable();
        $proof = $this->upload($offering, 'CONSENT_PROOF')->assertCreated()->json('id');
        $photo = $this->createExternal($offering, $file, $proof);
        $this->capture($photo, ['capturedAt' => now('UTC')->addDay()->toIso8601String()])->assertUnprocessable();
        $this->capture($photo, ['capturedAt' => '2026-02-31T09:00:00+07:00'])->assertUnprocessable();
        $captured = $this->capture($photo)->assertOk()->assertJsonPath('visibility', 'PRIVATE')->json();
        $this->capture($photo)->assertConflict();
        Storage::disk('local')->delete(MediaFile::findOrFail($proof)->path);
        $this->publish($file, [$captured])->assertUnprocessable();
        $this->get('/media/'.$file)->assertNotFound();
    }

    public function test_shared_file_requires_all_live_consents_and_withdrawal_denies_public_gallery_and_direct_bytes(): void
    {
        $offering = $this->offering();
        $other = $this->offering($offering->id);
        $this->actor('owner');
        $file = $this->upload($offering)->assertCreated()->json('id');
        $proof = $this->upload($offering, 'CONSENT_PROOF')->assertCreated()->json('id');
        $first = $this->createExternal($offering, $file, $proof);
        $second = $this->createExternal($other, $file, $proof);
        $this->getJson(self::ROUTE.'/file/'.$file.'/uses')->assertOk()->assertJsonCount(2, 'photos');
        $firstCaptured = $this->capture($first, ['version' => '0'])->assertOk()->json();
        $this->publish($file, [$firstCaptured])->assertConflict();
        $this->publish($file, [$firstCaptured, $second])->assertConflict();
        $secondCaptured = $this->capture($second)->assertOk()->json();
        $this->publish($file, [$first, $secondCaptured])->assertConflict();
        $this->publish($file, [$firstCaptured, $secondCaptured])->assertOk()->assertJsonCount(2, 'photos');
        $this->assertDatabaseHas('media_files', ['id' => $file, 'visibility' => 'PRIVATE', 'disk' => 'local']);

        $url = '/media/'.$file;
        foreach ([$offering, $other] as $service) {
            $row = $this->getJson(self::ROUTE.'/public/'.$service->id)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.url', $url)->json('data.0');
            $this->assertSame(['id', 'url'], array_keys($row));
        }
        $served = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $served->headers->get('Cache-Control'));
        $this->assertSame($this->png(), $served->getContent());
        $this->get('/media/'.$proof)->assertNotFound();

        $other->update(['bookable' => false]);
        $this->get($url)->assertNotFound();
        $this->getJson(self::ROUTE.'/public/'.$offering->id)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::ROUTE.'/public/'.$other->id)->assertNotFound();
        $other->update(['bookable' => true]);
        $this->get($url)->assertOk();
        $withdrawal = self::ROUTE.'/'.$first['id'].'/withdraw';
        $this->postJson($withdrawal, ['expectedVersion' => $firstCaptured['version']])->assertConflict();
        $this->postJson($withdrawal, ['expectedVersion' => $firstCaptured['version'] + 1])
            ->assertOk()->assertJsonPath('revoked', true);
        $this->get($url)->assertNotFound();
        foreach ([$offering, $other] as $service) {
            $this->getJson(self::ROUTE.'/public/'.$service->id)->assertOk()->assertJsonCount(0, 'data');
        }
        $uses = $this->getJson(self::ROUTE.'/file/'.$file.'/uses')->assertOk()->json('photos');
        $this->publish($file, $uses)->assertConflict();
        $this->get("/api/v1/media/{$file}/content")->assertOk();
    }

    private function actor(string $name): User
    {
        $user = User::where('email', $name.'@beautybook.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'workspace_assignment_id' => $user->roleAssignments()->firstOrFail()->id,
            'beautybook_access_hash' => hash('sha256', 'result-photo-fixture'),
            'beautybook_access_expires' => time() + 900,
        ])->withHeader('Authorization', 'Bearer result-photo-fixture');

        return $user;
    }

    private function offering(?int $except = null): BranchServiceOffering
    {
        return BranchServiceOffering::publiclyVisible()->where('branch_id', Branch::where('slug', 'tan-tao')->value('id'))
            ->when($except, fn ($query) => $query->whereKeyNot($except))->orderBy('id')->firstOrFail();
    }

    private function upload(BranchServiceOffering $offering, string $purpose = 'RESULT', ?int $branchId = null): TestResponse
    {
        $file = $purpose === 'RESULT'
            ? UploadedFile::fake()->createWithContent('result.png', $this->png())
            : UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n%%EOF\n");

        return $this->post(self::ROUTE.'/upload', [
            'purpose' => $purpose, 'businessId' => $offering->business_id, 'branchId' => $branchId ?? $offering->branch_id,
            'file' => $file,
        ], ['Accept' => 'application/json']);
    }

    private function createExternal(BranchServiceOffering $offering, string $file, string $proof): array
    {
        return $this->postJson(self::ROUTE, [
            'serviceId' => $offering->id, 'mediaId' => $file, 'proofMediaId' => $proof,
            'consentSubjectReference' => 'Fixture subject private',
        ])->assertCreated()->assertJsonPath('visibility', 'PRIVATE')->assertJsonPath('version', 0)->json();
    }

    private function capture(array $photo, array $overrides = []): TestResponse
    {
        return $this->patchJson(self::ROUTE.'/'.$photo['id'].'/consent', $overrides + [
            'expectedVersion' => $photo['version'], 'capturedAt' => '2026-01-01T10:30:00+07:00',
            'source' => 'Fixture consent record', 'version' => 'v1',
        ]);
    }

    private function publish(string $file, array $photos): TestResponse
    {
        return $this->postJson(self::ROUTE.'/publish', [
            'mediaId' => $file,
            'photos' => array_map(fn ($photo) => ['id' => $photo['id'], 'expectedVersion' => $photo['version']], $photos),
        ]);
    }

    private function png(): string
    {
        return hex2bin('89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da63fcff1f0002eb01f569769cac0000000049454e44ae426082');
    }
}
