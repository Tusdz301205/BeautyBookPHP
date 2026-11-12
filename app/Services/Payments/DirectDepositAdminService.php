<?php

namespace App\Services\Payments;

use App\Services\Beautybook\ApiScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform-admin view of direct deposits (an addition to the source, which gives the admin only the wallet receipts): the refunds
 * owed back to customers and the bank transfers that could not be applied to a deposit, each page of them with its status, and the
 * admin's review note recorded in the audit log. Read-only apart from the review mark; nothing here moves money.
 */
class DirectDepositAdminService
{
    public const PROCESSING = ['UNMATCHED', 'REFUND_REQUIRED', 'APPLIED', 'VERIFIED'];

    public const REFUND_STATUS = ['PENDING', 'REPORTED', 'CONFIRMED'];

    public function __construct(private ApiScope $scope) {}

    private function iso(?string $utc): ?string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->toISOString() : null;
    }

    /** @return array{0:int,1:int} page (1-based) and page size (1-100) */
    private function paging(Request $r): array
    {
        $page = max(1, (int) $r->query('page', 1));
        $size = (int) $r->query('pageSize', 20);

        return [$page, min(100, max(1, $size ?: 20))];
    }

    private function mask(?string $account): ?string
    {
        $text = (string) $account;

        return $text === '' ? null : (strlen($text) > 4 ? str_repeat('•', strlen($text) - 4).substr($text, -4) : $text);
    }

    /** Refunds owed back to customers, newest first; filter by `status` (PENDING, REPORTED, CONFIRMED) and `reason`. */
    public function refunds(Request $r): array
    {
        $this->scope->platform($r);
        [$page, $size] = $this->paging($r);
        $status = $r->query('status');
        $reason = $r->query('reason');
        abort_if($status !== null && ! in_array($status, self::REFUND_STATUS, true), 422, 'Invalid status');
        abort_if($reason !== null && ! preg_match('/^[A-Z_]{3,40}$/', (string) $reason), 422, 'Invalid reason');
        $query = DB::table('deposit_refunds as f')->join('booking_deposits as d', 'd.id', '=', 'f.deposit_id')->join('bookings as b', 'b.id', '=', 'd.booking_id')->join('businesses as biz', 'biz.id', '=', 'b.business_id')
            ->when($status, fn ($q) => $q->where('f.status', $status))->when($reason, fn ($q) => $q->where('f.reason', $reason));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('f.id')->forPage($page, $size)->get(['f.*', 'd.status as deposit_status', 'd.booking_id', 'b.booking_code', 'b.business_id', 'biz.name as business_name']);

        return ['items' => $rows->map(function ($f) {
            $destination = json_decode((string) $f->destination, true);

            return ['id' => (string) $f->id, 'bookingId' => (string) $f->booking_id, 'bookingCode' => $f->booking_code, 'businessId' => (string) $f->business_id, 'businessName' => $f->business_name, 'depositStatus' => $f->deposit_status,
                'amountVnd' => (string) $f->amount_vnd, 'reason' => $f->reason, 'status' => $f->status, 'destination' => $destination ? ['bankCode' => $destination['bankCode'] ?? null, 'accountNumber' => $this->mask($destination['accountNumber'] ?? null),
                    'accountName' => $destination['accountName'] ?? null] : null, 'reference' => $f->reference, 'createdAt' => $f->created_at ? CarbonImmutable::parse($f->created_at, config('app.timezone'))->toISOString() : null, 'reportedAt' => $this->iso($f->reported_at), 'confirmedAt' => $this->iso($f->confirmed_at)];
        })->all(), 'page' => $page, 'pageSize' => $size, 'total' => $total, 'hasMore' => $page * $size < $total];
    }

    /** Bank transfers received on a business's direct connection, newest first; by default the ones that need a person (UNMATCHED, REFUND_REQUIRED). */
    public function receipts(Request $r): array
    {
        $this->scope->platform($r);
        [$page, $size] = $this->paging($r);
        $processing = $r->query('processing');
        abort_if($processing !== null && ! in_array($processing, self::PROCESSING, true), 422, 'Invalid processing');
        $query = DB::table('sepay_webhook_events as e')->join('payment_connections as c', 'c.id', '=', 'e.connection_id')->join('businesses as biz', 'biz.id', '=', 'c.business_id')->leftJoin('booking_deposits as d', 'd.id', '=', 'e.deposit_id')
            ->where('e.transfer_type', 'in')->when($processing, fn ($q) => $q->where('e.processing', $processing), fn ($q) => $q->whereIn('e.processing', ['UNMATCHED', 'REFUND_REQUIRED']));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('e.received_at')->orderByDesc('e.id')->forPage($page, $size)->get(['e.*', 'c.business_id', 'c.bank_code', 'biz.name as business_name', 'd.booking_id']);
        $notes = DB::table('audit_logs')->where('action', 'DEPOSIT_RECEIPT_ADMIN_REVIEW')->where('entity_type', 'SepayReceipt')->whereIn('entity_id', $rows->pluck('id')->map(fn ($id) => (string) $id))->orderBy('id')->get()->groupBy('entity_id');

        return ['items' => $rows->map(fn ($e) => ['id' => (string) $e->id, 'providerTransactionId' => (string) $e->provider_transaction_id, 'businessId' => (string) $e->business_id, 'businessName' => $e->business_name, 'connectionId' => (string) $e->connection_id,
            'environment' => $e->environment, 'amountVnd' => (string) $e->amount_vnd, 'content' => $e->content, 'processing' => $e->processing, 'reviewReason' => $e->review_reason, 'bookingId' => $e->booking_id ? (string) $e->booking_id : null,
            'transactionAt' => $this->iso($e->transaction_at), 'receivedAt' => $this->iso($e->received_at), 'reviewedAt' => $this->iso($e->reviewed_at),
            'reviews' => ($notes[(string) $e->id] ?? collect())->map(fn ($n) => ['by' => (int) $n->user_id, 'at' => CarbonImmutable::parse($n->created_at, config('app.timezone'))->toISOString(), 'note' => json_decode((string) $n->metadata, true)['note'] ?? null])->all()])->all(),
            'page' => $page, 'pageSize' => $size, 'total' => $total, 'hasMore' => $page * $size < $total];
    }

    /** The admin has looked at a receipt that needs a person: the note goes to the audit log, the first review also stamps `reviewed_at`. */
    public function reviewReceipt(Request $r, int $id, array $raw): array
    {
        $this->scope->platform($r);
        if (array_diff(array_keys($raw), ['note'])) abort(400, 'Invalid review fields');
        $note = DirectDeposit::text($raw['note'] ?? null, 500);

        return DB::transaction(function () use ($r, $id, $note) {
            $receipt = DB::table('sepay_webhook_events as e')->join('payment_connections as c', 'c.id', '=', 'e.connection_id')->where('e.id', $id)->lockForUpdate()->first(['e.*', 'c.business_id']);
            abort_unless($receipt && $receipt->transfer_type === 'in', 404, 'Receipt not found');
            if (! $receipt->reviewed_at) DB::table('sepay_webhook_events')->where('id', $id)->update(['reviewed_at' => now('UTC')->format('Y-m-d H:i:s'), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['user_id' => $r->user()->id, 'business_id' => (int) $receipt->business_id, 'action' => 'DEPOSIT_RECEIPT_ADMIN_REVIEW', 'entity_type' => 'SepayReceipt', 'entity_id' => (string) $id,
                'metadata' => json_encode(['note' => $note, 'processing' => $receipt->processing, 'amountVnd' => (string) $receipt->amount_vnd], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);

            return ['success' => true, 'reviewedAt' => $this->iso(DB::table('sepay_webhook_events')->where('id', $id)->value('reviewed_at'))];
        }, 3);
    }
}
