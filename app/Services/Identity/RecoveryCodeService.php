<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Email -> six-digit code -> new password (recovery.service.ts). Codes are single-use, expire in 10 minutes, allow 5 tries. */
class RecoveryCodeService
{
    private function mac(string $value): string
    {
        return hash_hmac('sha256', 'recovery:v1:'.$value, (string) config('app.key'));
    }

    /** Identical response for known and unknown addresses so the endpoint does not reveal which accounts exist. */
    public function request(string $email): array
    {
        $email = strtolower(trim($email));
        $emailHash = $this->mac('email:'.$email);
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $id = (string) Str::uuid();
        $challenge = DB::transaction(function () use ($email, $emailHash, $code, $id) {
            $now = now('UTC');
            // Serialise per address: the user row (or the previous challenge) is the lock.
            $user = User::where('email', $email)->lockForUpdate()->first();
            $recent = DB::table('password_reset_codes')->where('email_hash', $emailHash)->where('created_at', '>', $now->copy()->subSeconds(60))->orderByDesc('created_at')->lockForUpdate()->first();
            if ($recent) return ['id' => $recent->id, 'send' => false];
            abort_if(DB::table('password_reset_codes')->where('email_hash', $emailHash)->where('created_at', '>', $now->copy()->subHour())->count() >= 5, 429, 'Bạn đã yêu cầu quá nhiều mã. Vui lòng thử lại sau.');
            DB::table('password_reset_codes')->where('email_hash', $emailHash)->whereNull('consumed_at')->update(['consumed_at' => $now]);
            DB::table('password_reset_codes')->insert(['id' => $id, 'email_hash' => $emailHash, 'user_id' => $user && $user->is_active ? $user->id : null, 'code_hash' => $this->mac($id.':'.$code),
                'expires_at' => $now->copy()->addMinutes(10), 'created_at' => $now]);

            return ['id' => $id, 'send' => (bool) ($user && $user->is_active)];
        }, 3);
        if ($challenge['send']) {
            try {
                Mail::raw("Mã xác nhận đặt lại mật khẩu BeautyBook của bạn là: {$code}\n\nMã có hiệu lực trong 10 phút và chỉ dùng một lần. Không chia sẻ mã này với bất kỳ ai.\nNếu bạn không yêu cầu, hãy bỏ qua email này.",
                    fn ($mail) => $mail->to($email)->subject('BeautyBook — Mã xác nhận đặt lại mật khẩu'));
            } catch (\Throwable $e) {
                // An undelivered code is invalidated; the log carries no address, code or password.
                report(new \RuntimeException('Recovery email delivery failed ('.get_class($e).')'));
                DB::table('password_reset_codes')->where('id', $challenge['id'])->whereNull('consumed_at')->update(['consumed_at' => now('UTC')]);
            }
        }

        return ['accepted' => true, 'requestId' => $challenge['id'], 'expiresIn' => 600, 'resendAfter' => 60];
    }

    public function reset(string $id, string $code, string $newPassword): void
    {
        $ok = DB::transaction(function () use ($id, $code, $newPassword) {
            $row = DB::table('password_reset_codes')->where('id', $id)->lockForUpdate()->first();
            $now = now('UTC');
            if (! $row || $row->consumed_at || strtotime($row->expires_at.' UTC') <= $now->getTimestamp() || $row->attempts >= 5) return false;
            $user = $row->user_id ? User::whereKey($row->user_id)->lockForUpdate()->first() : null;
            $valid = hash_equals($row->code_hash, $this->mac($id.':'.$code)) && $user && $user->is_active;
            DB::table('password_reset_codes')->where('id', $id)->update(['attempts' => $row->attempts + 1] + (! $valid && $row->attempts === 4 ? ['consumed_at' => $now] : []));
            // Failures return normally so the attempt counter commits instead of rolling back.
            if (! $valid) return false;
            $user->forceFill(['password' => Hash::make($newPassword), 'remember_token' => Str::random(60)])->save();
            DB::table('password_reset_codes')->where('user_id', $user->id)->whereNull('consumed_at')->update(['consumed_at' => $now]);
            DB::table('beautybook_auth_tokens')->where('user_id', $user->id)->where('purpose', 'RESET_PASSWORD')->whereNull('consumed_at')->update(['consumed_at' => $now, 'updated_at' => $now]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('audit_logs')->insert(['user_id' => $user->id, 'action' => 'AUTH_PASSWORD_RESET', 'entity_type' => 'AccountSecurity', 'entity_id' => (string) $user->id, 'metadata' => json_encode(['method' => 'EMAIL_CODE']), 'created_at' => now()]);

            return true;
        }, 3);
        abort_unless($ok, 400, 'Mã không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu mã mới.');
    }
}
