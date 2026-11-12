<?php

namespace Tests\Feature;

use App\Services\Payout\MockPayoutProvider;
use App\Services\Payout\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/** Business bank accounts (submit, replace, admin verification) and MOCK payouts drawn from released deposits. */
class WalletPayoutTest extends TestCase
{
    use RefreshDatabase;
    use WalletFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->configureLab();
    }

    private function business(): int
    {
        return (int) DB::table('branches')->where('slug', 'tan-tao')->value('business_id');
    }

    private function api(string $suffix = ''): string
    {
        return '/api/v1/wallet/businesses/'.$this->business().$suffix;
    }

    private function bank(bool $verify = true, string $number = '123456789'): array
    {
        $this->actor('owner');
        $account = $this->postJson($this->api('/accounts'), ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => $number, 'accountName' => 'DEMO OWNER'])->assertCreated()->json();
        if ($verify) {
            $this->actor('admin');
            $account = $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$account['id'].'/verify', ['version' => $account['version']])->assertOk()->json();
        }

        return $account;
    }

    /** A payable of 300.000 (30% of 1.000.000) and a verified account. @return array{0:object,1:array} */
    private function funded(): array
    {
        [, , $payable] = $this->releasedPayable(1000000);

        return [$payable, $this->bank()];
    }

    private function ask(string $amount, array $account, ?string $key = null, ?string $scope = null)
    {
        $this->actor('owner');

        return $this->withHeader('Idempotency-Key', $key ?? $this->key())->postJson($this->api('/withdrawals'), ['amountVnd' => $amount, 'accountId' => $account['id']] + ($scope ? ['walletScope' => $scope] : []));
    }

    private function balances(): array
    {
        $this->actor('owner');

        return $this->getJson($this->api())->assertOk()->json('withdrawalBalances');
    }

    private function countingProvider(bool $succeeds): object
    {
        $probe = new class { public int $count = 0; };
        $this->app->bind(MockPayoutProvider::class, fn () => new class($probe, $succeeds) extends MockPayoutProvider {
            public function __construct(private object $probe, private bool $succeeds) {}

            public function execute(int $withdrawalId, int $amountVnd, array $bankSnapshot): array
            {
                $this->probe->count++;

                return $this->succeeds ? ['success' => true, 'reference' => 'MOCK-'.$withdrawalId] : ['success' => false, 'errorCode' => 'MOCK_DOWN'];
            }
        });

        return $probe;
    }

    private function refundAfterRelease(object $payable, int $amount, string $status = 'PENDING'): int
    {
        return DB::table('deposit_refunds')->insertGetId(['deposit_id' => $payable->deposit_id, 'source' => 'SIMULATED_WALLET', 'operation_key' => 'post-release:'.bin2hex(random_bytes(6)), 'amount_vnd' => $amount,
            'reason' => 'POST_RELEASE_REFUND', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function decide(int $id, string $action, int $version, array $extra = [])
    {
        $this->actor('admin');

        return $this->postJson("/api/v1/wallet/admin/withdrawals/{$id}/{$action}", ['version' => $version] + $extra);
    }

    // ---- bank account verification --------------------------------------------------------------------------

    public function test_a_bank_account_is_submitted_pending_verified_by_an_admin_and_replaced_without_breaking_the_old_one(): void
    {
        $account = $this->bank(false);
        $this->assertSame(['PENDING', false, 0], [$account['status'], $account['isDefault'], $account['version']]);
        $this->assertStringNotContainsString('123456789', json_encode($account));
        $this->assertSame('•••••6789', $account['accountNumberMasked']);
        // Submitting the same details again returns the same row; invalid fields are refused.
        $this->actor('owner');
        $this->assertSame($account['id'], $this->postJson($this->api('/accounts'), ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '123456789', 'accountName' => 'DEMO OWNER'])->json('id'));
        $this->postJson($this->api('/accounts'), ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '12', 'accountName' => 'X'])->assertStatus(422);
        $this->postJson($this->api('/accounts'), ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '123456789', 'accountName' => 'X', 'status' => 'VERIFIED_DEMO'])->assertStatus(400);
        $this->assertSame(1, DB::table('business_bank_accounts')->count());

        // An unverified account cannot receive a payout; the owner cannot verify it; strangers cannot touch the business.
        $this->releasedPayable(1000000);
        $this->ask('1000', $account)->assertStatus(409);
        $this->actor('owner');
        $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$account['id'].'/verify', ['version' => 0])->assertForbidden();
        foreach (['receptionist', 'owner2', 'customer', 'admin'] as $actor) {
            $this->actor($actor);
            $this->getJson($this->api())->assertForbidden();
            $this->postJson($this->api('/accounts'), ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '5555555', 'accountName' => 'X'])->assertForbidden();
        }
        $this->actor('admin');
        $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$account['id'].'/verify', ['version' => 5])->assertStatus(409);
        $verified = $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$account['id'].'/verify', ['version' => 0])->assertOk()->json();
        $this->assertSame(['VERIFIED_DEMO', true, 1], [$verified['status'], $verified['isDefault'], $verified['version']]);
        $this->ask('1000', $verified)->assertCreated();

        // A replacement is a new pending account; the verified one keeps working until the admin verifies the replacement.
        $this->actor('owner');
        $replacement = $this->putJson($this->api('/accounts/'.$verified['id']), ['version' => 1, 'bankCode' => 'MBBank', 'bankName' => 'MB', 'accountNumber' => '987654321', 'accountName' => 'DEMO OWNER'])->assertCreated()->json();
        $this->putJson($this->api('/accounts/'.$verified['id']), ['version' => 0, 'bankCode' => 'MBBank', 'bankName' => 'MB', 'accountNumber' => '111222333', 'accountName' => 'X'])->assertStatus(409);
        $this->assertSame('PENDING', $replacement['status']);
        $this->assertSame(['VERIFIED_DEMO', true], [DB::table('business_bank_accounts')->find($verified['id'])->status, (bool) DB::table('business_bank_accounts')->find($verified['id'])->is_default]);
        $this->ask('1000', $verified)->assertCreated();
        $this->ask('1000', $replacement)->assertStatus(409);
        $this->actor('admin');
        $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$replacement['id'].'/verify', ['version' => $replacement['version']])->assertOk();
        $default = DB::table('business_bank_accounts')->where('is_default', 1)->pluck('id')->all();
        $this->assertSame([(int) $replacement['id']], $default, 'exactly one default account');
        $this->assertEqualsCanonicalizing(['SETTLEMENT_BANK_SUBMITTED', 'SETTLEMENT_BANK_REPLACEMENT_SUBMITTED', 'BANK_VERIFIED_SIMULATION_ONLY'], array_values(array_unique(DB::table('audit_logs')->whereIn('action', ['SETTLEMENT_BANK_SUBMITTED', 'SETTLEMENT_BANK_REPLACEMENT_SUBMITTED', 'BANK_VERIFIED_SIMULATION_ONLY'])->orderBy('id')->pluck('action')->all())));
    }

    // ---- request -> approve -> MOCK ---------------------------------------------------------------------------

    public function test_payout_request_approval_and_mock_processing_post_once_and_respect_balance_idempotency_and_roles(): void
    {
        [$payable, $account] = $this->funded();
        $this->assertSame(300000, (int) $payable->amount_vnd);
        $this->assertSame(['available' => '300000', 'locked' => '0', 'completed' => '0'], $this->balances());
        $key = 'withdrawal-request-0001';
        $this->ask('300001', $account, $key)->assertStatus(409);
        $this->withHeader('Idempotency-Key', 'short')->postJson($this->api('/withdrawals'), ['amountVnd' => '1000', 'accountId' => $account['id']])->assertStatus(422);
        $this->ask('0', $account)->assertStatus(422);
        $this->ask('120000', ['id' => 999999])->assertStatus(409);
        $request = $this->ask('120000', $account, $key)->assertCreated()->json();
        $this->assertSame(['PENDING_APPROVAL', 'LIVE_LAB', 0], [$request['status'], $request['walletScope'], $request['version']]);
        $this->assertSame($request['id'], $this->ask('120000', $account, $key)->json('id'));
        $this->ask('110000', $account, $key)->assertStatus(409);
        $this->assertSame(1, DB::table('business_withdrawals')->count());
        $this->assertSame(['available' => '180000', 'locked' => '120000', 'completed' => '0'], $this->balances());
        $this->ask('180001', $account)->assertStatus(409);

        $this->actor('owner');
        $this->postJson("/api/v1/wallet/admin/withdrawals/{$request['id']}/approve", ['version' => 0])->assertForbidden();
        $this->getJson('/api/v1/wallet/admin/withdrawals')->assertForbidden();
        foreach (['owner2', 'receptionist', 'customer'] as $actor) {
            $this->actor($actor);
            $this->postJson("/api/v1/wallet/admin/withdrawals/{$request['id']}/approve", ['version' => 0])->assertForbidden();
            $this->postJson($this->api("/withdrawals/{$request['id']}/cancel"), ['version' => 0, 'reason' => 'x'])->assertForbidden();
        }
        $this->decide((int) $request['id'], 'approve', 7)->assertStatus(409);
        $this->decide((int) $request['id'], 'approve', 0, ['bogus' => 1])->assertStatus(400);
        $done = $this->decide((int) $request['id'], 'approve', 0)->assertOk()->json();
        $this->assertSame('SIMULATED_COMPLETED', $done['status']);
        $this->assertSame(['MOCK', 'MOCK-'.$request['id']], [$done['attempts'][0]['provider'], $done['attempts'][0]['reference']]);
        // A lost response is retried with the stale version: it resumes the finished command, it does not pay again.
        $this->decide((int) $request['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->decide((int) $request['id'], 'retry', 0)->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->decide((int) $request['id'], 'reject', 2, ['reason' => 'late'])->assertStatus(409);
        $transfer = DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->get();
        $this->assertSame([1, 'withdrawal:'.$request['id'], 120000, null], [$transfer->count(), $transfer[0]->operation_key, (int) $transfer[0]->amount_vnd, $transfer[0]->wallet_id]);
        $this->assertSame(1, DB::table('withdrawal_attempts')->count());
        $this->assertSame(['available' => '180000', 'locked' => '0', 'completed' => '120000'], $this->balances());
        // The admin queue and the owner's list show it; another scope is a separate book.
        $this->actor('admin');
        $this->assertSame([$request['id']], array_column($this->getJson('/api/v1/wallet/admin/withdrawals?status=SIMULATED_COMPLETED')->json('items'), 'id'));
        $this->actor('owner');
        $this->assertSame(['0', '0', '0'], array_values($this->getJson($this->api('/withdrawals?scope=DEMO'))->json('balances')));
        $this->ask('1000', $account, null, 'DEMO')->assertStatus(409);
    }

    public function test_rejected_and_cancelled_requests_return_funds_and_a_failed_payout_can_be_retried(): void
    {
        [, $account] = $this->funded();
        $first = $this->ask('300000', $account)->assertCreated()->json();
        $this->assertSame('0', $this->balances()['available']);
        $this->actor('owner');
        $this->postJson($this->api("/withdrawals/{$first['id']}/cancel"), ['version' => 0])->assertUnprocessable();
        $this->postJson($this->api('/withdrawals/999999/cancel'), ['version' => 0, 'reason' => 'x'])->assertNotFound();
        $this->postJson($this->api("/withdrawals/{$first['id']}/cancel"), ['version' => 0, 'reason' => 'nhập nhầm'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('300000', $this->balances()['available']);

        $second = $this->ask('200000', $account)->assertCreated()->json();
        $this->decide((int) $second['id'], 'reject', 0)->assertUnprocessable();
        $this->decide((int) $second['id'], 'reject', 0, ['reason' => 'thiếu hồ sơ'])->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertSame('300000', $this->balances()['available']);

        $third = $this->ask('100000', $account)->assertCreated()->json();
        $calls = $this->countingProvider(false);
        $failed = $this->decide((int) $third['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'FAILED')->json();
        $this->assertSame([1, 0, '200000'], [$calls->count, DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count(), $this->balances()['available']], 'a failed payout keeps its funds reserved');
        $this->app->forgetInstance(MockPayoutProvider::class);
        $this->app->bind(MockPayoutProvider::class, fn () => new MockPayoutProvider());
        $this->decide((int) $third['id'], 'retry', $failed['version'])->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->assertSame([1, 2], [DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count(), DB::table('withdrawal_attempts')->where('withdrawal_id', $third['id'])->count()]);
        $this->assertSame(['available' => '200000', 'locked' => '0', 'completed' => '100000'], $this->balances());
    }

    // ---- payout x refund after release: requested -> refunded -> approve / retry / recover ------------------------

    public function test_a_refund_raised_after_the_request_blocks_approval_until_the_request_is_cancelled_and_refiled_for_the_net_amount(): void
    {
        [$payable, $account] = $this->funded();
        $withdrawal = $this->ask('300000', $account)->assertCreated()->json();
        $refund = $this->refundAfterRelease($payable, 100000);
        $untouched = function () use ($withdrawal) {
            $row = DB::table('business_withdrawals')->find($withdrawal['id']);
            $this->assertSame(['PENDING_APPROVAL', 0, 0, 0, 0], [$row->status, (int) $row->version, DB::table('withdrawal_attempts')->where('withdrawal_id', $row->id)->count(),
                DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count(), DB::table('withdrawal_allocations')->where('withdrawal_id', $row->id)->where('released', 1)->count()]);
        };
        $this->decide((int) $withdrawal['id'], 'approve', 0)->assertStatus(409);
        $untouched();
        // Even once the refund is paid the request cannot ship the refunded money.
        DB::table('deposit_refunds')->where('id', $refund)->update(['status' => 'CONFIRMED']);
        $this->decide((int) $withdrawal['id'], 'approve', 0)->assertStatus(409);
        $untouched();

        $this->actor('owner');
        $this->postJson($this->api("/withdrawals/{$withdrawal['id']}/cancel"), ['version' => 0, 'reason' => 'có hoàn tiền'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('200000', $this->balances()['available']);
        $this->ask('200001', $account)->assertStatus(409);
        $second = $this->ask('200000', $account)->assertCreated()->json();
        $this->decide((int) $second['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->assertSame([200000], DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->pluck('amount_vnd')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_refund_that_is_rejected_releases_its_hold_and_the_original_request_is_paid_in_full(): void
    {
        [$payable, $account] = $this->funded();
        $withdrawal = $this->ask('300000', $account)->assertCreated()->json();
        $refund = $this->refundAfterRelease($payable, 100000);
        $this->decide((int) $withdrawal['id'], 'approve', 0)->assertStatus(409);
        DB::table('deposit_refunds')->where('id', $refund)->update(['status' => 'REJECTED']);
        $this->decide((int) $withdrawal['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->assertSame([300000], DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->pluck('amount_vnd')->map(fn ($v) => (int) $v)->all());
    }

    public function test_a_retry_after_a_provider_failure_re_checks_the_source_funds_before_calling_the_provider_again(): void
    {
        [$payable, $account] = $this->funded();
        $withdrawal = $this->ask('300000', $account)->assertCreated()->json();
        $calls = $this->countingProvider(false);
        $failed = $this->decide((int) $withdrawal['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'FAILED')->json();
        $this->assertSame(1, $calls->count);
        $this->refundAfterRelease($payable, 100000);
        $this->decide((int) $withdrawal['id'], 'retry', $failed['version'])->assertStatus(409);
        $this->assertSame([1, 1, 0], [$calls->count, DB::table('withdrawal_attempts')->where('withdrawal_id', $withdrawal['id'])->count(), DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count()], 'the provider must not be called for money that is being refunded');
        $this->actor('owner');
        $this->postJson($this->api("/withdrawals/{$withdrawal['id']}/cancel"), ['version' => $failed['version'], 'reason' => 'có hoàn tiền'])->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertSame('200000', $this->balances()['available']);
    }

    public function test_recovery_of_an_interrupted_approval_never_pays_refunded_money_and_pays_exactly_once_when_intact(): void
    {
        [$payable, $account] = $this->funded();
        $calls = $this->countingProvider(true);
        $broken = $this->ask('300000', $account)->assertCreated()->json();
        DB::table('business_withdrawals')->where('id', $broken['id'])->update(['status' => 'PROCESSING', 'version' => 1]);
        $this->refundAfterRelease($payable, 100000);
        $this->assertSame(1, app(WithdrawalService::class)->recover());
        $row = DB::table('business_withdrawals')->find($broken['id']);
        $this->assertSame(['FAILED', 'SOURCE_FUNDS_CHANGED', 0, 0, 0], [$row->status, $row->reason, $calls->count, DB::table('withdrawal_attempts')->count(), DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count()]);
        $this->assertSame(0, app(WithdrawalService::class)->recover());
        $this->decide((int) $broken['id'], 'retry', 2)->assertStatus(409);
        $this->actor('owner');
        $this->postJson($this->api("/withdrawals/{$broken['id']}/cancel"), ['version' => 2, 'reason' => 'có hoàn tiền'])->assertOk();

        // Intact funds: recovery and a duplicate process call pay once.
        $this->assertSame('200000', $this->balances()['available']);
        $fine = $this->ask('70000', $account)->assertCreated()->json();
        DB::table('business_withdrawals')->where('id', $fine['id'])->update(['status' => 'PROCESSING', 'version' => 1]);
        $this->assertSame(1, app(WithdrawalService::class)->recover());
        $this->assertSame(0, app(WithdrawalService::class)->recover());
        app(WithdrawalService::class)->process((int) $fine['id']);
        $this->assertSame(['SIMULATED_COMPLETED', 1, 1], [DB::table('business_withdrawals')->find($fine['id'])->status, $calls->count, DB::table('wallet_transfers')->where('kind', 'SETTLEMENT')->count()]);
    }

    public function test_a_refund_after_a_completed_payout_is_recovered_from_later_payables_instead_of_being_forgotten(): void
    {
        [$payable, $account] = $this->funded();
        $withdrawal = $this->ask('300000', $account)->assertCreated()->json();
        $this->decide((int) $withdrawal['id'], 'approve', 0)->assertOk()->assertJsonPath('status', 'SIMULATED_COMPLETED');
        $this->refundAfterRelease($payable, 100000, 'CONFIRMED');
        $this->assertSame('0', $this->balances()['available']);
        // Another released deposit of 300.000 arrives: 100.000 of it covers the refund the business already took payment for.
        $this->releasedPayable(1000000);
        $this->assertSame('200000', $this->balances()['available']);
        $this->ask('200001', $account)->assertStatus(409);
        $this->ask('200000', $account)->assertCreated();
        $this->assertSame(['available' => '0', 'locked' => '200000', 'completed' => '300000'], $this->balances());
    }

    public function test_the_deposit_lifecycle_feeds_payouts_only_after_release(): void
    {
        $this->fund(120000);
        $held = $this->booking(400000);
        $this->heldDeposit($held);
        $account = $this->bank();
        $this->assertSame('0', $this->balances()['available'], 'a held deposit is not payable');
        $this->actor('owner');
        $summary = $this->getJson($this->api())->assertOk()->json();
        $this->assertSame(['120000', 'LIVE_LAB', true], [$summary['heldVnd'], $summary['walletScope'], $summary['walletMode']]);
        $this->ask('1000', $account)->assertStatus(409);
        DB::transaction(fn () => \App\Services\Wallet\WalletCore::transition($held->id, 'COMPLETED', ['now' => now('UTC')]));
        $this->assertSame('120000', $this->balances()['available']);
        $this->actor('owner');
        $payables = $this->getJson($this->api())->json('payables');
        $this->assertSame([['COMPLETED_SERVICE', 'APPLIED', '120000', $held->booking_code]], array_map(fn ($p) => [$p['reason'], $p['depositStatus'], $p['amountVnd'], $p['bookingCode']], $payables));
    }
}
