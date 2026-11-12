<?php

namespace App\Services\Identity;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/** Accept imported GlowMe hashes; Laravel rehashes with its current algorithm on successful login. */
class GlowMeUserProvider extends EloquentUserProvider
{
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials)
    {
        $hash = $user->getAuthPassword();
        if (! is_string($hash) || ! str_starts_with($hash, 'scrypt$')) {
            return parent::validateCredentials($user, $credentials);
        }
        $password = $credentials['password'] ?? null;
        if (! is_string($password) || ! preg_match('/^scrypt\$32768\$8\$1\$([a-f0-9]{32})\$([a-f0-9]{128})$/D', $hash, $matches)) {
            return false;
        }
        if (! function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
            return false;
        }
        // Node uses the 32-character hex salt as UTF-8, not decoded bytes.
        $derived = sodium_crypto_pwhash_scryptsalsa208sha256(64, $password, $matches[1], 1048576, 33554432);

        return hash_equals($matches[2], bin2hex($derived));
    }
}
