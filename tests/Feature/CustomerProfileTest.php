<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->withSession(['_token' => 'profile-csrf'])->withHeaders(['X-CSRF-TOKEN' => 'profile-csrf']);
        Storage::fake('public');
    }

    private function customer(): User
    {
        $user = User::where('email', 'customer@beautybook.local')->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avatar.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
    }

    public function test_customer_can_update_only_self_profile_fields(): void
    {
        $user = $this->customer();
        $other = User::factory()->create(['account_kind' => 'CUSTOMER']);
        $this->patchJson(route('profile.update'), ['name' => 'Khách mới', 'phone' => '0901234567', 'date_of_birth' => '2000-03-02', 'address' => 'Bình Tân', 'user_id' => $other->id, 'account_kind' => 'OPERATIONAL'])
            ->assertOk()->assertJsonPath('name', 'Khách mới')->assertJsonPath('date_of_birth', '2000-03-02');
        $this->assertDatabaseHas('customer_profiles', ['user_id' => $user->id, 'phone' => '0901234567', 'address' => 'Bình Tân']);
        $this->assertSame('CUSTOMER', $user->fresh()->account_kind->value);
        $this->assertNotSame('Khách mới', $other->fresh()->name);
        $this->get(route('profile.show'))->assertOk()->assertSee('Hồ sơ cá nhân');
    }

    public function test_operational_user_and_unverified_customer_cannot_use_customer_profile(): void
    {
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $this->actingAs($owner)->getJson(route('profile.show'))->assertForbidden();
        $unverified = User::factory()->unverified()->create(['account_kind' => 'CUSTOMER']);
        $this->actingAs($unverified)->getJson(route('profile.show'))->assertForbidden();
    }

    public function test_profile_validation_rejects_future_birth_date_and_foreign_avatar(): void
    {
        $user = $this->customer();
        $this->patchJson(route('profile.update'), ['date_of_birth' => now()->addDay()->toDateString(), 'phone' => '<script>'])->assertUnprocessable();
        $foreign = MediaFile::create(['disk' => 'public', 'path' => 'foreign.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => User::factory()->create(['account_kind' => 'CUSTOMER'])->id, 'entity_type' => 'CUSTOMER_AVATAR', 'entity_id' => 999999, 'visibility' => 'PUBLIC']);
        $this->patchJson(route('profile.update'), ['avatar_media_file_id' => $foreign->id])->assertUnprocessable();
        $this->assertNull($user->fresh()->avatar_media_file_id);
    }

    public function test_real_avatar_upload_has_self_ownership_and_public_read_only_while_referenced(): void
    {
        $user = $this->customer();
        $response = $this->postJson(route('profile.avatar.upload'), ['avatar' => $this->image()])->assertCreated();
        $media = MediaFile::findOrFail($response->json('avatar_media_file_id'));
        $this->assertSame($user->id, (int) $media->uploaded_by);
        $this->assertSame('CUSTOMER_AVATAR', $media->entity_type);
        $this->assertNull($media->business_id);
        Storage::disk('public')->assertExists($media->path);
        $this->get(route('profile.avatar', $media))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->postJson(route('profile.avatar.upload'), ['avatar' => $this->image()])->assertCreated();
        $this->get(route('profile.avatar', $media))->assertNotFound();
    }

    public function test_avatar_rejects_executable_or_svg_and_private_media_cannot_be_read_as_avatar(): void
    {
        $user = $this->customer();
        $this->postJson(route('profile.avatar.upload'), ['avatar' => UploadedFile::fake()->createWithContent('attack.png', '<?php echo 1;')])->assertUnprocessable();
        $this->postJson(route('profile.avatar.upload'), ['avatar' => UploadedFile::fake()->createWithContent('attack.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')])->assertUnprocessable();
        $private = MediaFile::create(['disk' => 'local', 'path' => 'private.png', 'mime_type' => 'image/png', 'file_size' => 1, 'uploaded_by' => $user->id, 'entity_type' => 'CUSTOMER_AVATAR', 'entity_id' => $user->id, 'visibility' => 'PRIVATE']);
        $user->forceFill(['avatar_media_file_id' => $private->id])->save();
        $this->get(route('profile.avatar', $private))->assertNotFound();
    }
}
