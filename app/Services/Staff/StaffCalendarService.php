<?php

namespace App\Services\Staff;

use App\Exceptions\BookingConflictException;
use App\Models\BookingService;
use App\Models\Branch;
use App\Models\Business;
use App\Models\RoleAssignment;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffCalendarService
{
    private const WARNING_LIMIT = 100;

    public function hours(StaffProfile $staff): array
    {
        $days = DB::table('staff_working_hours')->where('staff_profile_id', $staff->id)
            ->orderBy('day_of_week')->orderBy('start_minute')->get()->groupBy('day_of_week')
            ->map(fn ($rows, $day) => [
                'dayOfWeek' => (int) $day,
                'isOff' => (bool) $rows->first()->is_off,
                'intervals' => $rows->where('is_off', false)->map(fn ($row) => [
                    'startTime' => $this->clock($row->start_minute),
                    'endTime' => $this->clock($row->end_minute),
                ])->values()->all(),
            ])->values()->all();

        return ['configured' => count($days) === 7, 'days' => $days];
    }

    public function assertAvailable(int $staffId, CarbonImmutable $localStart, CarbonImmutable $localEnd, bool $lock): void
    {
        $hours = DB::table('staff_working_hours')->where('staff_profile_id', $staffId)
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        // A missing week means the staff member inherits branch hours. An explicit day off does not.
        if ($hours->isNotEmpty()) {
            $windows = $hours->where('day_of_week', $localStart->dayOfWeek)->where('is_off', false)
                ->map(fn ($row) => [(int) $row->start_minute, (int) $row->end_minute])->all();
            if (! $this->coversWindow($windows, $localStart, $localEnd)) {
                throw new DomainException('Khung giờ nằm ngoài ca làm của nhân viên.');
            }
        }

        $absence = DB::table('staff_time_offs')->where('staff_profile_id', $staffId)
            ->where('starts_at', '<', $localEnd->utc()->format('Y-m-d H:i:s.u'))
            ->where('ends_at', '>', $localStart->utc()->format('Y-m-d H:i:s.u'))
            ->when($lock, fn ($query) => $query->lockForUpdate())->first(['id']);
        if ($absence) {
            throw new DomainException('Nhân viên nghỉ trong khung giờ này.');
        }
    }

    public function replaceHours(StaffProfile $staff, RoleAssignment $grant, array $days): array
    {
        $week = [];
        foreach ($days as $day) {
            $week[(int) $day['dayOfWeek']] = $this->normalizeDay($day);
        }

        return $this->lockedWrite($staff, $grant, function (StaffProfile $current, Branch $branch) use ($week, $grant) {
            $rows = [];
            foreach ($week as $day => $windows) {
                foreach ($windows ?: [[null, null]] as [$start, $end]) {
                    $rows[] = ['staff_profile_id' => $current->id, 'day_of_week' => $day,
                        'is_off' => $start === null, 'start_minute' => $start, 'end_minute' => $end];
                }
            }
            DB::table('staff_working_hours')->where('staff_profile_id', $current->id)->delete();
            DB::table('staff_working_hours')->insert($rows);

            $timezone = $branch->timezone ?: 'Asia/Ho_Chi_Minh';
            $items = $this->activeItems($current)->where(function ($query) {
                $query->where('occupied_end_at', '>', now('UTC'))
                    ->orWhereHas('booking', fn ($booking) => $booking->whereIn('status', ['CHECKED_IN', 'IN_PROGRESS']));
            });
            $warnings = $this->warnings($items, function (BookingService $item) use ($week, $timezone) {
                $start = $item->occupied_start_at->toImmutable()->setTimezone($timezone);
                $end = $item->occupied_end_at->toImmutable()->setTimezone($timezone);

                return ! $this->coversWindow($week[$start->dayOfWeek], $start, $end);
            });
            $this->audit($current, $grant, 'STAFF_HOURS_UPDATE', 'StaffProfile', $current->id, $warnings['conflictCount']);

            return $this->hours($current) + $warnings;
        });
    }

    public function timeOff(StaffProfile $staff, array $query, bool $private): array
    {
        [$from, $to] = $this->period($query, 'from', 'to');
        $page = (int) ($query['page'] ?? 1);
        $limit = (int) ($query['limit'] ?? 20);
        $rows = DB::table('staff_time_offs')->where('staff_profile_id', $staff->id)
            ->where('starts_at', '<', $this->sqlInstant($to))->where('ends_at', '>', $this->sqlInstant($from));

        return DB::transaction(fn () => [
            'total' => $rows->count(),
            'items' => $rows->orderBy('starts_at')->orderBy('id')->forPage($page, $limit)->get()
                ->map(fn ($row) => $this->timeOffData($row, $private))->all(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    public function saveTimeOff(StaffProfile $staff, RoleAssignment $grant, array $input, ?int $offId = null): array
    {
        [$from, $to] = $this->period($input, 'startsAt', 'endsAt');
        $start = $this->sqlInstant($from);
        $end = $this->sqlInstant($to);

        return $this->lockedWrite($staff, $grant, function (StaffProfile $current) use ($grant, $input, $offId, $start, $end) {
            $rows = DB::table('staff_time_offs')->where('staff_profile_id', $current->id);
            $target = (clone $rows)->where('id', $offId);
            if ($offId !== null) {
                abort_unless($target->lockForUpdate()->first(), 404);
            }

            $overlap = (clone $rows)->when($offId !== null, fn ($query) => $query->where('id', '<>', $offId))
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
                ->lockForUpdate()->first(['id']);
            if ($overlap) {
                throw new BookingConflictException('Khoảng nghỉ chồng lấn với lịch nghỉ đã có.');
            }

            $values = ['starts_at' => $start, 'ends_at' => $end,
                'reason' => $input['reason'] ?? null, 'updated_at' => now()];
            if ($offId === null) {
                $savedId = DB::table('staff_time_offs')->insertGetId($values + [
                    'staff_profile_id' => $current->id, 'recorded_by_id' => $grant->user_id, 'created_at' => now(),
                ]);
            } else {
                $target->update($values);
                $savedId = $offId;
            }

            $warnings = $this->warnings($this->activeItems($current)
                ->where('occupied_start_at', '<', $end)->where('occupied_end_at', '>', $start));
            $action = $offId === null ? 'STAFF_TIME_OFF_CREATE' : 'STAFF_TIME_OFF_UPDATE';
            $this->audit($current, $grant, $action, 'StaffTimeOff', $savedId, $warnings['conflictCount']);

            return $this->timeOffData((clone $rows)->where('id', $savedId)->first(), true) + $warnings;
        });
    }

    public function removeTimeOff(StaffProfile $staff, RoleAssignment $grant, int $offId): array
    {
        return $this->lockedWrite($staff, $grant, function (StaffProfile $current) use ($grant, $offId) {
            $removed = DB::table('staff_time_offs')->where('staff_profile_id', $current->id)->where('id', $offId)->delete() > 0;
            if ($removed) {
                $this->audit($current, $grant, 'STAFF_TIME_OFF_DELETE', 'StaffTimeOff', $offId);
            }

            return ['id' => (string) $offId, 'removed' => $removed];
        });
    }

    private function lockedWrite(StaffProfile $staff, RoleAssignment $grant, Closure $write): array
    {
        return DB::transaction(function () use ($staff, $grant, $write) {
            // Match booking and operational impact writers. The staff row serializes calendar edits.
            $branch = Branch::whereKey($staff->branch_id)->lockForUpdate()->firstOrFail();
            Business::whereKey($branch->business_id)->lockForUpdate()->firstOrFail();
            $current = StaffProfile::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            if ($current->branch_id !== $branch->id || $current->business_id !== $branch->business_id) {
                throw new BookingConflictException('Chi nhánh của nhân viên đã thay đổi. Vui lòng tải lại.');
            }
            $liveGrant = RoleAssignment::whereKey($grant->id)->where('user_id', $grant->user_id)
                ->where('business_id', $current->business_id)->whereNull('branch_id')->where('role_name', 'BUSINESS_OWNER')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now('UTC')))
                ->lockForUpdate()->first(['id']);
            abort_unless($liveGrant, 403);

            return $write($current, $branch);
        }, 3);
    }

    private function normalizeDay(array $day): array
    {
        if ((bool) $day['isOff'] !== ($day['intervals'] === [])) {
            throw new DomainException('Ngày nghỉ không có ca. Ngày làm việc cần ít nhất một ca.');
        }
        $intervals = array_map(fn ($row) => [$this->minute($row['startTime']), $this->minute($row['endTime'])], $day['intervals']);
        usort($intervals, fn ($left, $right) => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = array_key_last($merged);
            $previousEnd = $last === null ? null : $merged[$last][1];
            if ($end <= $start || ($previousEnd !== null && $start < $previousEnd)) {
                throw new DomainException('Giờ kết thúc phải sau giờ bắt đầu và các ca không được chồng lấn.');
            }
            if ($start === $previousEnd) {
                $merged[$last][1] = $end;
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    private function minute(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    private function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private function coversWindow(array $windows, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $midnight = $start->startOfDay();
        foreach ($windows as [$from, $to]) {
            if ($start >= $midnight->addMinutes($from) && $end <= $midnight->addMinutes($to)) {
                return true;
            }
        }

        return false;
    }

    private function period(array $input, string $start, string $end): array
    {
        $from = $this->parseInstant($input[$start], $start);
        $to = $this->parseInstant($input[$end], $end);
        if ($from >= $to || $to > $from->addDays(366)) {
            throw ValidationException::withMessages([$end => 'Khoảng thời gian phải lớn hơn 0 và tối đa 366 ngày.']);
        }

        return [$from, $to];
    }

    private function parseInstant(string $value, string $field): CarbonImmutable
    {
        $format = '/\A(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,3})?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/';
        if (preg_match($format, $value, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            $instant = CarbonImmutable::parse($value)->utc();
            if ($instant->year >= 1000 && $instant->year <= 9999) {
                return $instant;
            }
        }

        throw ValidationException::withMessages([$field => 'Dùng thời điểm ISO hợp lệ có múi giờ và tối đa 3 chữ số phần giây.']);
    }

    private function sqlInstant(CarbonImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.v');
    }

    private function timeOffData(object $row, bool $private): array
    {
        $data = ['id' => (string) $row->id, 'staffId' => (string) $row->staff_profile_id,
            'startsAt' => CarbonImmutable::parse($row->starts_at, 'UTC')->toIso8601ZuluString('millisecond'),
            'endsAt' => CarbonImmutable::parse($row->ends_at, 'UTC')->toIso8601ZuluString('millisecond')];

        return $private ? $data + ['reason' => $row->reason,
            'recordedById' => $row->recorded_by_id === null ? null : (string) $row->recorded_by_id] : $data;
    }

    private function activeItems(StaffProfile $staff): Builder
    {
        return BookingService::where('staff_profile_id', $staff->id)->where('branch_id', $staff->branch_id)
            ->whereHas('booking', fn ($query) => $query->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS']));
    }

    private function warnings(Builder $query, ?Closure $conflicts = null): array
    {
        $count = 0;
        $rows = [];
        foreach ($query->orderBy('item_start_at')->orderBy('id')->lazy(250) as $item) {
            if ($conflicts && ! $conflicts($item)) {
                continue;
            }
            $count++;
            if ($count <= self::WARNING_LIMIT) {
                $rows[] = ['id' => (string) $item->id, 'bookingId' => (string) $item->booking_id,
                    'startAt' => $item->item_start_at->toIso8601String(), 'endAt' => $item->item_end_at->toIso8601String()];
            }
        }

        return ['conflicts' => $rows, 'conflictCount' => $count,
            'conflictsTruncated' => $count > self::WARNING_LIMIT, 'existingBookingsPreserved' => true];
    }

    private function audit(StaffProfile $staff, RoleAssignment $grant, string $action, string $type, int $id, ?int $count = null): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $grant->user_id, 'business_id' => $staff->business_id,
            'action' => $action, 'entity_type' => $type, 'entity_id' => (string) $id,
            'metadata' => json_encode(['staffId' => $staff->id, 'branchId' => $staff->branch_id, 'conflictCount' => $count], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
