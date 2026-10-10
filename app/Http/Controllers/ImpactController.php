<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingConflictException;
use App\Models\Branch;
use App\Models\OperationalImpactCase;
use App\Models\OperationalImpactItem;
use App\Models\StaffProfile;
use App\Services\Operations\ImpactService;
use DomainException;
use Illuminate\Http\Request;

class ImpactController extends Controller
{
    public function index(Request $request, ImpactService $service)
    {
        $assignment = $service->access($request);
        $query = OperationalImpactCase::with('branch')->withCount(['items', 'items as pending_count' => fn ($q) => $q->where('status', '<>', 'RESOLVED')])
            ->when($assignment->role_name !== 'PLATFORM_ADMIN', fn ($q) => $q->where('business_id', $assignment->business_id));
        $cases = $query->orderBy('deadline_at')->paginate(20);
        return $request->expectsJson() ? response()->json($cases) : view('impact.index', compact('cases'));
    }

    public function show(Request $request, OperationalImpactCase $impact, ImpactService $service)
    {
        $service->access($request, $impact);
        $impact->load(['branch', 'items.booking.services.staffProfile', 'items.booking.customerProfile.user', 'items.booking.contact']);
        $branches = Branch::where('business_id', $impact->business_id)->publicReady()->get();
        $staff = StaffProfile::where('business_id', $impact->business_id)->where('status', 'ACTIVE')->where('is_bookable', true)->orderBy('full_name')->get();
        return $request->expectsJson() ? response()->json($impact) : view('impact.show', compact('impact', 'branches', 'staff'));
    }

    public function resolve(Request $request, OperationalImpactCase $impact, OperationalImpactItem $item, ImpactService $service)
    {
        $service->access($request, $impact);
        abort_unless((int) $item->case_id === (int) $impact->id, 404);
        $data = $this->data($request);
        return $this->run($request, fn () => $service->resolve($request, $impact, $item, $data), 'Đã xử lý lịch hẹn bị ảnh hưởng.');
    }

    public function batch(Request $request, OperationalImpactCase $impact, ImpactService $service)
    {
        $service->access($request, $impact);
        $data = $this->data($request) + $request->validate(['item_ids' => ['required', 'array', 'min:1', 'max:100'], 'item_ids.*' => ['required', 'integer', 'min:1', 'distinct']]);
        return $this->run($request, fn () => $service->batch($request, $impact, $data), 'Đã xử lý các lịch hẹn đã chọn.');
    }

    public function complete(Request $request, OperationalImpactCase $impact, ImpactService $service)
    {
        $service->access($request, $impact);
        return $this->run($request, fn () => $service->complete($request, $impact), 'Đã hoàn tất hồ sơ ảnh hưởng.');
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'resolution' => ['required', 'in:REASSIGN,RESCHEDULE,TRANSFER_BRANCH,CANCEL_REFUND,APPROVED_EXCEPTION'],
            'reason' => ['required', 'string', 'max:2000'],
            'replacement_staff_id' => ['nullable', 'required_if:resolution,REASSIGN,TRANSFER_BRANCH', 'integer', 'min:1'],
            'replacement_branch_id' => ['nullable', 'required_if:resolution,TRANSFER_BRANCH', 'integer', 'min:1'],
            'proposed_start_at' => ['nullable', 'required_if:resolution,RESCHEDULE', 'date_format:Y-m-d\TH:i'],
        ]);
    }

    private function run(Request $request, callable $action, string $message)
    {
        try {
            $result = $action();
            return $request->expectsJson() ? response()->json($result) : back()->with('status', $message);
        } catch (DomainException $exception) {
            return $request->expectsJson() ? response()->json(['message' => $exception->getMessage()], $exception instanceof BookingConflictException ? 409 : 422)
                : back()->withErrors(['impact' => $exception->getMessage()])->withInput();
        }
    }
}
