<?php

namespace App\Http\Controllers\Beautybook;

use App\Enums\AccountKind;
use App\Http\Controllers\Controller;
use App\Models\AppointmentChangeRequest;
use App\Models\Booking;
use App\Models\BookingViolationEvent;
use App\Models\Branch;
use App\Models\Combo;
use App\Models\CustomerProfile;
use App\Models\RecurringBookingSeries;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Beautybook\ApiScope;
use App\Services\Beautybook\BookingCommands;
use App\Services\Beautybook\BookingItemLifecycle;
use App\Services\Beautybook\BookingPresenter;
use App\Services\Booking\AppointmentChangeRequestService;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\BookingLifecycleAccessService;
use App\Services\Booking\BookingLifecycleService;
use App\Services\Booking\BookingPolicyService;
use App\Services\Booking\CartBookingService;
use App\Services\Finance\Money;
use App\Services\Finance\PaymentService;
use App\Services\Identity\SessionContext;
use App\Services\Pricing\QuoteService;
use App\Services\Scheduling\CreateComboBookingService;
use App\Services\Scheduling\RecurringService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BookingController extends Controller
{
    public function __construct(private BookingPresenter $presenter, private SessionContext $session) {}

    public function query(Request $r)
    {
        $q = Booking::query();
        if ($r->user()->account_kind === AccountKind::Customer) {
            $q->where('customer_profile_id', CustomerProfile::where('user_id', $r->user()->id)->value('id'));
        } else {
            $a = app(ApiScope::class)->current($r);
            abort_unless($a, 403);
            if ($a->role_name !== 'PLATFORM_ADMIN') {
                $q->where('business_id', $a->business_id);
                $grants = app(ApiScope::class)->assignments($r);
                if (! $grants->contains('role_name', 'BUSINESS_OWNER')) {
                    $counterBranches = $grants->where('role_name', 'RECEPTIONIST')->pluck('branch_id');
                    $staffBranches = $grants->where('role_name', 'STAFF')->pluck('branch_id');
                    $q->where(fn ($query) => $query->whereIn('branch_id', $counterBranches)->orWhere(fn ($own) => $own->whereIn('branch_id', $staffBranches)->whereHas('services', fn ($items) => $items->whereIn('staff_profile_id', StaffProfile::where('user_id', $r->user()->id)->whereIn('branch_id', $staffBranches)->select('id')))));
                }
            }
        }

        return $q;
    }

    private function booking(Request $r, int $id): Booking
    {
        return $this->query($r)->findOrFail($id);
    }

    private function operating(Request $r, Branch $branch, array $roles = ['BUSINESS_OWNER', 'RECEPTIONIST']): Request
    {
        return $r->user()->account_kind === AccountKind::Customer ? $r : app(ApiScope::class)->requestForRoles($r, $roles, $branch->business_id, $branch->id);
    }

    public function index(Request $r)
    {
        $r->validate(['page' => 'nullable|integer|min:1', 'limit' => 'nullable|integer|between:1,100', 'dateFrom' => 'nullable|date', 'dateTo' => 'nullable|date', 'branchId' => 'nullable|integer', 'status' => 'nullable|string|max:30', 'search' => 'nullable|string|max:100']);
        $q = $this->query($r)->when($r->branchId, fn ($q) => $q->where('branch_id', $r->branchId))->when($r->status, fn ($q) => $q->where('status', $r->status))
            ->when($r->dateFrom, fn ($q) => $q->where('appointment_start_at', '>=', CarbonImmutable::parse($r->dateFrom)->utc()))
            ->when($r->dateTo, fn ($q) => $q->where('appointment_start_at', '<=', CarbonImmutable::parse($r->dateTo)->endOfDay()->utc()))
            ->when($r->search, fn ($q) => $q->where('booking_code', 'like', '%'.$r->search.'%'));
        $p = $q->orderBy('appointment_start_at')->paginate($r->integer('limit', 20));

        return ['data' => $p->map(fn ($b) => $this->presenter->row($b)), 'meta' => ['total' => $p->total(), 'page' => $p->currentPage(), 'limit' => $p->perPage(), 'totalPages' => $p->lastPage()]];
    }

    public function show(Request $r, int $id)
    {
        return $this->presenter->detail($this->booking($r, $id));
    }

    public function stats(Request $r)
    {
        $q = $this->query($r);
        $counts = (clone $q)->selectRaw('status,count(*) AS count')->groupBy('status')->pluck('count', 'status');

        return ['total' => $counts->sum(), 'pending' => $counts['PENDING'] ?? 0, 'confirmed' => $counts['CONFIRMED'] ?? 0, 'checkedIn' => $counts['CHECKED_IN'] ?? 0, 'inProgress' => $counts['IN_PROGRESS'] ?? 0, 'completed' => $counts['COMPLETED'] ?? 0, 'cancelled' => $counts['CANCELLED'] ?? 0, 'noShow' => $counts['NO_SHOW'] ?? 0];
    }

    public function appointments(Request $r)
    {
        $tab = $r->input('tab', 'upcoming');
        $q = $this->query($r);
        if ($tab === 'completed') {
            $q->where('status', 'COMPLETED');
        } elseif ($tab === 'cancelled') {
            $q->whereIn('status', ['CANCELLED', 'REJECTED', 'EXPIRED', 'NO_SHOW']);
        } else {
            $q->whereIn('status', ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS']);
        }

        return $q->orderBy('appointment_start_at')->get()->map(fn ($b) => $this->presenter->detail($b));
    }

    public function byBranch(Request $r, int $id)
    {
        $r->merge(['branchId' => $id]);

        return $this->index($r);
    }

    public function byCustomer(Request $r, int $id)
    {
        abort_unless((int) $r->user()->id === $id || $this->session->current($r)?->role_name === 'PLATFORM_ADMIN', 403);

        return $this->query($r)->whereHas('customerProfile', fn ($q) => $q->where('user_id', $id))->get()->map(fn ($b) => $this->presenter->row($b));
    }

    public function byCategory(Request $r)
    {
        return $this->query($r)->whereHas('services.offering.service.category', fn ($q) => $q->where('name', $r->name))->get()->map(fn ($b) => $this->presenter->row($b));
    }

    private function data(Request $r, bool $store = false): array
    {
        $r->validate(['branchId' => 'required|integer|min:1', 'serviceIds' => 'nullable', 'staffId' => ['nullable', 'regex:/^(?:[1-9][0-9]*|AUTO)$/D'], 'variantSelections' => 'nullable', 'voucherCode' => 'nullable|string|max:64', 'source' => 'nullable|in:ONLINE_WEB,ONLINE_APP,WALK_IN,PHONE,STAFF_CREATED,ADMIN_CREATED']);
        $ids = $r->input('serviceIds', []);
        if (is_string($ids)) {
            $ids = explode(',', $ids);
        }abort_unless(is_array($ids) && count($ids) <= 20, 422);
        $variants = $r->input('variantSelections', []);
        if (is_string($variants)) {
            $variants = json_decode($variants, true, 512, JSON_THROW_ON_ERROR);
        }abort_unless(is_array($variants), 422);
        $items = [];
        foreach ($ids as $id) {
            abort_unless(ctype_digit((string) $id) && (int) $id > 0, 422);
            $items[] = ['branch_service_offering_id' => (int) $id, 'service_variant_id' => isset($variants[$id]) ? (int) $variants[$id] : null];
        }
        $data = ['items' => $items, 'staff_profile_id' => $r->staffId && $r->staffId !== 'AUTO' ? (int) $r->staffId : null, 'voucher_code' => $r->voucherCode, 'loyalty_points' => 0, 'source_timeline' => true];
        if ($r->source) {
            $data['source'] = $r->source;
        }
        if ($r->appointmentDate) {
            $r->validate(['appointmentDate' => 'date']);
            $branch = Branch::findOrFail($r->branchId);
            $data['start_at'] = CarbonImmutable::parse($r->appointmentDate)->setTimezone($branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i');
        }
        if ($r->controlledOverbooking) {
            $r->validate(['controlledOverbooking' => 'boolean', 'overbookingReason' => 'required|string|min:10|max:500']);
            $data['controlled_overbooking'] = $r->boolean('controlledOverbooking');
            $data['overbooking_reason'] = $r->overbookingReason;
        }
        if (($store && $r->user()->account_kind === AccountKind::Customer) || $r->has('customerName') || $r->has('customerPhone')) {
            $phone = preg_replace('/[\s.-]+/', '', (string) $r->customerPhone);
            $r->merge(['customerPhone' => $phone]);
            $r->validate(['customerName' => 'required|string|min:2|max:160', 'customerPhone' => ['required', 'regex:/^(?:0\d{9}|\+84\d{9})$/D'], 'contactConsent' => 'required|accepted']);
            $data['contact_consent'] = true;
            $data['contact'] = ['full_name' => $r->customerName, 'phone' => $phone, 'email' => $r->user()?->email];
        }
        if ($store) {
            $r->validate(['appointmentDate' => 'required|date', 'note' => 'nullable|string|max:2000', 'guestName' => 'nullable|string|max:160', 'guestPhone' => 'nullable|string|max:32', 'violationAcknowledged' => 'nullable|boolean']);
            $b = Branch::findOrFail($r->branchId);
            $data += ['start_at' => CarbonImmutable::parse($r->appointmentDate)->setTimezone($b->timezone ?: 'Asia/Ho_Chi_Minh')->format('Y-m-d\TH:i'), 'note' => $r->note, 'contact' => ['full_name' => $r->guestName, 'phone' => $r->guestPhone, 'email' => $r->input('guestEmail')],
                'source' => in_array($r->source, ['WALK_IN', 'PHONE']) ? $r->source : 'PHONE', 'violation_acknowledged' => $r->boolean('violationAcknowledged'), 'idempotency_key' => $r->header('Idempotency-Key') ?: ($r->user()->account_kind === AccountKind::Operational ? (string) Str::uuid() : null)];
            abort_unless(is_string($data['idempotency_key']) && strlen($data['idempotency_key']) >= 8 && strlen($data['idempotency_key']) <= 100, 422);
        }

        return $data;
    }

    public function policy(Request $r, BookingPolicyService $policy)
    {
        $r->validate(['branchId' => 'required|integer']);
        $branch = Branch::findOrFail($r->branchId);
        $context = app(BookingAccessService::class)->resolve($r, $branch);
        abort_unless($context['customer_profile_id'], 403);
        $p = $policy->read($context['customer_profile_id'], $branch->business_id);

        return $p + ['selfBookingAllowed' => $p['self_booking_allowed'], 'acknowledgmentRequired' => $p['acknowledgment_required'], 'warningLevel' => $p['warning_level'], 'violationScore' => $p['score']];
    }

    private function comboStaff(Branch $branch, Combo $combo, array $context, array $data): array
    {
        if (! empty($data['staff_profile_id'])) {
            if ($combo->staff_assignment_mode === 'PER_SERVICE_PROVIDER') {
                foreach ($combo->items as $item) {
                    $data['staff_assignments'][$item->branch_service_offering_id] = $data['staff_profile_id'];
                }
            }

            return $data;
        }
        if ($combo->staff_assignment_mode === 'PER_SERVICE_PROVIDER') {
            $cursor = CarbonImmutable::parse($data['start_at'], $branch->timezone ?: 'Asia/Ho_Chi_Minh');
            $data['staff_assignments'] = [];
            foreach ($combo->items as $item) {
                $quote = app(QuoteService::class)->quote($branch, $item->branch_service_offering_id, $data['variant_assignments'][$item->branch_service_offering_id] ?? null);
                $found = null;
                $failure = null;
                $ids = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->where('is_bookable', true)->when($context['kind'] === 'CUSTOMER', fn ($q) => $q->where('public_visible', true))->whereHas('offerings', fn ($q) => $q->where('branch_service_offerings.id', $item->branch_service_offering_id))->orderBy('id')->pluck('id');
                foreach ($ids as $id) {
                    try {
                        $staff = app(AvailabilityService::class)->resolveStaff($branch, $quote['offering'], $id, $context['kind'] === 'CUSTOMER');
                        $q = $quote;
                        $q['duration_minutes'] *= $item->quantity;
                        app(AvailabilityService::class)->check($branch, $staff, $q, $cursor->format('Y-m-d\TH:i'), $context['customer_profile_id']);
                        $found = $id;
                        break;
                    } catch (\DomainException $e) {
                        $failure = $e;
                    }
                }
                if (! $found) {
                    throw $failure ?? new \DomainException('Không có nhân viên phù hợp cho dịch vụ trong combo.');
                }$data['staff_assignments'][$item->branch_service_offering_id] = $found;
                $cursor = $cursor->addMinutes($quote['duration_minutes'] * $item->quantity + $item->transition_minutes);
            }

            return $data;
        }
        $ids = StaffProfile::where('branch_id', $branch->id)->where('business_id', $branch->business_id)->where('status', 'ACTIVE')->where('is_bookable', true)->when($context['kind'] === 'CUSTOMER', fn ($q) => $q->where('public_visible', true))->orderBy('id')->pluck('id');
        $failure = null;
        foreach ($ids as $id) {
            $candidate = $data + ['staff_assignments' => [], 'variant_assignments' => []];
            $candidate['staff_profile_id'] = $id;
            foreach ($combo->items as $item) {
                $candidate['staff_assignments'][$item->branch_service_offering_id] = $id;
            }try {
                app(CreateComboBookingService::class)->plan($branch, $combo, $context, $candidate, $context['customer_profile_id']);

                return $candidate;
            } catch (\DomainException $e) {
                $failure = $e;
            }
        }
        throw $failure ?? new \DomainException('Không còn nhân viên phù hợp cho toàn bộ combo.');
    }

    public function preview(Request $r, CartBookingService $cart, BookingAccessService $access)
    {
        $data = $this->data($r);
        $branch = Branch::findOrFail($r->branchId);
        $context = $access->resolve($this->operating($r, $branch), $branch);
        if ($r->comboId) {
            abort_unless(isset($data['start_at']), 422);
            $combo = Combo::where('branch_id', $branch->id)->findOrFail($r->comboId);
            $q = app(CreateComboBookingService::class)->previewPrice($branch, $combo, $context, $this->comboStaff($branch, $combo, $context, $data));
        } else {
            if (isset($data['start_at'])) {
                $data['staff_profile_id'] = $cart->previewPlan($branch, $context, $data)[0]['staff_id'];
            }$q = $cart->quote($branch, $context, $data);
        }
        $rules = $q['discount_rules'] ?? [];
        $promo = 0;
        $voucher = 0;
        foreach ($rules as $rule) {
            if (($rule['kind'] ?? $rule['type'] ?? '') === 'VOUCHER') {
                $voucher += ($rule['cents'] ?? 0);
            } else {
                $promo += ($rule['cents'] ?? 0);
            }
        }

        return ['subtotal' => $q['subtotal_amount'], 'subtotalAmount' => $q['subtotal_amount'], 'discountAmount' => $q['discount_amount'], 'promotionDiscount' => Money::decimal($promo), 'voucherDiscount' => Money::decimal($voucher), 'voucherApplied' => $voucher > 0, 'code' => $r->voucherCode, 'totalAmount' => $q['final_amount'], 'finalAmount' => $q['final_amount'], 'totalDuration' => $q['duration_minutes'], 'durationMinutes' => $q['duration_minutes'], 'currency' => 'VND', 'items' => $q['items'], 'appliedPromotions' => $rules];
    }

    public function slots(Request $r, CartBookingService $cart, BookingAccessService $access)
    {
        $data = $this->data($r);
        $r->validate(['date' => 'required|date_format:Y-m-d']);
        $branch = Branch::findOrFail($r->branchId);
        $result = $cart->slots($branch, $access->resolve($this->operating($r, $branch), $branch), $data + ['date' => $r->date]);

        return ['branchId' => (string) $branch->id, 'date' => $r->date, 'serverNow' => now()->toIso8601String(), 'slots' => array_map(fn ($s) => ['start' => CarbonImmutable::parse($s['value'], $result['timezone'])->toIso8601String(), 'end' => CarbonImmutable::parse($r->date.' '.$s['end'], $result['timezone'])->toIso8601String()], $result['slots'])];
    }

    public function store(Request $r, CartBookingService $cart, BookingAccessService $access)
    {
        $data = $this->data($r, true);
        $branch = Branch::findOrFail($r->branchId);
        $context = $access->resolve($this->operating($r, $branch), $branch);
        if ($r->comboId) {
            $combo = Combo::where('branch_id', $branch->id)->findOrFail($r->comboId);
            $data['variant_assignments'] = $r->input('variantSelections', []);
            $data['staff_assignments'] = [];
            $result = app(CreateComboBookingService::class)->create($branch, $combo, $context, $data);
        } else {
            $result = $cart->create($branch, $context, $data);
        }

        return response()->json($this->presenter->detail($result['booking']) + ['replayed' => $result['replayed']], 201);
    }

    public function status(Request $r, int $id, BookingLifecycleService $lifecycle)
    {
        $r->validate(['status' => 'required|in:PENDING,CONFIRMED,CHECKED_IN,IN_PROGRESS,COMPLETED,CANCELLED,NO_SHOW,REJECTED,EXPIRED', 'note' => 'nullable|string|max:1000', 'noShowConfirmed' => 'nullable|boolean']);

        $booking = $this->booking($r, $id);
        $operatingRequest = $r;
        if ($r->user()->account_kind !== AccountKind::Customer) {
            $roles = in_array($r->status, ['IN_PROGRESS', 'COMPLETED'], true) ? ['BUSINESS_OWNER', 'STAFF'] : ['BUSINESS_OWNER', 'RECEPTIONIST'];
            $operatingRequest = app(ApiScope::class)->requestForRoles($r, $roles, $booking->business_id, $booking->branch_id);
            $role = app(ApiScope::class)->current($operatingRequest);
            if ($role->role_name === 'STAFF') {
                abort_unless($booking->services()->whereIn('staff_profile_id', StaffProfile::where('user_id', $r->user()->id)->where('branch_id', $booking->branch_id)->select('id'))->exists(), 403);
            }
        }

        return $this->presenter->detail($lifecycle->transition($operatingRequest, $booking, $r->status, $r->note, $r->boolean('noShowConfirmed')));
    }

    public function checkin(Request $r, int $id, BookingLifecycleService $s)
    {
        $r->merge(['status' => 'CHECKED_IN']);

        return $this->status($r, $id, $s);
    }

    public function scheduler(Request $r)
    {
        $r->validate(['branchId' => 'required|integer', 'startDate' => 'required|date', 'endDate' => 'required|date|after_or_equal:startDate']);
        $a = app(ApiScope::class)->current($r);
        $q = $this->query($r)->where('branch_id', $r->branchId)->where('appointment_start_at', '<', CarbonImmutable::parse($r->endDate)->endOfDay())->where('appointment_end_at', '>', CarbonImmutable::parse($r->startDate));
        $branch = app(ApiScope::class)->branch($r, $r->branchId);

        return ['bookings' => $q->get()->map(fn ($b) => $this->presenter->detail($b)), 'staff' => StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->get()->map(fn ($s) => ['id' => (string) $s->id, 'fullName' => $s->full_name ?? $s->user?->name, 'isBookable' => $s->is_bookable])];
    }

    public function schedulerBatch(Request $r): array
    {
        $data = $r->validate([
            'branchIds' => ['required', 'string', 'max:600', 'regex:/^\d+(,\d+){0,49}$/'],
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
        ]);
        $start = CarbonImmutable::parse($data['startDate']);
        $end = CarbonImmutable::parse($data['endDate']);
        abort_if($start->diffInDays($end) > 45, 422, 'Khoảng lịch không được vượt quá 45 ngày.');
        $scope = app(ApiScope::class);
        abort_unless(in_array($scope->current($r)->role_name, ['PLATFORM_ADMIN', 'BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF'], true), 403);
        $branchIds = array_values(array_unique(explode(',', $data['branchIds'])));

        // Resolve every branch through its tenant/role scope before returning any booking data.
        $branches = array_map(fn ($id) => $scope->branch($r, $id), $branchIds);
        $result = [];
        foreach ($branches as $branch) {
            $bookings = $this->query($r)
                ->where('branch_id', $branch->id)
                ->where('appointment_start_at', '<', $end->endOfDay())
                ->where('appointment_end_at', '>', $start)
                ->get()->map(fn ($booking) => $this->presenter->detail($booking));
            $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->get()
                ->map(fn ($person) => ['id' => (string) $person->id, 'fullName' => $person->full_name ?? $person->user?->name, 'isBookable' => $person->is_bookable]);
            $result[] = ['branchId' => (string) $branch->id, 'payload' => ['bookings' => $bookings, 'staff' => $staff]];
        }

        return ['branches' => $result];
    }

    public function change(Request $r, int $id, AppointmentChangeRequestService $service)
    {
        $r->validate(['requestType' => 'required|in:CANCEL,RESCHEDULE,STAFF_CHANGE', 'proposedStartTime' => 'nullable|date', 'proposedEndTime' => 'nullable|date', 'proposedStaffId' => 'nullable|integer', 'reason' => 'nullable|string|max:1000']);
        $c = $service->create($r, $this->booking($r, $id), ['request_type' => $r->requestType, 'proposed_start_at' => $r->proposedStartTime, 'proposed_end_at' => $r->proposedEndTime, 'proposed_staff_profile_id' => $r->proposedStaffId, 'reason' => $r->reason]);

        return ['id' => (string) $c->id, 'bookingId' => (string) $c->booking_id, 'requestType' => $c->request_type, 'status' => $c->status];
    }

    public function pending(Request $r)
    {
        $ids = $this->query($r)->select('id');

        return AppointmentChangeRequest::whereIn('booking_id', $ids)->where('status', 'PENDING')->get()->map(fn ($c) => ['id' => (string) $c->id, 'bookingId' => (string) $c->booking_id, 'requestType' => $c->request_type, 'reason' => $c->reason, 'status' => $c->status, 'booking' => $this->presenter->detail($c->booking)]);
    }

    public function reviewChange(Request $r, int $id, string $decision, AppointmentChangeRequestService $s)
    {
        $c = AppointmentChangeRequest::findOrFail($id);
        $b = $this->booking($r, $c->booking_id);
        $r->validate(['reviewNote' => 'nullable|string|max:1000']);
        $operating = $this->operating($r, $b->branch);
        $result = $decision === 'approve' ? $s->approve($operating, $b, $c, $r->reviewNote) : $s->reject($operating, $b, $c, $r->reviewNote);

        return ['id' => (string) $result->id, 'status' => $result->status];
    }

    public function move(Request $r, int $id, BookingCommands $s)
    {
        $r->validate(['newStartTime' => 'required|date', 'newEndTime' => 'required|date|after:newStartTime', 'newStaffId' => 'required|integer']);

        return $this->presenter->detail($s->move($r, $this->booking($r, $id), $r->newStartTime, $r->newEndTime, (int) $r->newStaffId));
    }

    public function resize(Request $r, int $id, BookingCommands $s)
    {
        $r->validate(['newEndTime' => 'required|date']);

        return $this->presenter->detail($s->resize($r, $this->booking($r, $id), $r->newEndTime));
    }

    public function assign(Request $r, int $id, BookingCommands $s)
    {
        $r->validate(['staffId' => 'required|integer']);
        $b = $this->booking($r, $id);

        return $this->presenter->detail($s->move($r, $b, $b->appointment_start_at->toIso8601String(), null, (int) $r->staffId));
    }

    public function addItem(Request $r, int $id, BookingCommands $s)
    {
        $data = $r->validate(['serviceId' => 'required|integer', 'variantId' => 'nullable|integer', 'staffId' => 'nullable|integer', 'reason' => 'required|string|max:2000', 'durationMinutes' => 'nullable|integer|between:1,1440', 'price' => 'nullable|numeric|min:0|max:999999999999']);

        return $this->presenter->detail($s->add($r, $this->booking($r, $id), $data));
    }

    public function updateItem(Request $r, int $id, int $itemId, BookingCommands $s)
    {
        $data = $r->validate(['action' => 'required|in:REMOVE,SKIP,REASSIGN,START,COMPLETE,RESIZE,REPRICE', 'reason' => 'required|string|max:2000', 'expectedRevision' => 'required|integer|min:1', 'staffId' => 'required_if:action,REASSIGN|nullable|integer', 'durationMinutes' => 'required_if:action,RESIZE|nullable|integer|between:1,1440', 'price' => 'required_if:action,REPRICE|nullable|numeric|min:0|max:999999999999']);
        $b = $this->booking($r, $id);
        if (in_array($data['action'], ['START', 'COMPLETE'], true)) {
            return $this->presenter->detail(app(BookingItemLifecycle::class)->transition($r, $b, $itemId, $data));
        }
        app(ApiScope::class)->forRoles($r, ['BUSINESS_OWNER', 'RECEPTIONIST'], $b->business_id, $b->branch_id);

        return $this->presenter->detail($s->item($r, $b, $itemId, $data));
    }

    public function actual(Request $r, int $id, int $itemId, BookingCommands $s)
    {
        $data = $r->validate(['actualStartedAt' => 'present|nullable|date', 'actualCompletedAt' => 'present|nullable|date', 'reason' => 'required|string|max:2000', 'expectedRevision' => 'required|integer|min:1', 'actualTimingStatus' => 'nullable|in:KNOWN,UNKNOWN']);

        return $this->presenter->item($s->actual($r, $this->booking($r, $id), $itemId, $data));
    }

    public function actualHistory(Request $r, int $id, int $itemId)
    {
        $b = $this->booking($r, $id);
        app(ApiScope::class)->forRoles($r, ['BUSINESS_OWNER'], $b->business_id, $b->branch_id);
        $b->services()->findOrFail($itemId);

        $history = DB::table('beautybook_booking_edits')->where('booking_id', $id)->where('booking_service_id', $itemId)->where('action', 'ACTUAL_TIME')->orderByDesc('id')->get();

        return ['bookingId' => (string) $id, 'itemId' => (string) $itemId, 'serverNow' => now()->toIso8601String(), 'data' => $history->map(function ($h, $index) use ($history) {
            $before = json_decode($h->before, true);
            $after = json_decode($h->after, true);
            $iso = fn ($value) => $value ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;

            return ['id' => (string) $h->id, 'version' => $history->count() - $index, 'reason' => $h->reason, 'actorId' => (string) $h->actor_user_id, 'actor' => ['fullName' => User::find($h->actor_user_id)?->name], 'oldActualStartedAt' => $iso($before['actual_started_at'] ?? null), 'oldActualCompletedAt' => $iso($before['actual_completed_at'] ?? null), 'oldActualTimingStatus' => $before['actual_timing_status'] ?? 'UNKNOWN', 'oldItemStatus' => $before['status'], 'newItemStatus' => $after['status'], 'actualTimingStatus' => $after['actual_timing_status'], 'actualStartedAt' => $iso($after['actual_started_at'] ?? null), 'actualCompletedAt' => $iso($after['actual_completed_at'] ?? null), 'correctedAt' => $iso($h->created_at)];
        })];
    }

    public function forcePreview(Request $r, int $id)
    {
        $b = $this->booking($r, $id);
        $c = app(BookingLifecycleAccessService::class)->resolve($r, $b);
        abort_unless($c['kind'] === 'ADMIN', 403);
        $refund = app(PaymentService::class)->balance($b);

        return ['bookingId' => (string) $b->id, 'status' => $b->status, 'canCancel' => in_array($b->status, ['PENDING', 'CONFIRMED', 'CHECKED_IN', 'IN_PROGRESS'], true), 'paidAmount' => Money::decimal($refund['net_cents']), 'refundRequired' => $refund['net_cents'] > 0, 'affectedServices' => $b->services()->count()];
    }

    public function violations(Request $r)
    {
        $ids = $this->query($r)->select('id');

        return BookingViolationEvent::whereIn('booking_id', $ids)->latest()->get()->map(fn ($v) => ['id' => (string) $v->id, 'bookingId' => (string) $v->booking_id, 'kind' => $v->kind, 'occurredAt' => $v->occurred_at, 'voidedAt' => $v->voided_at]);
    }

    private function recurringData(Request $r): array
    {
        $r->validate(['branchId' => 'required|integer', 'serviceIds' => 'required_without:comboId|array|min:1|max:20', 'serviceIds.*' => 'integer|min:1', 'comboId' => 'nullable|integer', 'staffId' => 'nullable|integer', 'staffMode' => 'required|in:SAME_STAFF,ANY_AVAILABLE', 'frequency' => 'required|in:WEEKLY,BIWEEKLY,MONTHLY', 'startDate' => 'required|date', 'endDate' => 'nullable|date|after_or_equal:startDate', 'preferredTime' => 'required|date_format:H:i', 'occurrenceCount' => 'required|integer|between:2,52', 'skipConflicts' => 'nullable|boolean', 'violationAcknowledged' => 'nullable|boolean', 'note' => 'nullable|string|max:2000']);
        if ($r->staffMode === 'SAME_STAFF') {
            abort_unless($r->staffId, 422);
        }

        return ['items' => $r->comboId ? null : array_map(fn ($id) => ['branch_service_offering_id' => (int) $id, 'service_variant_id' => null], $r->serviceIds),
            'combo_id' => $r->comboId ? (int) $r->comboId : null, 'staff_mode' => $r->staffMode, 'staff_profile_id' => $r->staffId ? (int) $r->staffId : null, 'frequency' => $r->frequency, 'start_date' => CarbonImmutable::parse($r->startDate)->toDateString(), 'end_date' => $r->endDate ? CarbonImmutable::parse($r->endDate)->toDateString() : null, 'preferred_time' => $r->preferredTime, 'occurrence_count' => (int) $r->occurrenceCount, 'skip_conflicts' => $r->boolean('skipConflicts'), 'violation_acknowledged' => $r->boolean('violationAcknowledged'), 'note' => $r->note];
    }

    private function series(RecurringBookingSeries $s): array
    {
        $s->loadMissing('occurrences.booking', 'branch');

        return ['id' => (string) $s->id, 'branchId' => (string) $s->branch_id, 'branch' => ['id' => (string) $s->branch_id, 'name' => $s->branch->name], 'frequency' => $s->frequency, 'staffMode' => $s->staff_mode, 'status' => $s->status, 'startDate' => $s->start_date?->toDateString(), 'endDate' => $s->end_date?->toDateString(), 'preferredTime' => $s->preferred_time, 'occurrenceCount' => $s->occurrence_count, 'occurrences' => $s->occurrences->map(fn ($o) => ['id' => (string) $o->id, 'bookingId' => $o->booking_id ? (string) $o->booking_id : null, 'scheduledAt' => $o->scheduled_start_at, 'status' => $o->status, 'reason' => $o->reason, 'booking' => $o->booking ? $this->presenter->detail($o->booking) : null])];
    }

    public function recurringPreview(Request $r, RecurringService $s)
    {
        $data = $this->recurringData($r);
        $branch = Branch::findOrFail($r->branchId);
        $p = $s->preview($branch, $s->context($r, $branch), $data);

        return ['availableCount' => $p['available_count'], 'conflictCount' => $p['conflict_count'], 'occurrences' => array_map(fn ($o) => ['index' => $o['sequence'], 'appointmentDate' => CarbonImmutable::parse($o['start_at'], $branch->timezone ?: 'Asia/Ho_Chi_Minh')->toIso8601String(), 'available' => $o['available'], 'staffId' => isset($o['staff_profile_id']) ? (string) $o['staff_profile_id'] : null, 'reason' => $o['reason'] ?? null], $p['occurrences'])];
    }

    public function recurringStore(Request $r, RecurringService $s)
    {
        $data = $this->recurringData($r);
        $r->validate(['customerName' => 'required|string|min:2|max:160', 'customerPhone' => ['required', 'regex:/^(?:0\d{9}|\+84\d{9})$/D'], 'contactConsent' => 'required|accepted']);
        $data['contact'] = ['full_name' => $r->customerName, 'phone' => $r->customerPhone, 'email' => $r->user()->email];
        $data['contact_consent'] = true;
        $key = $r->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 100, 422);
        $branch = Branch::findOrFail($r->branchId);
        $result = $s->create($branch, $s->context($r, $branch), $data + ['idempotency_key' => $key]);

        return $this->series($result['series']) + ['bookings' => $result['series']->occurrences->filter(fn ($o) => $o->booking)->map(fn ($o) => $this->presenter->detail($o->booking))->values(), 'replayed' => $result['replayed']];
    }

    public function recurringMine(Request $r)
    {
        $customer = app(ApiScope::class)->customer($r);

        return RecurringBookingSeries::where('customer_profile_id', $customer->id)->latest()->get()->map(fn ($s) => $this->series($s));
    }

    public function recurringStatus(Request $r, int $id, RecurringService $s)
    {
        $r->validate(['status' => 'required|in:ACTIVE,PAUSED']);

        return $this->series($s->changeStatus($r, RecurringBookingSeries::findOrFail($id), $r->status));
    }

    public function recurringCancel(Request $r, int $id, RecurringService $s, ?int $bookingId = null)
    {
        $series = RecurringBookingSeries::findOrFail($id);
        $occurrence = $bookingId ? $series->occurrences()->where('booking_id', $bookingId)->firstOrFail()->id : null;

        return $this->series($s->cancel($r, $series, $occurrence));
    }
}
