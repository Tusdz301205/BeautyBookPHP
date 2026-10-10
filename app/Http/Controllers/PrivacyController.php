<?php

namespace App\Http\Controllers;

use App\Services\Privacy\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrivacyController extends Controller
{
    public function index(Request $request, PrivacyService $privacy)
    {
        $customer = $privacy->customer($request);
        return view('privacy.index', [
            'requests' => DB::table('data_subject_requests')->where('customer_profile_id', $customer->id)->orderByDesc('id')->paginate(20),
            'preferences' => DB::table('marketing_preferences')->where('customer_profile_id', $customer->id)->first(),
        ]);
    }

    public function store(Request $request, PrivacyService $privacy)
    {
        $data = $request->validate(['type' => ['required', 'in:EXPORT,RECTIFICATION,ERASURE,RESTRICT_PROCESSING,OBJECT_PROCESSING,DELETE_ACCOUNT'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $row = $privacy->requestData($request, $data);
        return $request->expectsJson() ? response()->json(['id' => $row->id, 'status' => $row->status], 201) : back()->with('status', 'Đã tiếp nhận yêu cầu dữ liệu.');
    }

    public function preferences(Request $request, PrivacyService $privacy)
    {
        $customer = $privacy->customer($request);
        $data = $request->validate(array_fill_keys(['email_marketing', 'sms_marketing', 'push_marketing', 'personalized_promotions'], ['required', 'boolean']));
        DB::transaction(function () use ($request, $customer, $data) {
            DB::table('marketing_preferences')->updateOrInsert(['customer_profile_id' => $customer->id], [...$data, 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['user_id' => $request->user()->id, 'action' => 'UPDATE', 'entity_type' => 'MarketingPreference', 'entity_id' => (string)$customer->id, 'created_at' => now()]);
        });
        return back()->with('status', 'Đã cập nhật lựa chọn tiếp thị.');
    }

    public function export(Request $request, PrivacyService $privacy)
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:200']]);
        $package = $privacy->export($request, $data['current_password']);
        return $request->expectsJson() ? response()->json($package, 201)->header('Cache-Control', 'no-store') : response()->view('privacy.export', ['package' => $package])->header('Cache-Control', 'no-store');
    }

    public function download(Request $request, int $package, PrivacyService $privacy)
    {
        $data = $request->validate(['download_token' => ['required', 'string', 'size:64']]);
        return response($privacy->download($request, $package, $data['download_token']))
            ->header('Content-Type', 'application/json; charset=utf-8')->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="beautybook-data-'.$package.'.json"');
    }
}
