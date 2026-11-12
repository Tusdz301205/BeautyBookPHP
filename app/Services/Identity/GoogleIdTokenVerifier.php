<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a Google Identity Services ID token the way google.service.ts does: RS256 signature against Google's
 * published keys, issuer, audience, expiry, a nonce bound to this browser session, and a verified email.
 */
class GoogleIdTokenVerifier
{
    public function clientId(): ?string
    {
        $id = trim((string) config('services.google.client_id'));

        return preg_match('/^[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com$/', $id) ? $id : null;
    }

    private function b64(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function keys(bool $refresh = false): array
    {
        if ($refresh) Cache::forget('google:jwks');

        return Cache::remember('google:jwks', 3600, function () {
            $response = Http::timeout(10)->acceptJson()->get(config('services.google.jwks_url'));
            abort_unless($response->ok() && is_array($response->json('keys')), 503, 'Không tải được khóa xác minh Google.');

            return collect($response->json('keys'))->filter(fn ($k) => ($k['kty'] ?? null) === 'RSA' && isset($k['kid'], $k['n'], $k['e']))->keyBy('kid')->all();
        });
    }

    private static function der(int $tag, string $body): string
    {
        $length = strlen($body);
        $encoded = $length < 128 ? chr($length) : (function () use ($length) { $bytes = ltrim(pack('N', $length), "\0"); return chr(0x80 | strlen($bytes)).$bytes; })();

        return chr($tag).$encoded.$body;
    }

    private function publicKey(array $jwk)
    {
        $int = fn (string $raw) => self::der(0x02, (ord($raw[0]) & 0x80 ? "\0" : '').$raw);
        $rsa = self::der(0x30, $int($this->b64($jwk['n'])).$int($this->b64($jwk['e'])));
        $spki = self::der(0x30, self::der(0x30, self::der(0x06, hex2bin('2a864886f70d010101')).self::der(0x05, '')).self::der(0x03, "\0".$rsa));

        return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n");
    }

    /** @return array{sub:string,email:string,name:string,authoritative:bool} */
    public function verify(string $credential, ?string $nonce): array
    {
        $clientId = $this->clientId();
        abort_unless($clientId, 503, 'Đăng nhập Google chưa được cấu hình.');
        abort_unless($nonce && preg_match('/^[a-f0-9]{64}$/', $nonce), 401, 'Phiên Google đã hết hạn. Tải lại trang và thử lại.');
        $fail = fn () => abort(401, 'Không xác minh được tài khoản Google. Vui lòng thử lại.');
        $parts = explode('.', $credential);
        if (count($parts) !== 3) $fail();
        $header = json_decode($this->b64($parts[0]), true);
        $claims = json_decode($this->b64($parts[1]), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) $fail();
        $keys = $this->keys();
        if (! isset($keys[$header['kid']])) $keys = $this->keys(true);
        $key = isset($keys[$header['kid']]) ? $this->publicKey($keys[$header['kid']]) : false;
        if (! $key || openssl_verify($parts[0].'.'.$parts[1], $this->b64($parts[2]), $key, OPENSSL_ALGO_SHA256) !== 1) $fail();
        $email = strtolower((string) ($claims['email'] ?? ''));
        $audience = $claims['aud'] ?? null;
        if (! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true) || ! (is_array($audience) ? in_array($clientId, $audience, true) : $audience === $clientId)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] <= time() - 60 || (isset($claims['iat']) && $claims['iat'] > time() + 300)
            || ! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255
            || ($claims['email_verified'] ?? null) !== true || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) $fail();

        return ['sub' => $claims['sub'], 'email' => $email, 'name' => mb_substr(trim((string) ($claims['name'] ?? '')) ?: explode('@', $email)[0], 0, 120),
            // Google vouches for an address only for Gmail or a Workspace domain it hosts (hd claim).
            'authoritative' => str_ends_with($email, '@gmail.com') || ! empty($claims['hd'])];
    }
}
