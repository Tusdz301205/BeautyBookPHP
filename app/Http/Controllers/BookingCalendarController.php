<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\CustomerProfile;
use App\Models\StaffProfile;
use App\Services\Identity\SessionContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BookingCalendarController extends Controller
{
    public function __invoke(Request $request, SessionContext $context)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'view' => ['nullable', 'in:day,week'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'business_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $timezone = 'Asia/Ho_Chi_Minh';
        $date = CarbonImmutable::parse($data['date'] ?? CarbonImmutable::now($timezone)->toDateString(), $timezone)->startOfDay();
        $mode = $data['view'] ?? 'week';
        $from = $mode === 'week' ? $date->startOfWeek() : $date;
        $to = $from->addDays($mode === 'week' ? 7 : 1);
        $query = Booking::query()->with(['branch.business', 'customerProfile.user', 'contact', 'services.staffProfile']);
        $branches = Branch::query()->with('business')->orderBy('public_name');
        $staffId = null;
        $user = $request->user();
        abort_unless($user, 401);

        if ($user->account_kind === AccountKind::Customer) {
            $kind = 'CUSTOMER';
            $profile = CustomerProfile::where('user_id', $user->id)->first();
            abort_unless($profile, 403);
            $query->where('customer_profile_id', $profile->id);
            $branches->whereRaw('1 = 0');
        } else {
            // Always use the selected workspace, never the union of all grants.
            $assignment = $context->current($request);
            abort_unless($assignment, 403);
            $kind = match ($assignment->role_name) {
                RoleName::BusinessOwner->value => 'OWNER',
                RoleName::Receptionist->value => 'RECEPTIONIST',
                RoleName::Staff->value => 'STAFF',
                RoleName::PlatformAdmin->value => 'ADMIN',
                default => abort(403),
            };
            if ($kind === 'OWNER') {
                $query->where('business_id', $assignment->business_id);
                $branches->where('business_id', $assignment->business_id);
            } elseif (in_array($kind, ['RECEPTIONIST', 'STAFF'], true)) {
                $query->where('business_id', $assignment->business_id)->where('branch_id', $assignment->branch_id);
                $branches->whereKey($assignment->branch_id);
                if ($kind === 'STAFF') {
                    $staff = StaffProfile::where('user_id', $user->id)->where('business_id', $assignment->business_id)
                        ->where('branch_id', $assignment->branch_id)->where('status', 'ACTIVE')->first();
                    abort_unless($staff, 403);
                    $staffId = $staff->id;
                    $query->whereHas('services', fn ($q) => $q->where('staff_profile_id', $staffId));
                }
            }
        }
        if (! empty($data['business_id'])) $query->where('business_id', $data['business_id']);
        if (! empty($data['branch_id'])) $query->where('branch_id', $data['branch_id']);
        $query->where('appointment_start_at', '<', $to->utc())->where('appointment_end_at', '>', $from->utc());
        $bookings = $query->orderBy('appointment_start_at')->orderBy('id')->paginate(350)->withQueryString();
        $days = collect(range(0, $mode === 'week' ? 6 : 0))->map(fn ($offset) => $from->addDays($offset));
        $groups = $days->mapWithKeys(fn ($day) => [$day->toDateString() => collect()]);
        $firstHour = 8;
        $lastHour = 20;
        foreach ($bookings as $booking) {
            // Calendar time is explicitly labelled; branch-local times remain on booking details.
            $items = $staffId ? $booking->services->where('staff_profile_id', $staffId) : $booking->services;
            $windows = $staffId ? $items->map(fn ($item) => [
                'start' => CarbonImmutable::parse($item->getRawOriginal('item_start_at'), 'UTC')->setTimezone($timezone),
                'end' => CarbonImmutable::parse($item->getRawOriginal('item_end_at'), 'UTC')->setTimezone($timezone),
                'services' => $item->service_name_snapshot,
            ]) : collect([[
                'start' => CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC')->setTimezone($timezone),
                'end' => CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC')->setTimezone($timezone),
                'services' => $items->pluck('service_name_snapshot')->implode(' · '),
            ]]);
            foreach ($windows as $window) {
                $start = $window['start'];
                $end = $window['end'];
                foreach ($days as $day) {
                    if ($start->lessThan($day->addDay()) && $end->greaterThan($day)) {
                        $visibleStart = $start->max($day);
                        $visibleEnd = $end->min($day->addDay());
                        $firstHour = min($firstHour, $visibleStart->hour);
                        $lastHour = max($lastHour, (int) ceil(($visibleEnd->timestamp - $day->timestamp) / 3600));
                        $groups[$day->toDateString()]->push([
                            'booking' => $booking, 'start' => $start, 'end' => $end, 'visible_start' => $visibleStart, 'visible_end' => $visibleEnd,
                            'services' => $window['services'],
                        ]);
                    }
                }
            }
        }
        // Give overlapping events independent columns so no appointment hides another.
        $groups = $groups->map(function ($events) {
            $tracks = [];
            $positioned = $events->sortBy(fn ($event) => $event['visible_start']->timestamp)->map(function ($event) use (&$tracks) {
                $track = 0;
                while (isset($tracks[$track]) && $tracks[$track]->greaterThan($event['visible_start'])) $track++;
                $tracks[$track] = $event['visible_end'];
                return $event + ['track' => $track];
            });
            return $positioned->map(fn ($event) => $event + ['tracks' => max(1, count($tracks))]);
        });
        return view('booking.calendar', compact('date', 'mode', 'from', 'to', 'days', 'groups', 'firstHour', 'lastHour', 'bookings', 'kind', 'timezone') + [
            'accessibleBranches' => $branches->get(), 'selectedBranch' => $data['branch_id'] ?? null,
            'selectedBusiness' => $data['business_id'] ?? null,
        ]);
    }
}
