<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Business;
use App\Services\Identity\SessionContext;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformController extends Controller
{
    public function __construct(private SessionContext $contexts) {}

    private function admin(Request $request): void
    {
        abort_unless($this->contexts->current($request)?->role_name === 'PLATFORM_ADMIN', 403);
    }

    public function settings(Request $request, PlatformSettingsService $settings)
    {
        $this->admin($request);
        return view('platform.settings', ['settings' => $settings->get()]);
    }

    public function updateSettings(Request $request, PlatformSettingsService $settings)
    {
        $this->admin($request);
        $data = $request->validate(['settings' => ['required', 'array']]);
        $updated = $settings->update($data['settings'], $request->user()->id);
        return $request->expectsJson() ? response()->json(['settings' => $updated]) : back()->with('status', 'Đã cập nhật cài đặt.');
    }

    public function directory(Request $request)
    {
        $this->admin($request);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', 'in:DRAFT,PENDING_REVIEW,NEED_MORE_INFO,APPROVED,ACTIVE,SUSPENDED,REJECTED']]);
        $query = Business::query()->withCount('branches');
        if (! empty($filters['q'])) $query->where('name', 'like', '%'.$filters['q'].'%');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        return view('platform.directory', ['businesses' => $query->orderBy('name')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function report(Request $request)
    {
        $assignment = $this->contexts->current($request);
        abort_unless($assignment && in_array($assignment->role_name, ['PLATFORM_ADMIN', 'BUSINESS_OWNER'], true), 403);
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $query = Booking::query();
        if ($assignment->role_name === 'BUSINESS_OWNER') $query->where('business_id', $assignment->business_id);
        if (! empty($data['from'])) $query->where('appointment_start_at', '>=', \Carbon\CarbonImmutable::parse($data['from'], 'Asia/Ho_Chi_Minh')->utc());
        if (! empty($data['to'])) $query->where('appointment_start_at', '<', \Carbon\CarbonImmutable::parse($data['to'], 'Asia/Ho_Chi_Minh')->addDay()->utc());
        $summary = (clone $query)->selectRaw('status, COUNT(*) as total, SUM(total_amount) as booking_value')->groupBy('status')->get();
        return view('platform.report', ['summary' => $summary, 'bookings' => $query->with('branch')->latest('appointment_start_at')->paginate(20)->withQueryString(), 'filters' => $data]);
    }

    public function audit(Request $request)
    {
        $assignment = $this->contexts->current($request);
        abort_unless($assignment && in_array($assignment->role_name, ['PLATFORM_ADMIN', 'BUSINESS_OWNER'], true), 403);
        $query = DB::table('audit_logs')->orderByDesc('id');
        if ($assignment->role_name === 'BUSINESS_OWNER') $query->where('user_id', $request->user()->id);
        return view('platform.audit', ['logs' => $query->paginate(30)]);
    }
}
