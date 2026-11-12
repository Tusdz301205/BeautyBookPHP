<?php

namespace Tests\Support;

use App\Models\Branch;
use App\Models\BranchServiceOffering;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Shared setup for the direct-deposit suites: the owner's verified connection, signed receipts, real bookings with a quote. */
trait DirectDepositFixtures
{
    use WalletFixtures;

    protected const KEY = 'ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12cd34ef56ab12';

    /** One bank transaction date per test: a resent receipt is byte-identical, whichever second it is delivered in. */
    protected string $bankDate;

    protected function business(): int
    {
        return (int) Branch::where('slug', 'tan-tao')->value('business_id');
    }

    protected function branchId(): int
    {
        return (int) Branch::where('slug', 'tan-tao')->value('id');
    }

    protected function conn(string $suffix = ''): string
    {
        return '/api/v1/payments/businesses/'.$this->business().'/connections'.$suffix;
    }

    protected function create(array $patch = [], string $as = 'owner')
    {
        $this->actor($as);

        return $this->postJson($this->conn(), array_merge(['bankCode' => 'ACB', 'accountNumber' => '0000000001', 'accountName' => 'cong ty thu nghiem', 'environment' => 'test'], $patch));
    }

    protected function webhookUrl(array $connection): string
    {
        return '/api/v1/payments/sepay/connections/'.$connection['id'].'/webhook';
    }

    protected function transfer(array $connection, string $secret, string $content, int $amount, array $patch = [])
    {
        return $this->deliver($this->webhookUrl($connection), array_merge([
            'id' => random_int(100000, 99999999), 'gateway' => $connection['bankCode'], 'transactionDate' => $this->bankDate, 'accountNumber' => $connection['accountNumber'], 'subAccount' => null,
            'code' => null, 'content' => $content, 'transferType' => 'in', 'transferAmount' => $amount, 'referenceCode' => 'TEST-FT1', 'description' => 'thu nghiem', 'accumulated' => 0,
        ], $patch), $secret);
    }

    /** A verified, enabled connection created and set up exactly as the owner does. @return array{0:array,1:string} */
    protected function connect(bool $enable = true): array
    {
        $connection = $this->create()->assertCreated()->json();
        $secret = $connection['secret'];
        $this->transfer($connection, $secret, $connection['verificationCode'].' xac minh', 10000)->assertOk();
        $this->actor('owner');
        $listed = collect($this->getJson($this->conn())->assertOk()->json('connections'))->firstWhere('id', $connection['id']);
        $this->assertSame('READY', $listed['status']);
        if ($enable) {
            $this->patchJson($this->conn('/'.$connection['id']), ['enabled' => true, 'version' => $listed['version']])->assertOk()->assertJsonPath('enabled', true);
        }

        return [$listed, $secret];
    }

    protected function policy(array $metadata = []): void
    {
        $id = $this->branchId();
        DB::table('beautybook_branch_settings')->insertOrIgnore(['branch_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('beautybook_branch_settings')->where('branch_id', $id)->first();
        DB::table('beautybook_branch_settings')->where('branch_id', $id)->update(['metadata' => json_encode(array_replace(json_decode($row->metadata ?? '{}', true) ?: [], $metadata))]);
    }

    protected function bookingData(int $daysAhead = 8): array
    {
        $daysAhead = 8 + ($daysAhead - 8) * 2;
        $b = Branch::where('slug', 'tan-tao')->firstOrFail();
        $offering = BranchServiceOffering::where('branch_id', $b->id)->orderBy('id')->firstOrFail();
        $staff = StaffProfile::where('branch_id', $b->id)->where('status', 'ACTIVE')->firstOrFail();
        $staff->offerings()->syncWithoutDetaching([$offering->id => ['business_id' => $b->business_id, 'branch_id' => $b->id]]);
        $at = CarbonImmutable::now('Asia/Ho_Chi_Minh')->addDays($daysAhead)->setTime(10, 0);
        while ($at->dayOfWeek === 0) $at = $at->addDay();

        return ['branchId' => (string) $b->id, 'serviceIds' => [(string) $offering->id], 'staffId' => (string) $staff->id, 'appointmentDate' => $at->toIso8601String(), 'source' => 'ONLINE_WEB',
            'customerName' => 'Tên liên hệ riêng', 'customerPhone' => '0901234567', 'contactConsent' => true];
    }

    protected function terms(array $data): array
    {
        $this->actor('customer');
        $total = (int) round((float) $this->postJson('/api/v1/bookings/preview-price', $data)->assertOk()->json('finalAmount'));

        return $this->getJson('/api/v1/wallet/deposit-terms?branchId='.$data['branchId'].'&total='.$total)->assertOk()->json();
    }

    protected function book(array $data, ?string $quote, string $key)
    {
        $this->actor('customer');

        return $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/bookings', $data + ($quote !== null ? ['walletDepositQuote' => $quote] : []));
    }

    /** Books through the real API with the accepted quote. @return array{0:int,1:object} booking id and its deposit */
    protected function booked(string $key = 'direct-booking-0001', int $daysAhead = 8): array
    {
        $data = $this->bookingData($daysAhead);
        $terms = $this->terms($data)['terms'];
        $id = (int) $this->book($data, $terms['quote'], $key)->assertCreated()->json('id');

        return [$id, DB::table('booking_deposits')->where('booking_id', $id)->first()];
    }

    protected function pay(array $connection, string $secret, object $deposit, ?int $amount = null, array $patch = [])
    {
        return $this->transfer($connection, $secret, $deposit->payment_code.' coc lich hen', $amount ?? (int) $deposit->amount_vnd, $patch);
    }

    protected function moveStatus(int $id, string $to, string $as = 'owner', array $extra = [])
    {
        $this->actor($as);

        return $this->patchJson("/api/v1/bookings/{$id}/status", ['status' => $to] + $extra);
    }

    protected function move(int $id, $start, $end): void
    {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();
        DB::table('bookings')->where('id', $id)->update(['appointment_start_at' => $start, 'appointment_end_at' => $end]);
        DB::table('booking_services')->where('booking_id', $id)->update(['item_start_at' => $start, 'item_end_at' => $end, 'occupied_start_at' => $start, 'occupied_end_at' => $end]);
    }

    protected function deposit(int $bookingId): object
    {
        return DB::table('booking_deposits')->where('booking_id', $bookingId)->first();
    }

    protected function depositView(int $bookingId, string $as)
    {
        $this->actor($as);

        return $this->getJson("/api/v1/payments/bookings/{$bookingId}/deposit");
    }

    protected function act(int $bookingId, string $action, array $body, string $as)
    {
        $this->actor($as);

        return $this->postJson("/api/v1/payments/bookings/{$bookingId}/deposit/{$action}", $body);
    }

    /** Seeds and configures the direct-deposit lab: no wallet mode, direct deposits enabled with a throwaway key. */
    protected function prepareDirectDeposits(): void
    {
        $this->seed();
        $this->bankDate = now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s');
        config(['wallet.mode' => '', 'wallet.direct' => ['enabled' => true, 'encryption_key' => self::KEY]]);
    }
}
