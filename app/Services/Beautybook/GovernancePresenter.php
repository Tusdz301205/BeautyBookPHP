<?php

namespace App\Services\Beautybook;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GovernancePresenter
{
    public function row(object|array $row): array
    {
        $result = [];
        foreach ((array) $row as $key => $value) {
            if (in_array($key, ['payload_ciphertext', 'download_token_hash'], true)) continue;
            if (is_string($value) && in_array($key, ['metadata','reasons','impact_snapshot','settlement_agreement','snapshot','booking_snapshot'], true)) $value = json_decode($value, true);
            $name = Str::camel($key);
            if (($key === 'id' || str_ends_with($key, '_id')) && $value !== null) $value = (string) $value;
            if (is_string($value) && (str_ends_with($key, '_at') || in_array($key, ['valid_from', 'valid_to'], true)) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value)) {
                $timezone = in_array($key, ['effective_at', 'valid_from', 'valid_to'], true) ? 'UTC' : config('app.timezone');
                $value = \Carbon\CarbonImmutable::parse($value, $timezone)->utc()->toISOString();
            }
            $result[$name] = $value;
        }
        return $result;
    }

    public function version(object $row): array
    {
        $result = $this->row($row);
        $payload = json_decode(Crypt::decryptString($row->payload_ciphertext), true, 512, JSON_THROW_ON_ERROR);
        foreach ($payload as $key => $value) if ($key !== 'account_number') $result[Str::camel($key)] = $value;
        if (isset($payload['account_number'])) $result['maskedAccountNumber'] = '****'.substr($payload['account_number'], -4);
        $result['active'] = (bool) $row->is_active;
        return $result;
    }

    public function transfer(object $row): array
    {
        $result = $this->row($row);
        $business = DB::table('businesses')->where('id', $row->business_id)->first(['id','name']);
        $result['business'] = $business ? $this->row($business) : null;
        foreach (['old_owner_user_id' => 'requester', 'new_owner_user_id' => 'newOwner'] as $column => $key) {
            $user = DB::table('users')->where('id', $row->$column)->first(['id','name','email']);
            $result[$key] = $user ? ['id' => (string)$user->id,'fullName' => $user->name,'email' => $user->email] : null;
        }
        $result['requestedBy'] = (string)$row->old_owner_user_id;
        $result['newOwnerUserId'] = (string)$row->new_owner_user_id;
        $snapshot = $result['impactSnapshot'] ?? [];
        $result['impactSnapshot'] = ['futureBookings' => $snapshot['future_bookings'] ?? 0, 'pendingPayments' => $snapshot['pending_payments'] ?? 0, 'pendingRefunds' => $snapshot['pending_refunds'] ?? 0, 'branches' => array_map('strval', $snapshot['branch_ids'] ?? [])];
        foreach (['LEGAL_ENTITY' => ['legal_version_id','legalEntityVersion'], 'PAYOUT_ACCOUNT' => ['payout_version_id','payoutAccountVersion']] as $type => [$column,$key]) {
            $version = $row->$column ? DB::table('business_identity_versions')->find($row->$column) : DB::table('business_identity_versions')->where('business_id',$row->business_id)->where('type',$type)->latest('version')->first();
            $result[$key] = $version ? $this->version($version) : null;
        }
        return $result;
    }
}
