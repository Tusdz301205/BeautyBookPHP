<?php

namespace App\Services\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class PlatformSettingsService
{
    public const DEFAULTS = [
        'maxAdvanceBookingDays' => 30, 'minBookingLeadTimeHours' => 2, 'freeCancellationHours' => 4,
        'allowRescheduleRequests' => true, 'maxRescheduleCountPerBooking' => 2, 'pendingHoldMinutes' => 30,
        'appointmentReminderBeforeHours' => 2, 'reviewReminderAfterHours' => 24,
        'emailEnabled' => false, 'smsEnabled' => false, 'pushEnabled' => false,
        'autoApproveNewSalons' => false, 'requirePhoneVerification' => false, 'requireIdVerification' => true,
        'maxBranchesPerBusiness' => 10, 'reviewMinLength' => 0, 'allowAnonymousReview' => false,
        'autoHideReviewReportThreshold' => 3, 'violationSuspendThreshold' => 5,
    ];

    public function get(): array
    {
        $raw = DB::table('platform_settings')->where('key', 'platform')->value('value');
        return array_replace(self::DEFAULTS, $raw ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : []);
    }

    public function value(string $key): mixed
    {
        return $this->get()[$key] ?? null;
    }

    public function update(array $input, int $actor): array
    {
        $ranges = [
            'maxAdvanceBookingDays' => [1, 365], 'minBookingLeadTimeHours' => [0, 168],
            'freeCancellationHours' => [4, 4], 'maxRescheduleCountPerBooking' => [0, 10],
            'pendingHoldMinutes' => [5, 1440], 'appointmentReminderBeforeHours' => [0, 168],
            'reviewReminderAfterHours' => [0, 720], 'maxBranchesPerBusiness' => [1, 1000],
            'reviewMinLength' => [0, 500], 'autoHideReviewReportThreshold' => [1, 100], 'violationSuspendThreshold' => [1, 100],
        ];
        $rules = ['settings' => ['required', 'array:'.implode(',', array_keys(self::DEFAULTS))]];
        foreach (self::DEFAULTS as $key => $default) {
            $rules['settings.'.$key] = isset($ranges[$key])
                ? ['sometimes', 'integer', 'between:'.implode(',', $ranges[$key])]
                : ['sometimes', 'boolean'];
        }
        $data = Validator::make(['settings' => $input], $rules)->validate()['settings'];
        foreach ($data as $key => $value) $data[$key] = is_bool(self::DEFAULTS[$key]) ? (bool) $value : (int) $value;

        return DB::transaction(function () use ($data, $actor) {
            DB::table('platform_settings')->insertOrIgnore(['key' => 'platform', 'value' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('platform_settings')->where('key', 'platform')->lockForUpdate()->first();
            $settings = array_replace(self::DEFAULTS, json_decode($row->value, true), $data);
            DB::table('platform_settings')->where('key', 'platform')->update([
                'value' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_by_user_id' => $actor, 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert(['user_id' => $actor, 'action' => 'UPDATE', 'entity_type' => 'PlatformSetting', 'entity_id' => 'platform', 'metadata' => json_encode(['changed_keys' => array_keys($data)], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            return $settings;
        }, 3);
    }
}
