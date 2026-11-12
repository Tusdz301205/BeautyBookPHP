<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GlowMePasswordImportTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'scrypt$32768$8$1$0123456789abcdef0123456789abcdef$4752ac7019a52f11e3e6172eb2a028a060bbcb6a723ab2ab3bc20d5e9b320c8d0534899914a90e5e81f2ec8af900aee846314d203a7bedd68e135fbc530f691d';

    public function test_node_scrypt_password_logs_in_and_is_rehashed_without_changing_password(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => self::HASH]);
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'wrong-password']));
        $this->assertSame(self::HASH, $user->fresh()->getAuthPassword());
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'GlowMeTest123!']));
        $this->assertTrue(Hash::check('GlowMeTest123!', $user->fresh()->getAuthPassword()));
        $this->assertFalse(str_starts_with($user->fresh()->getAuthPassword(), 'scrypt$'));
    }

    public function test_invalid_or_expensive_imported_hash_parameters_are_rejected(): void
    {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => str_replace('$32768$', '$999999999$', self::HASH)]);
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'GlowMeTest123!']));
    }
}
