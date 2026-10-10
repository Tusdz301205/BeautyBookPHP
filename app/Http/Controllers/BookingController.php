<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Exceptions\BookingConflictException;
use App\Http\Requests\BookingAvailabilityRequest;
use App\Http\Requests\BookingCreateRequest;
use App\Http\Requests\BookingQuoteRequest;
use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\CustomerProfile;
use App\Models\StaffProfile;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\AppointmentChangeRequestService;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\BookingItemService;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingPolicyService;
use App\Services\Booking\CreateBookingService;
use App\Services\Identity\SessionContext;
use App\Services\Pricing\QuoteService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request, SessionContext $sessionContext)
    {
        $user = $request->user();
        abort_unless($user, 401);

        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'booking' => ['nullable', 'integer', 'min:1'],
            'filter' => ['nullable', 'in:upcoming,completed,cancelled'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'business_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $date = $data['date'] ?? null;
        $query = Booking::query()->with([
            'branch.business',
            'customerProfile.user',
            'contact',
            'services.staffProfile',
            'changeRequests',
        ]);
        $bookingContext = null;

        if ($user->account_kind === AccountKind::Customer) {
            $profile = CustomerProfile::query()->where('user_id', $user->id)->first();
            abort_unless($profile, 403);
            $query->where('customer_profile_id', $profile->id);
            $bookingContext = [
                'kind' => 'CUSTOMER',
                'customer_profile_id' => $profile->id,
                'staff_profile_id' => null,
            ];
        } else {
            $assignment = $sessionContext->current($request);
            abort_unless($assignment, 403);

            if ($assignment->role_name === RoleName::BusinessOwner->value) {
                $query->where('business_id', $assignment->business_id);
                $bookingContext = [
                    'kind' => 'OWNER',
                    'business_id' => $assignment->business_id,
                    'branch_id' => null,
                    'staff_profile_id' => null,
                ];
            } elseif ($assignment->role_name === RoleName::Receptionist->value) {
                $query->where('business_id', $assignment->business_id)
                    ->where('branch_id', $assignment->branch_id);
                $bookingContext = [
                    'kind' => 'RECEPTIONIST',
                    'business_id' => $assignment->business_id,
                    'branch_id' => $assignment->branch_id,
                    'staff_profile_id' => null,
                ];
            } elseif ($assignment->role_name === RoleName::Staff->value) {
                $staff = StaffProfile::query()
                    ->where('user_id', $user->id)
                    ->where('business_id', $assignment->business_id)
                    ->where('branch_id', $assignment->branch_id)
                    ->where('status', 'ACTIVE')
                    ->first();
                abort_unless($staff, 403);
                $query->where('business_id', $assignment->business_id)
                    ->where('branch_id', $assignment->branch_id)
                    ->whereHas('services', fn ($serviceQuery) => $serviceQuery->where('staff_profile_id', $staff->id));
                $bookingContext = [
                    'kind' => 'STAFF',
                    'business_id' => $assignment->business_id,
                    'branch_id' => $assignment->branch_id,
                    'staff_profile_id' => $staff->id,
                ];
            } elseif ($assignment->role_name === RoleName::PlatformAdmin->value) {
                $bookingContext = [
                    'kind' => 'ADMIN',
                    'business_id' => null,
                    'branch_id' => null,
                    'staff_profile_id' => null,
                ];
            } else {
                abort(403);
            }
        }

        if ($date !== null) {
            $from = CarbonImmutable::parse($date, 'Asia/Ho_Chi_Minh')->startOfDay()->utc();
            $query->where('appointment_start_at', '>=', $from)
                ->where('appointment_start_at', '<', $from->addDay());
        }

        if (isset($data['booking'])) {
            $query->whereKey($data['booking']);
        }
        if (isset($data['branch_id'])) $query->where('branch_id', $data['branch_id']);
        if (isset($data['business_id'])) $query->where('business_id', $data['business_id']);
        if (($data['filter'] ?? null) === 'upcoming') {
            $query->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS']);
        } elseif (($data['filter'] ?? null) === 'completed') {
            $query->where('status', 'COMPLETED');
        } elseif (($data['filter'] ?? null) === 'cancelled') {
            $query->whereIn('status', ['CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW']);
        }

        $bookingCounts = (clone $query)->reorder()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $accessibleBranches = Branch::query()->with('business')->orderBy('public_name');
        if ($bookingContext['kind'] === 'OWNER') $accessibleBranches->where('business_id', $bookingContext['business_id']);
        elseif (in_array($bookingContext['kind'], ['RECEPTIONIST', 'STAFF'], true)) $accessibleBranches->whereKey($bookingContext['branch_id']);
        elseif ($bookingContext['kind'] === 'CUSTOMER') $accessibleBranches->whereRaw('1 = 0');

        $bookings = $query
            ->orderBy('appointment_start_at')
            ->paginate(40)
            ->withQueryString();

        return view('booking.index', [
            'bookings' => $bookings,
            'bookingContext' => $bookingContext,
            'date' => $date,
            'filter' => $data['filter'] ?? null,
            'selectedBranch' => $data['branch_id'] ?? null,
            'selectedBusiness' => $data['business_id'] ?? null,
            'accessibleBranches' => $accessibleBranches->get(),
            'bookingCounts' => $bookingCounts,
            'changeStaff' => in_array($bookingContext['kind'], ['CUSTOMER', 'OWNER', 'RECEPTIONIST'], true)
                ? StaffProfile::whereIn('branch_id', $bookings->pluck('branch_id')->unique())
                    ->where('status', 'ACTIVE')->where('is_bookable', true)
                    ->when($bookingContext['kind'] === 'CUSTOMER', fn ($query) => $query->where('public_visible', true))
                    ->with('offerings')->orderBy('full_name')->get()
                : collect(),
            'changeSettings' => app(\App\Services\Platform\PlatformSettingsService::class)->get(),
        ]);
    }

    public function create(
        Request $request,
        Branch $branch,
        BookingAccessService $access,
        BookingPolicyService $policies,
    )
    {
        $context = $access->resolve($request, $branch);
        $bookingPolicy = $context['kind'] === 'CUSTOMER'
            ? $policies->read((int) $context['customer_profile_id'], (int) $branch->business_id)
            : null;

        $offerings = BranchServiceOffering::query()
            ->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id)
            ->where('bookable', true)
            ->where('is_active', true)
            ->whereHas('service', fn ($query) => $query->where('status', 'ACTIVE')->where('is_active', true))
            ->with([
                'service.category',
                'variants' => fn ($query) => $query->where('status', 'ACTIVE')->orderBy('name'),
            ])
            ->orderBy('id')
            ->get();

        $staffQuery = StaffProfile::query()
            ->where('business_id', $branch->business_id)
            ->where('branch_id', $branch->id)
            ->where('status', 'ACTIVE')
            ->where('is_bookable', true)
            ->with(['offerings' => fn ($query) => $query->where('branch_service_offerings.branch_id', $branch->id)]);

        if ($context['kind'] === 'CUSTOMER') {
            $staffQuery->where('public_visible', true);
        }

        return view('booking.create', [
            'branch' => $branch->loadMissing('business'),
            'offerings' => $offerings,
            'staffProfiles' => $staffQuery->orderBy('full_name')->get(),
            'bookingContext' => $context,
            'bookingPolicy' => $bookingPolicy,
            'customer' => $request->user(),
            'bookingLimits' => app(\App\Services\Platform\PlatformSettingsService::class)->get(),
        ]);
    }

    public function slots(Request $request, Branch $branch, BookingAccessService $access, QuoteService $quotes, AvailabilityService $availability)
    {
        $context = $access->resolve($request, $branch);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'branch_service_offering_id' => ['required', 'integer'],
            'service_variant_id' => ['nullable', 'integer'],
            'staff_profile_id' => ['required', 'integer'],
        ]);
        try {
            $quote = $quotes->quote($branch, (int) $data['branch_service_offering_id'], isset($data['service_variant_id']) ? (int) $data['service_variant_id'] : null);
            $staff = $availability->resolveStaff($branch, $quote['offering'], (int) $data['staff_profile_id'], $context['kind'] === 'CUSTOMER');
            $day = CarbonImmutable::parse($data['date'], $branch->timezone ?: 'Asia/Ho_Chi_Minh')->startOfDay();
            $settings = app(\App\Services\Platform\PlatformSettingsService::class)->get();
            $now = CarbonImmutable::now($day->timezone);
            if ($day->endOfDay()->lessThan($now) || $day->greaterThan($now->addDays($settings['maxAdvanceBookingDays']))) {
                throw new DomainException('Ngày đã chọn nằm ngoài khoảng đặt lịch.');
            }
            $hours = \App\Models\BranchWorkingHour::where('branch_id', $branch->id)->where('day_of_week', $day->dayOfWeek)->first();
            $slots = [];
            if ($hours && ! $hours->is_closed && $hours->open_time && $hours->close_time) {
                $cursor = CarbonImmutable::parse($data['date'].' '.$hours->open_time, $day->timezone)->addMinutes((int) $quote['buffer_before_minutes']);
                $close = CarbonImmutable::parse($data['date'].' '.$hours->close_time, $day->timezone);
                $attempts = 0;
                while ($attempts++ < 96 && $cursor->addMinutes((int) $quote['duration_minutes'] + (int) $quote['buffer_after_minutes'])->lessThanOrEqualTo($close)) {
                    try {
                        $window = $availability->check($branch, $staff, $quote, $cursor->format('Y-m-d\TH:i'), $context['customer_profile_id']);
                        $slots[] = ['value' => $cursor->format('Y-m-d\TH:i'), 'label' => $cursor->format('H:i'), 'end' => $window['local_end_at']->format('H:i')];
                    } catch (DomainException) {
                        // Busy, closed or outside the booking limits: never advertise that slot.
                    }
                    $cursor = $cursor->addMinutes(15);
                }
            }
            return response()->json(['slots' => $slots, 'timezone' => $day->timezoneName]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function quote(
        BookingQuoteRequest $request,
        Branch $branch,
        BookingAccessService $access,
        QuoteService $quotes,
    ) {
        $context = $access->resolve($request, $branch);

        try {
            $quote = $quotes->quote(
                $branch,
                (int) $request->validated('branch_service_offering_id'),
                $request->validated('service_variant_id') !== null
                    ? (int) $request->validated('service_variant_id')
                    : null,
                false,
                $context['customer_profile_id'],
                $request->validated('voucher_code'),
                (int) ($request->validated('loyalty_points') ?? 0),
            );

            return response()->json([
                'currency' => $quote['currency'],
                'unit_price' => $quote['unit_price'],
                'subtotal_amount' => $quote['subtotal_amount'],
                'discount_amount' => $quote['discount_amount'],
                'final_amount' => $quote['final_amount'],
                'duration_minutes' => $quote['duration_minutes'],
                'buffer_before_minutes' => $quote['buffer_before_minutes'],
                'buffer_after_minutes' => $quote['buffer_after_minutes'],
                'service_name' => $quote['offering']->service->name,
                'variant_name' => $quote['variant']?->name,
                'discount_rules' => $quote['discount_rules'] ?? [],
                'loyalty' => $quote['loyalty'] ?? null,
            ]);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function availability(
        BookingAvailabilityRequest $request,
        Branch $branch,
        BookingAccessService $access,
        QuoteService $quotes,
        AvailabilityService $availability,
    ) {
        $context = $access->resolve($request, $branch);
        $data = $request->validated();

        try {
            $quote = $quotes->quote(
                $branch,
                (int) $data['branch_service_offering_id'],
                isset($data['service_variant_id']) ? (int) $data['service_variant_id'] : null,
            );
            $staff = $availability->resolveStaff(
                $branch,
                $quote['offering'],
                (int) $data['staff_profile_id'],
                $context['kind'] === 'CUSTOMER',
            );
            $window = $availability->check(
                $branch,
                $staff,
                $quote,
                $data['start_at'],
                $context['customer_profile_id'],
            );

            return response()->json([
                'available' => true,
                'local_start_at' => $window['local_start_at']->format('d/m/Y H:i'),
                'local_end_at' => $window['local_end_at']->format('d/m/Y H:i'),
            ]);
        } catch (DomainException $exception) {
            return response()->json(['available' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(
        BookingCreateRequest $request,
        Branch $branch,
        BookingAccessService $access,
        CreateBookingService $bookings,
    ) {
        $context = $access->resolve($request, $branch);

        try {
            $result = $bookings->create($branch, $context, $request->validated());
            $booking = $result['booking'];

            return response()->json([
                'booking_id' => $booking->id,
                'booking_code' => $booking->booking_code,
                'status' => $booking->status,
                'replayed' => $result['replayed'],
                'message' => $result['replayed']
                    ? 'Yêu cầu này đã được xử lý trước đó. Lịch hẹn cũ được trả lại.'
                    : 'Đặt lịch thành công. Lịch đang ở trạng thái chờ xác nhận.',
            ], (int) $result['http_status']);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function transition(Request $request, Booking $booking, BookingLifecycleService $lifecycle)
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:CONFIRMED,CHECKED_IN,IN_PROGRESS,COMPLETED,CANCELLED,NO_SHOW,REJECTED,EXPIRED'],
            'reason' => ['nullable', 'string', 'max:255'],
            'confirmed_no_show' => ['sometimes', 'boolean'],
        ]);

        try {
            $updated = $lifecycle->transition(
                $request,
                $booking,
                $data['status'],
                $data['reason'] ?? null,
                (bool) ($data['confirmed_no_show'] ?? false),
            );

            return response()->json([
                'booking_id' => $updated->id,
                'status' => $updated->status,
            ]);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function transitionItem(
        Request $request,
        Booking $booking,
        \App\Models\BookingService $item,
        BookingItemService $items,
    ) {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:START,COMPLETE'],
            'expected_revision' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $items->transition(
                $request,
                $booking,
                $item,
                $data['action'],
                (int) $data['expected_revision'],
            );

            return response()->json([
                'booking_id' => $booking->id,
                'booking_status' => $booking->fresh()->status,
                'item_id' => $updated->id,
                'item_status' => $updated->status,
                'revision' => $updated->revision,
            ]);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function createChangeRequest(
        Request $request,
        Booking $booking,
        AppointmentChangeRequestService $changeRequests,
    ) {
        $data = $request->validate([
            'request_type' => ['required', 'string', 'in:CANCEL,RESCHEDULE,STAFF_CHANGE'],
            'proposed_start_at' => ['nullable', 'date'],
            'proposed_end_at' => ['nullable', 'date'],
            'proposed_local_start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'proposed_staff_profile_id' => ['nullable', 'integer', 'exists:staff_profiles,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['request_type'] === 'RESCHEDULE' && ! empty($data['proposed_local_start_at'])) {
            $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['proposed_local_start_at'], $booking->branch->timezone ?: 'Asia/Ho_Chi_Minh')->utc();
            $oldStart = CarbonImmutable::parse($booking->getRawOriginal('appointment_start_at'), 'UTC');
            $oldEnd = CarbonImmutable::parse($booking->getRawOriginal('appointment_end_at'), 'UTC');
            $data['proposed_start_at'] = $start->toIso8601String();
            $data['proposed_end_at'] = $start->addSeconds((int) $oldStart->diffInSeconds($oldEnd))->toIso8601String();
        }

        try {
            $changeRequest = $changeRequests->create($request, $booking, $data);

            return response()->json([
                'request_id' => $changeRequest->id,
                'booking_id' => $changeRequest->booking_id,
                'request_type' => $changeRequest->request_type,
                'status' => $changeRequest->status,
                'expires_at' => $changeRequest->expires_at?->toIso8601String(),
            ], 201);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function approveChangeRequest(
        Request $request,
        Booking $booking,
        AppointmentChangeRequest $changeRequest,
        AppointmentChangeRequestService $changeRequests,
    ) {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        try {
            $updated = $changeRequests->approve($request, $booking, $changeRequest, $data['review_note'] ?? null);

            return response()->json([
                'request_id' => $updated->id,
                'status' => $updated->status,
                'booking_status' => $booking->fresh()->status,
            ]);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function rejectChangeRequest(
        Request $request,
        Booking $booking,
        AppointmentChangeRequest $changeRequest,
        AppointmentChangeRequestService $changeRequests,
    ) {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:2000']]);

        try {
            $updated = $changeRequests->reject($request, $booking, $changeRequest, $data['review_note'] ?? null);

            return response()->json([
                'request_id' => $updated->id,
                'status' => $updated->status,
            ]);
        } catch (BookingConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }
}
