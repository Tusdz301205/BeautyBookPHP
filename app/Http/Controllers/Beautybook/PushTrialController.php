<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Push\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TRIAL ONLY. A protocol-faithful stand-in for Google's OAuth token endpoint and FCM HTTP v1 `messages:send`, so a
 * preview or test browser can register, receive and remove push without a Firebase project. It exists only when
 * PUSH_TRIAL_SINK=true in a local or testing environment (routes are not registered otherwise). The real
 * FcmClient is unchanged: it signs a service-account JWT, posts to these URLs and handles their errors.
 */
class PushTrialController extends Controller
{
    private function inboxKey(string $tokenHash): string
    {
        return 'push-trial:inbox:'.$tokenHash;
    }

    /** POST /push-trial/oauth/token — verifies the RS256 service-account assertion like Google's endpoint would. */
    public function token(Request $request): JsonResponse
    {
        if ($request->input('grant_type') !== 'urn:ietf:params:oauth:grant-type:jwt-bearer') return response()->json(['error' => 'unsupported_grant_type'], 400);
        $parts = explode('.', (string) $request->input('assertion'));
        $decode = fn (string $v) => base64_decode(strtr($v, '-_', '+/'), true);
        if (count($parts) !== 3 || ! ($header = json_decode((string) $decode($parts[0]), true)) || ! ($claims = json_decode((string) $decode($parts[1]), true)) || ! ($signature = $decode($parts[2]))) {
            return response()->json(['error' => 'invalid_grant'], 400);
        }
        $file = config('push.service_account_file');
        $account = $file && is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        $key = is_array($account) && ! empty($account['private_key']) ? openssl_pkey_get_private($account['private_key']) : false;
        $public = $key ? (openssl_pkey_get_details($key)['key'] ?? null) : null;
        $valid = $public && ($header['alg'] ?? null) === 'RS256' && ($claims['iss'] ?? null) === ($account['client_email'] ?? '')
            && ($claims['scope'] ?? null) === 'https://www.googleapis.com/auth/firebase.messaging' && ($claims['aud'] ?? null) === config('push.token_url')
            && ($claims['exp'] ?? 0) > time() && openssl_verify($parts[0].'.'.$parts[1], $signature, $public, OPENSSL_ALGO_SHA256) === 1;
        if (! $valid) return response()->json(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT assertion.'], 400);
        $access = 'trial-'.Str::random(40);
        Cache::put('push-trial:access:'.hash('sha256', $access), true, 3600);

        return response()->json(['access_token' => $access, 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }

    /** POST /push-trial/projects/{project}/messages:send */
    public function send(Request $request, string $project): JsonResponse
    {
        $bearer = (string) $request->bearerToken();
        if ($bearer === '' || ! Cache::has('push-trial:access:'.hash('sha256', $bearer))) return response()->json(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401);
        if ($project !== config('push.project_id')) return response()->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404);
        $message = $request->input('message');
        $token = is_array($message) ? ($message['token'] ?? null) : null;
        if (! is_string($token) || $token === '' || ! is_array($message['notification'] ?? null)) return response()->json(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT']], 400);
        $hash = hash('sha256', $token);
        $device = DB::table('beautybook_device_tokens')->where('token_hash', $hash)->where('active', true)->first();
        if (! $device) {
            return response()->json(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.',
                'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404);
        }
        $name = 'projects/'.$project.'/messages/'.Str::uuid();
        Cache::lock('push-trial:lock:'.$hash, 5)->block(3, function () use ($hash, $message, $name) {
            $items = Cache::get($this->inboxKey($hash), []);
            $items[] = ['id' => $name, 'title' => (string) $message['notification']['title'], 'body' => (string) ($message['notification']['body'] ?? ''), 'data' => (array) ($message['data'] ?? []), 'receivedAt' => now('UTC')->toISOString()];
            Cache::put($this->inboxKey($hash), array_slice($items, -50), 3600);
        });

        return response()->json(['name' => $name]);
    }

    /** GET /notifications/push-config — what this build can do for the signed-in browser. */
    /** What the browser may do: 'trial' (preview stand-in), 'fcm' (real Firebase, only with every public web setting) or 'off'. */
    public function config(): array
    {
        $on = PushService::enabled();
        $trial = $on && PushService::trialSink();
        $web = $on && ! $trial ? PushService::webConfig() : null;

        return ['enabled' => $trial || $web !== null, 'mode' => $trial ? 'trial' : ($web !== null ? 'fcm' : 'off'),
            'reason' => ! $on ? 'PUSH_DISABLED' : ($trial || $web !== null ? null : 'BROWSER_FCM_NOT_CONFIGURED')] + ($web !== null ? ['firebase' => $web] : []);
    }

    /** GET /notifications/push-trial/messages?token= — the trial browser collects what the sink received for its own device. */
    public function messages(Request $request): array
    {
        abort_unless(PushService::trialSink(), 404);
        $token = (string) $request->query('token');
        $hash = hash('sha256', $token);
        abort_unless($token !== '' && DB::table('beautybook_device_tokens')->where('token_hash', $hash)->where('user_id', $request->user()->id)->where('active', true)->exists(), 404);
        $items = Cache::lock('push-trial:lock:'.$hash, 5)->block(3, function () use ($hash) {
            $items = Cache::get($this->inboxKey($hash), []);
            Cache::forget($this->inboxKey($hash));

            return $items;
        });

        return ['items' => $items];
    }
}
