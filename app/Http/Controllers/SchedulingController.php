<?php

namespace App\Http\Controllers;

use App\Enums\AccountKind;
use App\Enums\RoleName;
use App\Exceptions\BookingConflictException;
use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\Combo;
use App\Models\CustomerProfile;
use App\Models\RecurringBookingSeries;
use App\Models\StaffProfile;
use App\Services\Identity\SessionContext;
use App\Services\Scheduling\ComboService;
use App\Services\Scheduling\RecurringService;
use App\Services\Scheduling\CreateComboBookingService;
use App\Services\Booking\BookingAccessService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SchedulingController extends Controller
{
    public function comboBookingCreate(Request $request, Branch $branch, Combo $combo, BookingAccessService $access, ComboService $service)
    {
        $context = $access->resolve($request, $branch);
        $combo = $service->resolveForBooking($branch, $combo->id);
        $staff = StaffProfile::where('branch_id', $branch->id)->where('business_id', $branch->business_id)->where('status', 'ACTIVE')->where('is_bookable', true)
            ->when($context['kind'] === 'CUSTOMER', fn ($q) => $q->where('public_visible', true))->with('offerings')->get();
        $combo->load('items.offering.variants');
        return view('scheduling.combo-booking', ['branch' => $branch, 'combo' => $combo, 'staff' => $staff, 'context' => $context, 'key' => (string) Str::uuid()]);
    }

    public function comboBookingStore(Request $request, Branch $branch, Combo $combo, BookingAccessService $access, CreateComboBookingService $service)
    {
        $context = $access->resolve($request, $branch);
        abort_unless((int) $combo->branch_id === (int) $branch->id, 404);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key') ?: $request->input('idempotency_key')]);
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'], 'start_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'staff_profile_id' => ['nullable', 'integer', 'min:1'], 'staff_assignments' => ['nullable', 'array', 'max:20'],
            'staff_assignments.*' => ['required', 'integer', 'min:1'], 'variant_assignments' => ['nullable', 'array', 'max:20'],
            'variant_assignments.*' => ['nullable', 'integer', 'min:1'], 'note' => ['nullable', 'string', 'max:2000'],
            'voucher_code' => ['nullable', 'string', 'max:64'], 'violation_acknowledged' => ['sometimes', 'boolean'],
            'loyalty_points' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'source' => ['nullable', 'in:WALK_IN,PHONE'], 'contact' => ['nullable', 'array:full_name,phone,email'],
            'contact.full_name' => ['nullable', 'string', 'max:255'], 'contact.phone' => ['nullable', 'string', 'max:30'], 'contact.email' => ['nullable', 'email', 'max:255'],
        ]);
        $data['staff_profile_id'] = isset($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null;
        $data['loyalty_points'] = (int) ($data['loyalty_points'] ?? 0);
        foreach (['staff_assignments', 'variant_assignments'] as $field) {
            $data[$field] = array_map(fn ($value) => $value !== null ? (int) $value : null, $data[$field] ?? []);
        }
        $data['violation_acknowledged'] = (bool) ($data['violation_acknowledged'] ?? false);
        $data['voucher_code'] = isset($data['voucher_code']) ? strtoupper(trim($data['voucher_code'])) : null;
        return $this->run($request, function () use ($request, $branch, $combo, $context, $data, $service) {
            $result = $service->create($branch, $combo, $context, $data);
            return $request->expectsJson() ? $result : redirect()->route('bookings.index', ['booking' => $result['booking']->id])->with('status', 'Đã đặt combo.');
        }, 'Đã đặt combo.', 201);
    }

    public function publicCombos(Request $request, ComboService $service)
    {
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = $service->publicQuery()->when($data['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', '%'.$search.'%'));
        $combos = $query->latest()->paginate(20)->withQueryString();
        return $request->expectsJson() ? response()->json($combos) : view('scheduling.combos-public', compact('combos'));
    }

    public function combos(Request $request, Branch $branch)
    {
        $this->authorizeOwner($request, $branch);
        $combos = Combo::query()->where('branch_id', $branch->id)->with('items.offering.service')->latest()->paginate(20);
        $offerings = BranchServiceOffering::query()->where('branch_id', $branch->id)->where('is_active', true)->with('service')->get();
        return $request->expectsJson() ? response()->json($combos) : view('scheduling.combos', compact('branch', 'combos', 'offerings'));
    }

    public function storeCombo(Request $request, Branch $branch, ComboService $service)
    {
        $this->authorizeOwner($request, $branch);
        $data = $this->comboData($request);
        return $this->run($request, fn () => $service->save($branch, $data), 'Đã tạo combo.', 201);
    }

    public function updateCombo(Request $request, Branch $branch, Combo $combo, ComboService $service)
    {
        $this->authorizeOwner($request, $branch);
        abort_unless((int) $combo->branch_id === (int) $branch->id, 404);
        $data = $this->comboData($request);
        return $this->run($request, fn () => $service->save($branch, $data, $combo), 'Đã cập nhật combo.');
    }

    public function archiveCombo(Request $request, Branch $branch, Combo $combo)
    {
        $this->authorizeOwner($request, $branch);
        abort_unless((int) $combo->branch_id === (int) $branch->id, 404);
        return $this->run($request, function () use ($combo) {
            $combo->update(['status' => 'INACTIVE']);
            $combo->delete();
            return ['ok' => true];
        }, 'Đã lưu trữ combo.');
    }

    private function authorizeOwner(Request $request, Branch $branch): void
    {
        abort_unless($request->user()?->account_kind === AccountKind::Operational, 403);
        $assignment = app(SessionContext::class)->current($request);
        abort_unless($assignment && $assignment->role_name === RoleName::BusinessOwner->value
            && (int) $assignment->business_id === (int) $branch->business_id && $assignment->branch_id === null, 403);
    }

    private function comboData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'combo_price' => ['required', 'numeric', 'min:0', 'max:999999999999', 'decimal:0,2'],
            'pricing_mode' => ['sometimes', 'in:FIXED_PRICE'],
            'staff_assignment_mode' => ['sometimes', 'in:SINGLE_PROVIDER,PER_SERVICE_PROVIDER'],
            'status' => ['required', 'in:ACTIVE,INACTIVE,PAUSED,EXPIRED'],
            'valid_from' => ['nullable', 'date'], 'valid_to' => ['nullable', 'date', ...($request->filled('valid_from') ? ['after:valid_from'] : [])],
            'max_usage' => ['nullable', 'integer', 'min:1'],
            'items' => ['required', 'array', 'min:2', 'max:20'],
            'items.*.branch_service_offering_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10'],
            'items.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:19', 'distinct'],
            'items.*.transition_minutes' => ['sometimes', 'integer', 'min:0', 'max:120'],
        ]);
    }

    public function recurringIndex(Request $request)
    {
        abort_unless($request->user()?->account_kind === AccountKind::Customer, 403);
        $customer = CustomerProfile::where('user_id', $request->user()->id)->firstOrFail();
        $series = RecurringBookingSeries::where('customer_profile_id', $customer->id)->with(['branch', 'occurrences.booking'])->latest()->paginate(20);
        return $request->expectsJson() ? response()->json($series) : view('scheduling.recurring-index', compact('series'));
    }

    public function recurringCreate(Request $request, Branch $branch, RecurringService $service)
    {
        $service->context($request, $branch);
        $offerings = BranchServiceOffering::where('branch_id', $branch->id)->where('is_active', true)->where('bookable', true)->with(['service','variants'])->get();
        $staff = StaffProfile::where('branch_id', $branch->id)->where('status', 'ACTIVE')->where('public_visible', true)->where('is_bookable', true)->with('offerings')->get();
        $combos = app(ComboService::class)->publicQuery($branch)->with('items')->get();
        return view('scheduling.recurring-create', compact('branch', 'offerings', 'staff', 'combos'));
    }

    public function recurringPreview(Request $request, Branch $branch, RecurringService $service)
    {
        $context = $service->context($request, $branch);
        $data = $this->recurringData($request);
        return $this->run($request, function () use ($request, $branch, $context, $data, $service) {
            $preview = $service->preview($branch, $context, $data);
            return $request->expectsJson() ? $preview : view('scheduling.recurring-preview', ['branch' => $branch, 'data' => $data, 'preview' => $preview, 'key' => (string) Str::uuid()]);
        }, 'Đã kiểm tra các kỳ.');
    }

    public function recurringStore(Request $request, Branch $branch, RecurringService $service)
    {
        $context = $service->context($request, $branch);
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key') ?: $request->input('idempotency_key')]);
        $data = $this->recurringData($request) + $request->validate(['idempotency_key' => ['required', 'string', 'min:8', 'max:100']]);
        return $this->run($request, function () use ($request, $branch, $context, $data, $service) {
            $result = $service->create($branch, $context, $data);
            return $request->expectsJson() ? $result : redirect()->route('recurring.index')->with('status', 'Đã tạo chuỗi lịch.');
        }, 'Đã tạo chuỗi lịch.', 201);
    }

    public function recurringStatus(Request $request, RecurringBookingSeries $series, RecurringService $service)
    {
        $data = $request->validate(['status' => ['required', 'in:ACTIVE,PAUSED']]);
        return $this->run($request, fn () => $service->changeStatus($request, $series, $data['status']), 'Đã đổi trạng thái chuỗi lịch.');
    }

    public function recurringCancel(Request $request, RecurringBookingSeries $series, RecurringService $service)
    {
        return $this->run($request, fn () => $service->cancel($request, $series), 'Đã hủy các kỳ còn có thể hủy.');
    }

    public function occurrenceCancel(Request $request, RecurringBookingSeries $series, int $occurrence, RecurringService $service)
    {
        return $this->run($request, fn () => $service->cancel($request, $series, $occurrence), 'Đã hủy kỳ lịch.');
    }

    private function recurringData(Request $request): array
    {
        $data = $request->validate([
            'branch_service_offering_id' => ['nullable', 'required_without:combo_id', 'prohibits:combo_id', 'integer', 'min:1'],
            'combo_id' => ['nullable', 'required_without:branch_service_offering_id', 'prohibits:branch_service_offering_id,service_variant_id', 'integer', 'min:1'],
            'service_variant_id' => ['nullable', 'integer', 'min:1'],
            'staff_mode' => ['required', 'in:SAME_STAFF,ANY_AVAILABLE'],
            'staff_profile_id' => ['nullable', 'required_if:staff_mode,SAME_STAFF', 'integer', 'min:1'],
            'frequency' => ['required', 'in:WEEKLY,BIWEEKLY,MONTHLY'], 'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'preferred_time' => ['required', 'date_format:H:i'],
            'occurrence_count' => ['required', 'integer', 'min:2', 'max:52'], 'skip_conflicts' => ['sometimes', 'boolean'],
            'violation_acknowledged' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:2000'],
        ]);
        foreach (['branch_service_offering_id', 'combo_id', 'service_variant_id', 'staff_profile_id', 'occurrence_count'] as $field) {
            $data[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }
        foreach (['skip_conflicts', 'violation_acknowledged'] as $field) {
            $data[$field] = (bool) ($data[$field] ?? false);
        }
        $data['end_date'] ??= null;
        $data['note'] ??= null;
        if ($data['staff_mode'] === 'ANY_AVAILABLE') {
            $data['staff_profile_id'] = null;
        }
        return $data;
    }

    private function run(Request $request, callable $action, string $message, int $status = 200)
    {
        try {
            $result = $action();
            if ($result instanceof \Symfony\Component\HttpFoundation\Response || $result instanceof \Illuminate\Contracts\View\View) {
                return $result;
            }
            return $request->expectsJson() ? response()->json($result, $status) : back()->with('status', $message);
        } catch (DomainException|BookingConflictException $exception) {
            $status = $exception instanceof BookingConflictException ? 409 : 422;
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], $status) : back()->withErrors(['scheduling' => $exception->getMessage()])->withInput();
        }
    }
}
