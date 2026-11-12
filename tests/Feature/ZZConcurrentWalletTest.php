<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallet\WalletCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/** Real parallel PHP processes against the isolated MySQL: money posts once however many receipts, payouts or bookings race. */
class ZZConcurrentWalletTest extends TestCase
{
    use WalletFixtures;

    public function test_parallel_receipts_payouts_and_deposit_holds_post_once_and_never_overdraw(): void
    {
        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->configureLab();

        // A: six identical deliveries of one receipt at the same instant credit one top-up once.
        $invoice = $this->invoice(50000);
        $payload = $this->payload($invoice, ['id' => 9001]);
        $this->assertSame(array_fill(0, 6, 'OK'), $this->race(array_fill(0, 6, ['receipt', base64_encode(json_encode($payload))])));
        $this->assertSame(1, DB::table('sepay_webhook_events')->count());
        $this->assertSame(1, DB::table('wallet_transfers')->where('kind', 'TOPUP')->count());
        $this->assertSame(['SUCCESS', 'TOPUP_APPLIED'], [DB::table('wallet_topups')->value('status'), DB::table('sepay_webhook_events')->value('processing')]);
        $this->assertSame('50000', $this->wallet()['availableVnd']);

        // B: different bank transactions carrying the same memo and amount. Only one may credit.
        $second = $this->invoice(30000);
        $jobs = array_map(fn ($id) => ['receipt', base64_encode(json_encode($this->payload($second, ['id' => $id])))], [9101, 9102, 9103, 9104]);
        $this->assertSame(array_fill(0, 4, 'OK'), $this->race($jobs));
        $this->assertSame(1, DB::table('wallet_transfers')->where('operation_key', 'topup:'.$second['id'])->count());
        $this->assertSame(1, DB::table('sepay_webhook_events')->whereBetween('provider_transaction_id', [9101, 9104])->where('processing', 'TOPUP_APPLIED')->count());
        $this->assertSame(3, DB::table('sepay_webhook_events')->whereBetween('provider_transaction_id', [9101, 9104])->where('review_reason', 'ADDITIONAL_BANK_PAYMENT')->count());
        $this->assertSame('80000', $this->wallet()['availableVnd']);

        // C: several workers resume the same approved withdrawal; the payout posts once.
        [, , $payable] = $this->releasedPayable(1000000);
        $business = (int) $payable->business_id;
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $bank = DB::table('business_bank_accounts')->insertGetId(['business_id' => $business, 'bank_code' => 'ACB', 'bank_name' => 'ACB', 'account_number' => '123456789', 'account_name' => 'DEMO', 'status' => 'VERIFIED_DEMO', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $withdrawal = DB::table('business_withdrawals')->insertGetId(['business_id' => $business, 'bank_account_id' => $bank, 'wallet_scope' => 'LIVE_LAB', 'operation_key' => hash('sha256', 'race'), 'fingerprint' => hash('sha256', 'race'), 'amount_vnd' => 100000,
            'status' => 'PROCESSING', 'bank_snapshot' => json_encode(['accountId' => $bank]), 'requested_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('withdrawal_allocations')->insert(['withdrawal_id' => $withdrawal, 'payable_id' => $payable->id, 'amount_vnd' => 100000, 'released' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(array_fill(0, 5, 'OK'), $this->race(array_fill(0, 5, ['payout', (string) $withdrawal])));
        $this->assertSame([1, 1, 'SIMULATED_COMPLETED'], [DB::table('wallet_transfers')->where('operation_key', 'withdrawal:'.$withdrawal)->count(), DB::table('withdrawal_attempts')->where('withdrawal_id', $withdrawal)->count(), DB::table('business_withdrawals')->where('id', $withdrawal)->value('status')]);

        // D: two bookings race for a wallet that can fund exactly one deposit: one wins, the other gets 402, nothing goes negative.
        DB::table('wallets')->where('scope', 'LIVE_LAB')->update(['available_vnd' => 30000, 'held_vnd' => 0]);
        $one = $this->booking(100000, 'PENDING');
        $two = $this->booking(100000, 'PENDING');
        $outputs = $this->race([['hold', (string) $one->id], ['hold', (string) $two->id]]);
        sort($outputs);
        $this->assertSame(['ERR:402', 'OK'], array_map(fn ($o) => str_starts_with($o, 'ERR:') ? 'ERR:'.explode(':', $o)[2] : $o, $outputs), json_encode($outputs));
        $this->assertSame(1, DB::table('booking_deposits')->where('status', 'HELD')->count());
        $wallet = DB::table('wallets')->where('scope', 'LIVE_LAB')->first();
        $this->assertSame([0, 30000], [(int) $wallet->available_vnd, (int) $wallet->held_vnd]);
        // One HOLD belongs to scenario C's released fixture, one to the winner; the loser left no ledger entry.
        $this->assertSame(2, DB::table('wallet_transfers')->where('kind', 'HOLD')->count());
        $this->assertSame([(int) DB::table('booking_deposits')->where('status', 'HELD')->value('id')], [(int) str_replace('hold:', '', (string) DB::table('wallet_transfers')->where('kind', 'HOLD')->orderByDesc('id')->value('operation_key'))]);

        // E: direct deposits. Six deliveries of one bank transaction pay one deposit once; four different transactions
        // carrying the same code pay it once and the other three are owed back.
        config(['wallet.mode' => '', 'wallet.direct' => ['enabled' => true, 'encryption_key' => str_repeat('c3', 32)]]);
        $secret = bin2hex(random_bytes(32));
        $connection = DB::table('payment_connections')->insertGetId(['business_id' => $business, 'environment' => 'test', 'bank_code' => 'ACB', 'account_number' => '0000000001', 'account_name' => 'RACE', 'encrypted_secret' => '',
            'verification_code' => 'GS'.strtoupper(bin2hex(random_bytes(12))), 'status' => 'READY', 'enabled' => 1, 'verified_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_connections')->where('id', $connection)->update(['encrypted_secret' => \App\Services\Payments\DirectDeposit::seal($secret, $connection)]);
        $awaiting = function (string $code) use ($connection) {
            $booking = $this->booking(100000, 'PENDING');
            DB::table('booking_deposits')->insert(['booking_id' => $booking->id, 'funding_source' => 'DIRECT_SEPAY', 'connection_id' => $connection, 'payment_code' => $code, 'total_vnd' => 100000, 'amount_vnd' => 30000, 'remaining_vnd' => 70000,
                'status' => 'AWAITING_PAYMENT', 'environment' => 'test', 'bank_code' => 'ACB', 'account_number' => '0000000001', 'account_name' => 'RACE', 'confirmation_mode' => 'MANUAL', 'pending_hold_minutes' => 15, 'cancellation_hours' => 4,
                'expires_at' => now('UTC')->addMinutes(15), 'created_at' => now(), 'updated_at' => now()]);

            return $booking;
        };
        $receipt = fn (string $code, int $id) => json_encode(['id' => $id, 'gateway' => 'ACB', 'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'), 'accountNumber' => '0000000001', 'subAccount' => null, 'code' => null,
            'content' => $code.' coc', 'transferType' => 'in', 'transferAmount' => 30000, 'referenceCode' => 'RACE', 'description' => 'race', 'accumulated' => 0]);
        $job = fn (string $raw) => ['direct-receipt', base64_encode(json_encode(['connection' => $connection, 'secret' => $secret, 'raw' => $raw]))];
        $codeA = 'GM'.strtoupper(bin2hex(random_bytes(12)));
        $awaiting($codeA);
        $same = $receipt($codeA, 9301);
        $this->assertSame(array_fill(0, 6, 'OK'), $this->race(array_fill(0, 6, $job($same))));
        $this->assertSame([1, 'PAID', 0], [DB::table('sepay_webhook_events')->where('provider_transaction_id', 9301)->count(), DB::table('booking_deposits')->where('payment_code', $codeA)->value('status'), DB::table('deposit_refunds')->count()]);
        $codeB = 'GM'.strtoupper(bin2hex(random_bytes(12)));
        $awaiting($codeB);
        $this->assertSame(array_fill(0, 4, 'OK'), $this->race(array_map(fn ($id) => $job($receipt($codeB, $id)), [9401, 9402, 9403, 9404])));
        $depositB = (int) DB::table('booking_deposits')->where('payment_code', $codeB)->value('id');
        $this->assertSame(['PAID', 1, 3, 3], [DB::table('booking_deposits')->find($depositB)->status, DB::table('sepay_webhook_events')->where('deposit_id', $depositB)->where('processing', 'APPLIED')->count(),
            DB::table('deposit_refunds')->where('deposit_id', $depositB)->where('reason', 'DUPLICATE_PAYMENT')->count(), DB::table('sepay_webhook_events')->whereBetween('provider_transaction_id', [9401, 9404])->count() - 1]);

        // F: one settlement or one withdrawal may take a payable, never two of them. Two admins settle with different keys
        // while the owner asks for everything that is withdrawable: exactly one of the three succeeds.
        $this->configureLab();
        $this->releasedPayable(500000);
        $admin = User::where('email', 'admin@beautybook.local')->firstOrFail();
        DB::table('business_bank_accounts')->where('business_id', $business)->update(['is_default' => 0]);
        $verified = DB::table('business_bank_accounts')->insertGetId(['business_id' => $business, 'bank_code' => 'ACB', 'bank_name' => 'ACB', 'account_number' => '987654321', 'account_name' => 'DEMO', 'status' => 'VERIFIED_DEMO', 'is_default' => 1, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $available = (new \App\Services\Payout\WithdrawalService(app(\App\Services\Beautybook\ApiScope::class)))->balances($business)['available'];
        $assignment = fn (User $u) => $u->roleAssignments()->firstOrFail()->id;
        $settle = fn (string $key) => ['settle', base64_encode(json_encode(['user' => $admin->id, 'assignment' => $assignment($admin), 'key' => $key, 'body' => ['businessId' => (string) $business, 'accountId' => (string) $verified,
            'periodStart' => now('UTC')->subDay()->toIso8601String(), 'periodEnd' => now('UTC')->addDay()->toIso8601String()]]))];
        $withdraw = ['withdraw', base64_encode(json_encode(['user' => $owner->id, 'assignment' => $assignment($owner), 'key' => 'race-withdraw-000001', 'business' => $business, 'amount' => $available, 'account' => $verified]))];
        $outputs = $this->race([$settle('race-settle-000000001'), $settle('race-settle-000000002'), $withdraw]);
        $this->assertSame(1, count(array_filter($outputs, fn ($o) => $o === 'OK')), json_encode($outputs));
        $settled = (int) DB::table('business_payables')->whereNotNull('settlement_id')->count();
        $raceWithdrawal = DB::table('business_withdrawals')->where('operation_key', hash('sha256', 'withdrawal-request:'.$owner->id.':race-withdraw-000001'))->first();
        $this->assertTrue(($settled > 0) xor (bool) $raceWithdrawal, 'a payable is settled or withdrawn, never both: '.json_encode($outputs));
        $this->assertSame(1, DB::table('sim_settlements')->count() + ($raceWithdrawal ? 1 : 0), 'exactly one winner');
        $this->assertSame(0, DB::table('business_payables as p')->whereNotNull('p.settlement_id')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('withdrawal_allocations as a')->whereColumn('a.payable_id', 'p.id')->where('a.released', 0)->where('a.withdrawal_id', '!=', $withdrawal))->count(), 'no settled payable is also allocated to a withdrawal');

        // G: the remainder paid at the salon is recorded once, however the two doors (the deposit view's store receipt and the
        // payment screen's collect) and the two sessions (owner, receptionist) race: one transaction, one ledger entry, no deadlock.
        $owner = User::where('email', 'owner@beautybook.local')->firstOrFail();
        $receptionist = User::where('email', 'receptionist@beautybook.local')->firstOrFail();
        $arrived = $this->booking(100000, 'CHECKED_IN');
        $depositId = DB::table('booking_deposits')->insertGetId(['booking_id' => $arrived->id, 'funding_source' => 'DIRECT_SEPAY', 'connection_id' => $connection, 'payment_code' => 'GM'.strtoupper(bin2hex(random_bytes(12))), 'total_vnd' => 100000, 'amount_vnd' => 30000,
            'remaining_vnd' => 70000, 'status' => 'PAID', 'environment' => 'test', 'bank_code' => 'ACB', 'account_number' => '0000000001', 'account_name' => 'RACE', 'confirmation_mode' => 'MANUAL', 'pending_hold_minutes' => 15, 'cancellation_hours' => 4,
            'expires_at' => now('UTC')->addMinutes(15), 'paid_at' => now('UTC'), 'created_at' => now(), 'updated_at' => now()]);
        $store = fn (User $u, string $reference) => ['store-receipt', base64_encode(json_encode(['user' => $u->id, 'assignment' => $assignment($u), 'booking' => $arrived->id, 'version' => 0, 'reference' => $reference]))];
        $collect = fn (User $u, string $key) => ['collect', base64_encode(json_encode(['user' => $u->id, 'assignment' => $assignment($u), 'booking' => $arrived->id, 'key' => $key]))];
        $outputs = $this->race([$store($owner, 'phieu race'), $store($receptionist, 'phieu race'), $store($owner, 'phieu race'), $collect($owner, 'race-collect-0000001'), $collect($receptionist, 'race-collect-0000002')]);
        $this->assertSame([], preg_grep('/Deadlock|QueryException|EXIT:/i', $outputs), 'no deadlock or crash: '.json_encode($outputs));
        $this->assertSame(1, DB::table('payment_transactions')->where('booking_id', $arrived->id)->count(), json_encode($outputs));
        $this->assertSame(7000000, (int) DB::table('payment_transactions')->where('booking_id', $arrived->id)->sum('amount_cents'));
        $this->assertSame(1, DB::table('payment_ledger_entries')->where('booking_id', $arrived->id)->where('type', 'PAYMENT_RECEIVED')->count());
        $this->assertNotNull(DB::table('booking_deposits')->where('id', $depositId)->value('store_received_at'));
        $winner = DB::table('payment_transactions')->where('booking_id', $arrived->id)->first();
        // Whoever lost was refused (409 / 422) or replayed the winner's own receipt, never recorded a second payment.
        foreach ($outputs as $output) $this->assertMatchesRegularExpression('/^(OK|ERR:[^:]+:(409|422):.*|ERR:Illuminate.Validation.ValidationException:.*)$/s', $output, $output);
        if (str_starts_with((string) $winner->idempotency_key, 'store-receipt:')) {
            $this->assertSame(3, count(array_filter(array_slice($outputs, 0, 3), fn ($o) => $o === 'OK')), 'the three identical store receipts all answer OK (one posts, two replay): '.json_encode($outputs));
            $this->assertSame(0, count(array_filter(array_slice($outputs, 3), fn ($o) => $o === 'OK')), 'a collect after the store receipt is refused: '.json_encode($outputs));
        } else {
            $this->assertSame(1, count(array_filter(array_slice($outputs, 3), fn ($o) => $o === 'OK')), 'one collect won: '.json_encode($outputs));
            $this->assertSame(0, count(array_filter(array_slice($outputs, 0, 3), fn ($o) => $o === 'OK')), 'a store receipt after the collect is refused: '.json_encode($outputs));
        }

        // H: a bank receipt racing the store's collect while the deposit still awaits it. The store may never record money for the
        // price first: every collect is refused (409), the receipt pays the deposit once, and no payment is recorded.
        $codeH = 'GM'.strtoupper(bin2hex(random_bytes(12)));
        $waiting = $awaiting($codeH);
        $same = $receipt($codeH, 9501);
        $collectOn = fn (User $u, string $key, int $bookingId) => ['collect', base64_encode(json_encode(['user' => $u->id, 'assignment' => $assignment($u), 'booking' => $bookingId, 'key' => $key]))];
        $outputs = $this->race([$job($same), $job($same), $collectOn($owner, 'race-collect-h-000001', $waiting->id), $collectOn($receptionist, 'race-collect-h-000002', $waiting->id), $collectOn($owner, 'race-collect-h-000003', $waiting->id)]);
        $this->assertSame([], preg_grep('/Deadlock|QueryException|EXIT:/i', $outputs), 'no deadlock or crash: '.json_encode($outputs));
        $this->assertSame(['OK', 'OK'], array_slice($outputs, 0, 2), json_encode($outputs));
        foreach (array_slice($outputs, 2) as $output) $this->assertMatchesRegularExpression('/^ERR:[^:]+:409:/', $output, 'a collect before the deposit is paid is refused: '.$output);
        $this->assertSame(['PAID', 0, 0], [DB::table('booking_deposits')->where('payment_code', $codeH)->value('status'), DB::table('payment_transactions')->where('booking_id', $waiting->id)->count(), DB::table('deposit_refunds')->where('deposit_id', DB::table('booking_deposits')->where('payment_code', $codeH)->value('id'))->count()]);

        // H2: money already recorded before the gate existed (a stored payment on a booking whose deposit awaits): the receipt that
        // follows, delivered twice at once, is owed back once and the stored payment is untouched.
        $codeLegacy = 'GM'.strtoupper(bin2hex(random_bytes(12)));
        $legacy = $awaiting($codeLegacy);
        $storedId = DB::table('payment_transactions')->insertGetId(['booking_id' => $legacy->id, 'amount_cents' => 10000000, 'currency' => 'VND', 'method' => 'CASH', 'status' => 'VERIFIED', 'idempotency_key' => 'race-legacy-'.bin2hex(random_bytes(4)),
            'created_by' => $owner->id, 'verified_by' => $owner->id, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $late = $receipt($codeLegacy, 9601);
        $outputs = $this->race([$job($late), $job($late)]);
        $this->assertSame(['OK', 'OK'], $outputs, json_encode($outputs));
        $depositLegacy = DB::table('booking_deposits')->where('payment_code', $codeLegacy)->first();
        $this->assertSame(['AWAITING_PAYMENT', 1, 'STORE_PAYMENT_RECORDED', 'REFUND_REQUIRED'], [$depositLegacy->status, DB::table('deposit_refunds')->where('deposit_id', $depositLegacy->id)->count(),
            DB::table('deposit_refunds')->where('deposit_id', $depositLegacy->id)->value('reason'), DB::table('sepay_webhook_events')->where('provider_transaction_id', 9601)->value('processing')]);
        $this->assertSame(['VERIFIED', 1], [DB::table('payment_transactions')->where('id', $storedId)->value('status'), DB::table('payment_transactions')->where('booking_id', $legacy->id)->count()]);
    }

    /** @param list<array{0:string,1:string}> $jobs @return list<string> */
    private function race(array $jobs): array
    {
        $dir = sys_get_temp_dir().'/beautybook-wallet-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0777, true);
        $gate = $dir.'/start';
        file_put_contents($dir.'/worker.php', <<<'PHP'
<?php
use App\Services\Finance\SepayWebhookService;
use App\Services\Payout\WithdrawalService;
use App\Services\Wallet\WalletCore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

[, $root, $kind, $argument, $secret, $ready, $gate, $cfg] = $argv;
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$cfg = json_decode(base64_decode($cfg), true);
config(['wallet' => $cfg['wallet'], 'services.sepay' => $cfg['sepay']]);
file_put_contents($ready, 'ready');
while (! is_file($gate)) usleep(1000);
try {
    if ($kind === 'receipt') {
        $raw = base64_decode($argument);
        $timestamp = (string) time();
        $request = Request::create('/api/v1/payments/sepay/wallet-live-lab/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SEPAY_TIMESTAMP' => $timestamp,
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret)], $raw);
        $result = $app->make(SepayWebhookService::class)->receiveLiveLab($request);
        echo $result === ['success' => true] ? 'OK' : 'BAD';
    } elseif ($kind === 'direct-receipt') {
        $spec = json_decode(base64_decode($argument), true);
        $timestamp = (string) time();
        $request = Request::create('/webhook', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SEPAY_TIMESTAMP' => $timestamp,
            'HTTP_X_SEPAY_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$spec['raw'], $spec['secret'])], $spec['raw']);
        $result = $app->make(\App\Services\Payments\DirectDepositService::class)->webhook($request, (int) $spec['connection']);
        echo $result === ['success' => true] ? 'OK' : 'BAD';
    } elseif ($kind === 'settle' || $kind === 'withdraw') {
        $spec = json_decode(base64_decode($argument), true);
        $user = \App\Models\User::findOrFail($spec['user']);
        $request = Request::create('/finance', 'POST');
        $session = $app['session']->driver();
        $session->put('workspace_assignment_id', $spec['assignment']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        if ($kind === 'settle') $app->make(\App\Services\Wallet\WalletService::class)->createSettlement($request, $spec['key'], $spec['body']);
        else $app->make(WithdrawalService::class)->request($request, (int) $spec['business'], $spec['key'], (int) $spec['amount'], (int) $spec['account']);
        echo 'OK';
    } elseif ($kind === 'store-receipt' || $kind === 'collect') {
        $spec = json_decode(base64_decode($argument), true);
        $user = \App\Models\User::findOrFail($spec['user']);
        $request = Request::create('/finance', 'POST');
        $session = $app['session']->driver();
        $session->put('workspace_assignment_id', $spec['assignment']);
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        if ($kind === 'store-receipt') {
            $app->make(\App\Services\Payments\DirectDepositService::class)->action($request, (int) $spec['booking'], 'store-receipt', ['version' => $spec['version'], 'reference' => $spec['reference']]);
        } else {
            $booking = \App\Models\Booking::findOrFail($spec['booking']);
            $amount = \App\Services\Finance\Money::decimal($app->make(\App\Services\Finance\PaymentService::class)->balance($booking)['collectable_cents']);
            $app->make(\App\Services\Finance\PaymentService::class)->collect($request, $booking, ['method' => 'CASH', 'amount' => $amount, 'idempotency_key' => $spec['key'], 'evidence' => null]);
        }
        echo 'OK';
    } elseif ($kind === 'payout') {
        $app->make(WithdrawalService::class)->process((int) $argument);
        echo 'OK';
    } else {
        $booking = DB::table('bookings')->find((int) $argument);
        $terms = WalletCore::terms((int) $booking->branch_id, $booking->total_amount, 4);
        DB::transaction(function () use ($booking, $terms) {
            DB::table('customer_profiles')->where('id', $booking->customer_profile_id)->lockForUpdate()->first();
            WalletCore::hold(['bookingId' => $booking->id, 'customerProfileId' => (int) $booking->customer_profile_id, 'actorId' => (int) $booking->created_by_user_id, 'branchId' => (int) $booking->branch_id,
                'businessId' => (int) $booking->business_id, 'total' => $booking->total_amount, 'cancellationHours' => 4, 'confirmationMode' => 'MANUAL', 'pendingHoldMinutes' => 15, 'now' => now('UTC'),
                'startAt' => \Carbon\CarbonImmutable::parse($booking->appointment_start_at, 'UTC'), 'acceptedQuote' => $terms['quote']]);
        });
        echo 'OK';
    }
} catch (Throwable $e) {
    echo 'ERR:'.get_class($e).':'.($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode().':' : '').$e->getMessage();
}
PHP);
        $environment = ['APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'), 'DB_USERNAME' => (string) config('database.connections.mysql.username'), 'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr'];
        $cfg = base64_encode(json_encode(['wallet' => config('wallet'), 'sepay' => config('services.sepay')]));
        $processes = [];
        try {
            foreach ($jobs as $index => [$kind, $argument]) {
                $ready = $dir.'/ready-'.$index;
                $process = new Process([PHP_BINARY, $dir.'/worker.php', base_path(), $kind, $argument, self::LAB_SECRET, $ready, $gate, $cfg], base_path(), $environment, null, 60);
                $process->start();
                $processes[] = [$process, $ready];
            }
            $deadline = microtime(true) + 30;
            while (microtime(true) < $deadline && count(array_filter(array_column($processes, 1), is_file(...))) < count($jobs)) usleep(10000);
            $this->assertCount(count($jobs), array_filter(array_column($processes, 1), is_file(...)), 'every worker is ready before the gate opens');
            touch($gate);
            $outputs = [];
            foreach ($processes as [$process]) {
                $process->wait();
                $outputs[] = trim($process->getOutput()) ?: 'EXIT:'.$process->getExitCode().':'.trim($process->getErrorOutput());
            }

            return $outputs;
        } finally {
            foreach ($processes as [$process]) if ($process->isRunning()) $process->stop(1);
            @array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
    }
}
