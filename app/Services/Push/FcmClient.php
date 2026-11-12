<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Sends one FCM HTTP v1 message. The OAuth token comes from a service-account JWT, never from the repository. */
class FcmClient
{
    private function accessToken(): string
    {
        $file = config('push.service_account_file');
        if (! $file || ! is_readable($file)) throw new FcmFailure('FCM_CREDENTIALS', false);

        return Cache::remember('fcm:access-token', 3000, function () use ($file) {
            $account = json_decode((string) file_get_contents($file), true);
            if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])) throw new FcmFailure('FCM_CREDENTIALS', false);
            $b64 = fn (string $v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
            $now = time();
            $unsigned = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64(json_encode(['iss' => $account['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => config('push.token_url'), 'iat' => $now, 'exp' => $now + 3600]));
            if (! openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) throw new FcmFailure('FCM_CREDENTIALS', false);
            try {
                $response = Http::asForm()->timeout(10)->post(config('push.token_url'), ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$b64($signature)]);
            } catch (\Throwable) {
                throw new FcmFailure('FCM_NETWORK', true);
            }
            if (! $response->ok() || ! is_string($response->json('access_token'))) throw new FcmFailure('FCM_CREDENTIALS', true);

            return $response->json('access_token');
        });
    }

    /** @param array{token:string,notificationId:int|string,userId:int|string,title:string,body:?string,ttlSeconds:int,type:string,sessionId?:string} $input */
    public function send(array $input): void
    {
        $project = config('push.project_id');
        if (! is_string($project) || ! preg_match('/^[a-z][a-z0-9-]{4,61}[a-z0-9]$/', $project)) throw new FcmFailure('FCM_NOT_CONFIGURED', false);
        $identity = ['notificationId' => (string) $input['notificationId'], 'userId' => (string) $input['userId'], 'sessionId' => (string) ($input['sessionId'] ?? '')];
        // push-policy.ts pushMessage: the app can tell which session a push was queued for; Expo reads custom content from data.body.
        $data = $identity + ['body' => json_encode($identity), 'title' => $input['title'], 'message' => (string) $input['body'], 'type' => $input['type'], 'channelId' => config('push.channel')];
        $message = ['message' => ['token' => $input['token'], 'notification' => ['title' => $input['title'], 'body' => (string) $input['body']], 'data' => $data,
            'android' => ['priority' => 'HIGH', 'ttl' => max(0, min(86400, $input['ttlSeconds'])).'s', 'notification' => ['channel_id' => config('push.channel'), 'tag' => (string) $input['notificationId'], 'sound' => 'default']]]];
        try {
            $response = Http::withToken($this->accessToken())->timeout(10)->acceptJson()->post(sprintf(config('push.send_url'), $project), $message);
        } catch (FcmFailure $e) {
            throw $e;
        } catch (\Throwable) {
            throw new FcmFailure('FCM_NETWORK', true);
        }
        if (! $response->successful()) throw FcmFailure::fromResponse($response->status(), $response->json(), $response->header('Retry-After') ?: null);
    }
}
