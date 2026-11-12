<?php

namespace App\Http\Controllers\Beautybook;

use App\Http\Controllers\Controller;
use App\Services\Payout\WithdrawalService;
use App\Services\Wallet\LiveWalletPolicy;
use App\Services\Wallet\WalletService;
use Illuminate\Http\Request;

/** wallet.controller.ts: authority lives in the services (customer, business owner or platform admin per action). */
class WalletController extends Controller
{
    public function __construct(private WalletService $wallet, private WithdrawalService $withdrawals) {}

    private function page(Request $r): int
    {
        $v = $r->query('page', 1);
        if (! is_numeric($v) || (int) $v != $v || $v < 1 || $v > 100000) abort(400, 'Invalid page');

        return (int) $v;
    }

    private function body(Request $r): array
    {
        $body = $r->json()->all();
        if (! is_array($body) || ($body !== [] && array_is_list($body))) abort(400, 'Invalid body');

        return $body;
    }

    public function mine(Request $r)
    {
        return $this->wallet->mine($r, $this->page($r), LiveWalletPolicy::scope($r->query('scope')));
    }

    /** The deposit the customer would hold for a booking total; the booking request must echo `quote` back. */
    public function depositTerms(Request $r)
    {
        $branchId = $r->query('branchId');
        $total = $r->query('total');
        if (! ctype_digit((string) $branchId) || ! ctype_digit((string) $total) || strlen((string) $total) > 12) abort(422, 'Invalid deposit request');
        abort_unless(\App\Models\Branch::whereKey((int) $branchId)->exists(), 404, 'Branch not found');
        if (! LiveWalletPolicy::walletMode()) {
            // Direct mode: the customer pays the business's own account after booking; the quote names that account.
            $branch = \App\Models\Branch::findOrFail((int) $branchId);
            $direct = \App\Services\Payments\DirectDeposit::terms((int) $branch->business_id, (int) $branch->id, (int) $total, \App\Services\Booking\BranchBookingRules::cancellationHours((int) $branch->id));
            unset($direct['connectionId']);

            return ['walletMode' => false, 'depositMode' => $direct ? 'DIRECT' : null, 'terms' => $direct];
        }

        return ['walletMode' => true, 'terms' => \App\Services\Wallet\WalletCore::terms((int) $branchId, (int) $total, \App\Services\Booking\BranchBookingRules::cancellationHours((int) $branchId))];
    }

    public function topUpStatus(Request $r, int $id)
    {
        return $this->wallet->topUpStatus($r, $id);
    }

    public function topUp(Request $r)
    {
        return $this->wallet->topUp($r, $r->header('Idempotency-Key'), $this->body($r));
    }

    public function business(Request $r, int $businessId)
    {
        return $this->wallet->businessSummary($r, $businessId, LiveWalletPolicy::scope($r->query('scope')));
    }

    public function addBank(Request $r, int $businessId)
    {
        return response()->json($this->wallet->addBank($r, $businessId, $this->body($r)), 201);
    }

    public function replaceBank(Request $r, int $businessId, int $id)
    {
        return response()->json($this->wallet->replaceBank($r, $businessId, $id, $this->body($r)), 201);
    }

    public function withdrawals(Request $r, int $businessId)
    {
        return $this->withdrawals->listFor($r, $businessId, false, LiveWalletPolicy::scope($r->query('scope')) ?? 'LIVE_LAB');
    }

    public function requestWithdrawal(Request $r, int $businessId)
    {
        $body = $this->body($r);
        if (array_diff(array_keys($body), ['amountVnd', 'accountId', 'walletScope'])) abort(400, 'Invalid withdrawal fields');
        $amount = $body['amountVnd'] ?? null;
        if (! is_string($amount) || ! preg_match('/^[1-9][0-9]{0,14}$/', $amount)) abort(422, 'Invalid payment field');
        $account = $body['accountId'] ?? null;
        if (! is_int($account) && ! (is_string($account) && ctype_digit($account))) abort(422, 'Invalid payment field');

        return response()->json($this->withdrawals->request($r, $businessId, WalletService::financialKey($r->header('Idempotency-Key')), (int) $amount, (int) $account, LiveWalletPolicy::scope($body['walletScope'] ?? null) ?? 'LIVE_LAB'), 201);
    }

    private function decision(Request $r, int $id, string $action)
    {
        $body = $this->body($r);
        if (array_diff(array_keys($body), ['version', 'reason'])) abort(400, 'Invalid decision fields');
        if (! isset($body['version']) || ! is_int($body['version']) || $body['version'] < 0) abort(400, 'Current request version required');

        return $this->withdrawals->review($r, $id, $action, $body['version'], isset($body['reason']) && is_string($body['reason']) ? trim($body['reason']) : null);
    }

    public function cancelWithdrawal(Request $r, int $businessId, int $id)
    {
        $row = \Illuminate\Support\Facades\DB::table('business_withdrawals')->where('id', $id)->where('business_id', $businessId)->first();
        abort_unless($row, 404, 'Withdrawal not found');

        return $this->decision($r, $id, 'cancel');
    }

    public function decide(Request $r, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject', 'retry', 'cancel'], true), 400, 'Invalid decision');

        return $this->decision($r, $id, $action);
    }

    public function admin(Request $r)
    {
        return $this->wallet->adminOverview($r, $this->page($r));
    }

    public function settle(Request $r)
    {
        return $this->wallet->createSettlement($r, $r->header('Idempotency-Key'), $this->body($r));
    }

    public function completeSettlement(Request $r, int $id)
    {
        return $this->wallet->completeSettlement($r, $id);
    }

    public function adminWithdrawals(Request $r)
    {
        return $this->withdrawals->listAll($r);
    }

    public function verifyBank(Request $r, int $businessId, int $id)
    {
        $body = $this->body($r);
        if (! isset($body['version']) || ! is_int($body['version']) || $body['version'] < 0) abort(400, 'Current version required');

        return $this->wallet->verifyBank($r, $businessId, $id, $body['version']);
    }

    public function reconcile(Request $r, int $id)
    {
        return $this->wallet->reconcileReceipt($r, $id);
    }
}
