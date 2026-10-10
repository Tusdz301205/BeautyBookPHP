<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingConflictException;
use App\Models\Branch;
use App\Models\Business;
use App\Models\LoyaltyAccount;
use App\Models\WaitlistEntry;
use App\Services\Booking\BookingAccessService;
use App\Services\Identity\SessionContext;
use App\Services\Operations\LoyaltyService;
use App\Services\Operations\WaitlistService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function mine(Request $request, WaitlistService $waitlist)
    {
        $customer = $request->user()->customerProfile;
        abort_unless($customer, 403);
        $waitlist->expireOffers();

        return view('operations.waitlist', ['entries' => WaitlistEntry::where('customer_profile_id', $customer->id)->with(['branch', 'offering.service'])->latest()->paginate(15), 'branches' => Branch::publicReady()->with(['offerings.service', 'staffProfiles.offerings'])->get(), 'branch' => null]);
    }

    public function branch(Request $request, Branch $branch, BookingAccessService $access, WaitlistService $waitlist)
    {
        $context = $access->resolve($request, $branch);
        abort_unless(in_array($context['kind'], ['OWNER', 'RECEPTIONIST'], true), 403);
        $waitlist->expireOffers();

        return view('operations.waitlist', ['entries' => WaitlistEntry::where('branch_id', $branch->id)->with(['branch', 'offering.service'])->orderBy('status')->orderBy('created_at')->paginate(15), 'branches' => collect(), 'branch' => $branch->load('staffProfiles.offerings')]);
    }

    public function join(Request $request, Branch $branch, BookingAccessService $access, WaitlistService $waitlist)
    {
        $context = $access->resolve($request, $branch);
        $data = $request->validate(['branch_service_offering_id' => ['required', 'integer'], 'staff_profile_id' => ['nullable', 'integer'], 'window_start' => ['required', 'date'], 'window_end' => ['required', 'date', 'after:window_start']]);

        return $this->run($request, fn () => $waitlist->join($branch, $context, $data), 'Đã vào danh sách chờ.', 201);
    }

    public function offer(Request $request, WaitlistEntry $entry, BookingAccessService $access, WaitlistService $waitlist)
    {
        $context = $access->resolve($request, Branch::findOrFail($entry->branch_id));
        $data = $request->validate(['start_at' => ['required', 'date_format:Y-m-d\TH:i'], 'staff_profile_id' => ['required', 'integer'], 'ttl_minutes' => ['sometimes', 'integer', 'between:3,60']]);

        return $this->run($request, fn () => $waitlist->offer($entry, $context, $data), 'Đã gửi đề nghị cho khách.');
    }

    public function accept(Request $request, WaitlistEntry $entry, BookingAccessService $access, WaitlistService $waitlist)
    {
        $context = $access->resolve($request, Branch::findOrFail($entry->branch_id));
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        return $this->run($request, fn () => $waitlist->accept($entry, $context, $data['token']), 'Đã nhận đề nghị và tạo lịch hẹn.');
    }

    public function cancel(Request $request, WaitlistEntry $entry, WaitlistService $waitlist)
    {
        $customer = $request->user()->customerProfile;
        abort_unless($customer, 403);

        return $this->run($request, fn () => $waitlist->cancel($entry, $customer->id), 'Đã hủy yêu cầu chờ.');
    }

    public function loyalty(Request $request, LoyaltyService $loyalty)
    {
        $customer = $request->user()->customerProfile;
        abort_unless($customer, 403);
        $accounts = $loyalty->listCustomer($customer->id);

        return $request->expectsJson() ? response()->json($accounts) : view('operations.loyalty', ['accounts' => $accounts, 'business' => null, 'rules' => collect()]);
    }

    public function loyaltyBusiness(Request $request, Business $business, SessionContext $sessions)
    {
        $this->owner($request, $business, $sessions);

        return view('operations.loyalty', ['business' => $business->load('branches'), 'accounts' => LoyaltyAccount::where('business_id', $business->id)->get(), 'rules' => DB::table('loyalty_rules')->where('business_id', $business->id)->orderByDesc('version')->get()]);
    }

    public function configureLoyalty(Request $request, Business $business, SessionContext $sessions, LoyaltyService $loyalty)
    {
        $this->owner($request, $business, $sessions);
        $data = $request->validate(['branch_id' => ['nullable', 'integer'], 'earn_points_per_amount' => ['required', 'integer', 'between:1,1000000'], 'earn_amount_unit' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'], 'redemption_value_per_point' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999.99'], 'expires_after_days' => ['nullable', 'integer', 'between:1,3650'], 'valid_from' => ['nullable', 'date']]);
        if (($data['valid_from'] ?? null) === null) {
            unset($data['valid_from']);
        }
        $data['earn_points_per_amount'] = (int) $data['earn_points_per_amount'];

        return $this->run($request, fn () => $loyalty->configure($business, $request->user()->id, $data), 'Đã lưu phiên bản quy tắc tích điểm.', 201);
    }

    private function owner(Request $request, Business $business, SessionContext $sessions): void
    {
        $assignment = $sessions->current($request);
        abort_unless($assignment && $assignment->role_name === 'BUSINESS_OWNER' && (int) $assignment->business_id === (int) $business->id, 403);
    }

    private function run(Request $request, callable $action, string $message, int $status = 200)
    {
        try {
            $result = $action();

            return $request->expectsJson() ? response()->json($result ?? ['message' => $message], $status) : back()->with('status', $message);
        } catch (DomainException $e) {
            return $request->expectsJson() ? response()->json(['message' => $e->getMessage()], $e instanceof BookingConflictException ? 409 : 422) : back()->withErrors(['operation' => $e->getMessage()])->withInput();
        }
    }
}
