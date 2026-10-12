<?php

namespace Tests\Feature;

use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\MediaFile;
use App\Models\User;
use App\Services\Beautybook\ManagementPresenter;
use App\Services\Media\MediaManagementService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->seed();
    }

    public function test_owner_upload_route_persists_public_media_with_server_derived_scope(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->firstOrFail();

        $this->actingAs($owner)
            ->postWithCsrf(route('owner.media.store', $business), [
                'file' => UploadedFile::fake()->createWithContent('service.png', $this->png()),
                'entity_type' => 'SERVICE',
                'entity_id' => $offering->id,
                'visibility' => 'PUBLIC',
            ])
            ->assertRedirect();

        $media = MediaFile::where('entity_type', 'SERVICE')->where('entity_id', $offering->id)->latest('id')->firstOrFail();
        $this->assertSame('public', $media->disk);
        $this->assertSame($business->id, $media->business_id);
        $this->assertSame($offering->branch_id, $media->branch_id);
        $this->assertTrue(app(MediaManagementService::class)->canRead(null, $media));
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_private_media_is_readable_only_by_owner_of_the_scoped_business(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $otherOwner = User::where('email', 'owner2@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->firstOrFail();
        $media = app(MediaManagementService::class)->upload(
            $owner,
            $business,
            UploadedFile::fake()->createWithContent('private.png', $this->png()),
            'SERVICE',
            $offering->id,
            'PRIVATE',
        );

        $access = app(MediaManagementService::class);
        $this->assertFalse($access->canRead(null, $media));
        $this->assertTrue($access->canRead($owner, $media));
        $this->assertFalse($access->canRead($otherOwner, $media));
        $this->actingAs($owner)->get(route('media.show', $media))->assertOk();
    }

    public function test_public_media_presenter_url_serves_anonymous_images_without_storage_symlink(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->firstOrFail();
        $media = app(MediaManagementService::class)->upload(
            $owner,
            $business,
            UploadedFile::fake()->createWithContent('service.png', $this->png()),
            'SERVICE',
            $offering->id,
            'PUBLIC',
        );

        $url = app(ManagementPresenter::class)->media($media)['url'];
        $this->assertSame('/media/'.$media->id, $url);
        $response = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control');
        // HTTP directive order has no meaning; Symfony may normalize it.
        $this->assertEqualsCanonicalizing(['public', 'max-age=3600'], array_map('trim', explode(',', $response->headers->get('Cache-Control'))));
    }

    public function test_private_media_access_expires_with_business_owner_assignment(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->firstOrFail();
        $media = app(MediaManagementService::class)->upload(
            $owner,
            $business,
            UploadedFile::fake()->createWithContent('private.png', $this->png()),
            'SERVICE',
            $offering->id,
            'PRIVATE',
        );

        $this->assertNull(app(ManagementPresenter::class)->media($media)['url']);
        $this->get(route('media.show', $media))->assertForbidden();
        $response = $this->actingAs($owner)->get(route('media.show', $media))
            ->assertOk()->assertHeader('Cache-Control');
        $this->assertEqualsCanonicalizing(['private', 'no-store'], array_map('trim', explode(',', $response->headers->get('Cache-Control'))));

        $owner->roleAssignments()->where('role_name', 'BUSINESS_OWNER')
            ->where('business_id', $business->id)->update(['expires_at' => now('UTC')->subMinute()]);
        $this->actingAs($owner)->get(route('media.show', $media))->assertForbidden();
    }

    public function test_upload_rejects_cross_tenant_entity_scope(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $foreignOffering = BranchServiceOffering::whereHas('service.business', fn ($query) => $query->where('slug', 'glow-studio'))->firstOrFail();

        $this->actingAs($owner)
            ->from(route('owner.catalog.index', $business))
            ->postWithCsrf(route('owner.media.store', $business), [
                'file' => UploadedFile::fake()->createWithContent('cross.png', $this->png()),
                'entity_type' => 'SERVICE',
                'entity_id' => $foreignOffering->id,
                'visibility' => 'PUBLIC',
            ])
            ->assertRedirect(route('owner.catalog.index', $business))
            ->assertSessionHasErrors('media');
    }

    public function test_upload_validates_content_extension_and_size(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $business = Business::where('slug', 'beautybook-demo')->firstOrFail();
        $offering = BranchServiceOffering::where('business_id', $business->id)->firstOrFail();
        $media = app(MediaManagementService::class);
        $cases = [
            UploadedFile::fake()->createWithContent('fake.png', 'plain text'),
            UploadedFile::fake()->createWithContent('wrong.jpg', $this->png()),
            UploadedFile::fake()->createWithContent('empty.png', ''),
            UploadedFile::fake()->createWithContent('large.png', str_repeat('x', (10 * 1024 * 1024) + 1)),
        ];

        foreach ($cases as $file) {
            try {
                $media->upload($owner, $business, $file, 'SERVICE', $offering->id, 'PUBLIC');
                $this->fail('Invalid media file was accepted: '.$file->getClientOriginalName());
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, MediaFile::count());
    }

    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        $token = 'beautybook-test-csrf-token';

        return $this->withSession(['_token' => $token])->post($uri, ['_token' => $token, ...$data]);
    }

    private function png(): string
    {
        return pack('H*', '89504e470d0a1a0a0000000d4948445200000001000000010804000000b51c0c020000000b4944415478da63fcff1f0002eb01f569769cac0000000049454e44ae426082');
    }
}
