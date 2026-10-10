<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\Identity\SessionContext;
use App\Services\Ownership\OwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OwnershipController extends Controller
{
    public function index(Request $request)
    {
        $assignment = app(SessionContext::class)->current($request);
        $query = DB::table('ownership_transfers')->join('businesses', 'businesses.id', '=', 'ownership_transfers.business_id')
            ->select('ownership_transfers.*', 'businesses.name as business_name')->orderByDesc('ownership_transfers.id');
        $isAdmin = $assignment?->role_name === 'PLATFORM_ADMIN';
        if (!$isAdmin) $query->where(fn ($q) => $q->where('old_owner_user_id', $request->user()->id)->orWhere('new_owner_user_id', $request->user()->id));
        $businesses = $isAdmin ? Business::query()->get() : Business::whereHas('ownerProfile', fn ($q) => $q->where('user_id', $request->user()->id))->get();
        $versions = DB::table('business_identity_versions')->whereIn('business_id', $businesses->pluck('id'))
            ->orderByDesc('id')->get(['id', 'business_id', 'type', 'version', 'display_label', 'verification_status', 'is_active']);
        return view('ownership.index', ['transfers' => $query->paginate(20), 'businesses' => $businesses, 'versions' => $versions, 'isAdmin' => $isAdmin]);
    }

    public function store(Request $request, Business $business, OwnershipService $ownership)
    {
        $data = $request->validate(['new_owner_email' => ['required', 'email'], 'effective_at' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000'], 'settlement_agreement' => ['nullable', 'array'], 'settlement_agreement.note' => ['nullable', 'string', 'max:4000']]);
        $data['effective_at'] = \Carbon\CarbonImmutable::parse($data['effective_at'], 'Asia/Ho_Chi_Minh')->utc();
        $id = $ownership->create($request, $business, $data);
        return $request->expectsJson() ? response()->json(['id' => $id], 201) : back()->with('status', 'Đã gửi yêu cầu cho chủ mới xác nhận.');
    }

    public function action(Request $request, int $transfer, string $action, OwnershipService $ownership)
    {
        $data = $request->validate([
            'reason' => [in_array($action, ['review', 'cancel', 'resubmit']) ? 'required' : 'nullable', 'string', 'max:2000'],
            'decision' => [$action === 'review' ? 'required' : 'nullable', 'in:APPROVE,REJECT,NEED_MORE_INFO'],
            'settlement_agreement' => ['nullable', 'array'], 'settlement_agreement.note' => ['nullable', 'string', 'max:4000'],
        ]);
        $ownership->action($request, $transfer, $action, $data);
        return $request->expectsJson() ? response()->json(['status' => DB::table('ownership_transfers')->where('id', $transfer)->value('status')]) : back()->with('status', 'Đã cập nhật yêu cầu chuyển chủ.');
    }

    public function identity(Request $request, Business $business, OwnershipService $ownership)
    {
        $data = $request->validate([
            'type' => ['required', 'in:LEGAL_ENTITY,PAYOUT_ACCOUNT'],
            'legal_name' => ['required_if:type,LEGAL_ENTITY', 'nullable', 'string', 'max:255'], 'tax_code' => ['nullable', 'string', 'max:80'],
            'registration_number' => ['nullable', 'string', 'max:100'], 'representative_name' => ['nullable', 'string', 'max:255'],
            'bank_name' => ['required_if:type,PAYOUT_ACCOUNT', 'nullable', 'string', 'max:120'],
            'account_holder' => ['required_if:type,PAYOUT_ACCOUNT', 'nullable', 'string', 'max:255'],
            'account_number' => ['required_if:type,PAYOUT_ACCOUNT', 'nullable', 'string', 'max:100'],
        ]);
        $id = $ownership->identity($request, $business, $data);
        return $request->expectsJson() ? response()->json(['id' => $id], 201) : back()->with('status', 'Đã tạo phiên bản hồ sơ chờ xác minh.');
    }

    public function verify(Request $request, int $version, OwnershipService $ownership)
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:2000']]);
        $ownership->verify($request, $version, $data);
        return $request->expectsJson() ? response()->json(['verified' => true]) : back()->with('status', 'Đã ghi kết quả xác minh.');
    }
}
