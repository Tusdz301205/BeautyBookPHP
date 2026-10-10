<?php

namespace App\Http\Controllers;

use App\Models\BranchServiceOffering;
use App\Models\Business;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Services\Promotions\PromotionManagementService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $business = $this->business($request);
        $this->authorizeScope($request, $business);
        $type = $request->query('type') === 'voucher' ? 'voucher' : 'promotion';
        $class = $type === 'voucher' ? Voucher::class : Promotion::class;
        $query = $class::where('business_id', $business?->id)->where('created_by_platform', $business === null);
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search').'%');
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return view('promotions.index', ['rules' => $query->latest()->paginate(12)->withQueryString(), 'type' => $type, 'business' => $business, 'branches' => $business?->branches ?? collect(), 'offerings' => $business ? BranchServiceOffering::where('business_id', $business->id)->with('service')->get() : collect()]);
    }

    public function store(Request $request, PromotionManagementService $service)
    {
        return $this->save($request, $service, $this->business($request));
    }

    public function update(Request $request, PromotionManagementService $service)
    {
        return $this->save($request, $service, $this->business($request), (int) $request->route('id'), $request->route('type'));
    }

    private function save(Request $request, PromotionManagementService $service, ?Business $business, ?int $id = null, ?string $type = null)
    {
        $this->authorizeScope($request, $business);
        $type ??= $request->input('type');
        abort_unless(in_array($type, ['promotion', 'voucher'], true), 404);
        $voucher = $type === 'voucher';
        $required = $id ? 'sometimes' : 'required';
        $rules = ['name' => [$required, 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:3000'], 'discount_type' => [$required, Rule::in(['PERCENTAGE', 'FIXED_AMOUNT'])], 'discount_value' => [$required, 'decimal:0,2', 'gt:0', 'max:9999999999.99'], 'start_date' => [$required, 'date'], 'end_date' => [$required, 'date'], 'status' => ['sometimes', Rule::in($voucher ? ['ACTIVE', 'EXPIRED', 'REVOKED'] : ['ACTIVE', 'INACTIVE', 'EXPIRED'])], 'audience' => ['sometimes', Rule::in(['ALL', 'NEW_CUSTOMER', 'RETURNING_CUSTOMER', 'BIRTHDAY', 'VIP', 'SELECTED'])], 'total_quantity' => [$voucher && ! $id ? 'required' : 'nullable', 'integer', 'min:1'], 'max_usage_per_customer' => ['sometimes', 'integer', 'min:1'], 'branch_ids' => ['sometimes', 'array'], 'branch_ids.*' => ['integer'], 'service_ids' => ['sometimes', 'array'], 'service_ids.*' => ['integer'], 'combo_ids' => ['sometimes', 'array'], 'combo_ids.*' => ['integer']];
        if ($voucher) {
            $rules += ['code' => [$required, 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('vouchers', 'code')->ignore($id)], 'min_order_value' => ['sometimes', 'decimal:0,2', 'min:0'], 'max_discount' => ['nullable', 'decimal:0,2', 'gt:0'], 'auto_issue' => ['sometimes', 'boolean']];
        } else {
            $rules += ['auto_apply' => ['sometimes', 'boolean'], 'stacking_allowed' => ['sometimes', 'boolean']];
        }
        $data = $request->validate($rules);
        if ($voucher && array_key_exists('total_quantity', $data) && $data['total_quantity'] === null) {
            return $request->expectsJson() ? response()->json(['message' => 'Voucher cần quota tổng dương.'], 422) : back()->withErrors(['total_quantity' => 'Voucher cần quota tổng dương.']);
        }
        try {
            $rule = $service->save($business, $type, $data, $id);

            return $request->expectsJson() ? response()->json($rule, $id ? 200 : 201) : back()->with('status', 'Đã lưu ưu đãi.');
        } catch (DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], 422) : back()->withErrors(['promotion' => $e->getMessage()])->withInput();
        }
    }

    public function destroy(Request $request)
    {
        $business = $this->business($request);
        $type = $request->route('type');
        $id = (int) $request->route('id');
        $this->authorizeScope($request, $business);
        abort_unless(in_array($type, ['voucher', 'promotion'], true), 404);
        $class = $type === 'voucher' ? Voucher::class : Promotion::class;
        DB::transaction(function () use ($class, $id, $business) {
            $rule = $class::whereKey($id)->where('business_id', $business?->id)->where('created_by_platform', $business === null)->lockForUpdate()->firstOrFail();
            $rule->status = 'INACTIVE';
            $rule->version++;
            $rule->save();
            $rule->delete();
        });

        return back()->with('status', 'Đã lưu trữ ưu đãi, giữ nguyên lịch sử.');
    }

    public function mine(Request $request, PromotionManagementService $service)
    {
        $customer = $request->user()->customerProfile;
        abort_unless($customer, 403);
        $service->issueAutomatic($customer);

        return view('promotions.mine', ['vouchers' => Voucher::whereIn('id', DB::table('customer_vouchers')->where('customer_profile_id', $customer->id)->pluck('voucher_id'))->paginate(12)]);
    }

    public function claim(Request $request, Voucher $voucher, PromotionManagementService $service)
    {
        $customer = $request->user()->customerProfile;
        abort_unless($customer, 403);
        try {
            $service->grant($voucher, $customer->id);
        } catch (DomainException $e) {
            return back()->withErrors(['voucher' => $e->getMessage()]);
        }

        return back()->with('status', 'Đã nhận voucher.');
    }

    private function authorizeScope(Request $request, ?Business $business): void
    {
        if ($business) {
            Gate::authorize('manage', $business);
            $assignmentId = $request->session()->get('workspace_assignment_id');
            abort_unless($assignmentId && $request->user()->roleAssignments()->whereKey($assignmentId)->where('role_name', 'BUSINESS_OWNER')->where('business_id', $business->id)->exists(), 403);
        } else {
            $assignmentId = $request->session()->get('workspace_assignment_id');
            abort_unless($assignmentId && $request->user()->roleAssignments()->whereKey($assignmentId)->where('role_name', 'PLATFORM_ADMIN')->whereNull('business_id')->exists(), 403);
        }
    }

    private function business(Request $request): ?Business
    {
        return $request->route('business') ? Business::findOrFail($request->route('business')) : null;
    }
}
