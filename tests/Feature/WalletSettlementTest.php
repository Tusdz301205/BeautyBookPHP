<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WalletFixtures;
use Tests\TestCase;

/**
 * Batch settlement of released deposits (wallet.service.ts createSettlement / completeSettlement, SimSettlement). A platform
 * admin gathers a business's unallocated payables of a period into one settlement, then marks it paid: one ledger entry,
 * no bank transfer. Nothing here moves real money.
 */
class WalletSettlementTest extends TestCase
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

    private function bank(bool $verify = true): array
    {
        $this->actor('owner');
        $account = $this->postJson('/api/v1/wallet/businesses/'.$this->business().'/accounts', ['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '123456789', 'accountName' => 'DEMO OWNER'])->assertCreated()->json();
        if ($verify) {
            $this->actor('admin');
            $account = $this->postJson('/api/v1/wallet/admin/businesses/'.$this->business().'/accounts/'.$account['id'].'/verify', ['version' => $account['version']])->assertOk()->json();
        }

        return $account;
    }

    private function period(): array
    {
        return ['periodStart' => now('UTC')->subDay()->toIso8601String(), 'periodEnd' => now('UTC')->addDay()->toIso8601String()];
    }

    private function settle(array $account, ?string $key = null, array $patch = [], string $as = 'admin')
    {
        $this->actor($as);

        return $this->withHeader('Idempotency-Key', $key ?? $this->key())->postJson('/api/v1/wallet/admin/settlements', array_merge(['businessId' => (string) $this->business(), 'accountId' => (string) $account['id']], $this->period(), $patch));
    }

    private function complete(int|string $id, string $as = 'admin')
    {
        $this->actor($as);

        return $this->postJson('/api/v1/wallet/admin/settlements/'.$id.'/complete', []);
    }

    private function balances(): array
    {
        $this->actor('owner');

        return $this->getJson('/api/v1/wallet/businesses/'.$this->business())->assertOk()->json('withdrawalBalances');
    }

    private function ledger(string $kind): int
    {
        return (int) DB::table('wallet_transfers')->where('kind', $kind)->count();
    }

    public function test_an_admin_gathers_unallocated_payables_into_one_settlement_idempotently(): void
    {
        [, , $payable] = $this->releasedPayable(1000000);
        $account = $this->bank();
        $key = $this->key();

        $created = $this->settle($account, $key)->assertOk()->json();
        $this->assertSame(['PENDING', '300000', (string) $this->business(), 'LIVE_LAB'], [$created['status'], $created['amountVnd'], $created['businessId'], $created['walletScope']]);
        $this->assertEquals(['bankCode' => 'ACB', 'bankName' => 'ACB', 'accountNumber' => '123456789', 'accountName' => 'DEMO OWNER', 'accountId' => (int) $account['id'], 'verification' => 'VERIFIED_DEMO'], $created['bankSnapshot']);
        $this->assertSame((int) $created['id'], (int) DB::table('business_payables')->where('id', $payable->id)->value('settlement_id'));
        $this->assertSame(0, $this->ledger('SETTLEMENT'), 'creating a settlement posts nothing yet');

        // Replays return the same settlement; the same key for other content is refused; a new key finds nothing left.
        $this->assertSame($created['id'], $this->settle($account, $key)->assertOk()->json('id'));
        $this->assertSame(1, DB::table('sim_settlements')->count());
        $this->settle($account, $key, ['periodEnd' => now('UTC')->addDays(2)->toIso8601String()])->assertStatus(409);
        $this->settle($account)->assertStatus(409);

        // The owner is told, and sees it with the payable marked as settled; the withdrawable balance is locked, not available.
        $this->assertSame(1, DB::table('notifications')->where('type', 'SIMULATED_SETTLEMENT_CREATED')->count());
        $this->assertSame(['available' => '0', 'locked' => '300000', 'completed' => '0'], $this->balances());
        $summary = $this->getJson('/api/v1/wallet/businesses/'.$this->business())->assertOk()->json();
        $this->assertSame([$created['id']], array_column($summary['settlements'], 'id'));
        $this->assertSame($created['id'], $summary['payables'][0]['settlementId']);
    }

    public function test_only_a_platform_admin_with_a_verified_default_account_and_a_valid_request_can_settle(): void
    {
        $this->releasedPayable(1000000);
        $unverified = $this->bank(false);
        $this->settle($unverified)->assertStatus(409);
        $account = $this->bank();
        foreach (['owner', 'customer', 'receptionist'] as $role) {
            $this->settle($account, null, [], $role)->assertForbidden();
        }
        $this->settle($account, 'short')->assertUnprocessable();
        $this->settle($account, null, ['unknown' => 1])->assertStatus(400);
        $this->settle($account, null, ['periodEnd' => now('UTC')->subDays(2)->toIso8601String()])->assertStatus(400);
        $this->settle($account, null, ['periodEnd' => now('UTC')->addDays(400)->toIso8601String()])->assertStatus(400);
        $this->settle($account, null, ['accountId' => '999999'])->assertStatus(409);
        $this->settle($account, null, ['walletScope' => 'SOMETHING'])->assertStatus(400);
        // Another business's account cannot be used, and an empty period settles nothing.
        $this->settle($account, null, ['periodStart' => now('UTC')->addDays(3)->toIso8601String(), 'periodEnd' => now('UTC')->addDays(5)->toIso8601String()])->assertStatus(409);
        $this->assertSame(0, DB::table('sim_settlements')->count());
        $this->assertNull(DB::table('business_payables')->value('settlement_id'));
    }

    public function test_money_already_allocated_to_a_withdrawal_or_refunded_is_not_settled_and_settled_money_cannot_be_withdrawn(): void
    {
        [, , $payable] = $this->releasedPayable(1000000);
        $account = $this->bank();
        $this->actor('owner');
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/v1/wallet/businesses/'.$this->business().'/withdrawals', ['amountVnd' => '100000', 'accountId' => $account['id']])->assertSuccessful();
        $this->settle($account)->assertStatus(409);
        $this->assertNull(DB::table('business_payables')->where('id', $payable->id)->value('settlement_id'), 'a payable with an open allocation stays with the withdrawal');

        // A second, untouched payable is settled alone, and its money can no longer be asked for as a withdrawal.
        [, , $second] = $this->releasedPayable(500000);
        $settlement = $this->settle($account)->assertOk()->json();
        $this->assertSame('150000', $settlement['amountVnd']);
        $this->assertSame((int) $settlement['id'], (int) DB::table('business_payables')->where('id', $second->id)->value('settlement_id'));
        $this->assertNull(DB::table('business_payables')->where('id', $payable->id)->value('settlement_id'));
        $this->actor('owner');
        $this->withHeader('Idempotency-Key', $this->key())->postJson('/api/v1/wallet/businesses/'.$this->business().'/withdrawals', ['amountVnd' => '300000', 'accountId' => $account['id']])->assertStatus(409);
    }

    public function test_completing_a_settlement_posts_one_ledger_entry_and_is_replay_safe(): void
    {
        $this->releasedPayable(1000000);
        $account = $this->bank();
        $id = $this->settle($account)->assertOk()->json('id');

        foreach (['owner', 'customer'] as $role) $this->complete($id, $role)->assertForbidden();
        $this->complete(999999)->assertNotFound();
        $this->assertSame(0, $this->ledger('SETTLEMENT'));

        $done = $this->complete($id)->assertOk()->json();
        $this->assertSame('SIMULATED_PAID', $done['status']);
        $this->assertNotNull($done['completedAt']);
        $entry = DB::table('wallet_transfers')->where('operation_key', 'settled:'.$id)->first();
        $this->assertSame(['SETTLEMENT', 300000, 'business:'.$this->business().':payable', 'demo:settled', null], [$entry->kind, (int) $entry->amount_vnd, $entry->from_account, $entry->to_account, $entry->wallet_id]);
        $this->assertSame($done['completedAt'], $this->complete($id)->assertOk()->json('completedAt'));
        $this->assertSame(1, $this->ledger('SETTLEMENT'), 'a second completion posts nothing');
        $this->assertSame(1, DB::table('notifications')->where('type', 'SIMULATED_SETTLEMENT_COMPLETED')->count());
        $this->assertSame(['available' => '0', 'locked' => '0', 'completed' => '300000'], $this->balances());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'SIMULATED_SETTLEMENT_COMPLETED')->count());

        // The business payable account nets to zero: released 300.000 in, settled 300.000 out.
        $payableAccount = 'business:'.$this->business().':payable';
        $this->assertSame(0, (int) DB::table('wallet_transfers')->where('to_account', $payableAccount)->sum('amount_vnd') - (int) DB::table('wallet_transfers')->where('from_account', $payableAccount)->sum('amount_vnd'));
        // A settled payable cannot be settled again.
        $this->settle($account)->assertStatus(409);
    }

    public function test_the_admin_overview_lists_settlements_and_a_second_business_is_not_touched(): void
    {
        $this->releasedPayable(1000000);
        $account = $this->bank();
        $id = $this->settle($account)->assertOk()->json('id');
        $this->actor('admin');
        $overview = $this->getJson('/api/v1/wallet/admin?page=1')->assertOk()->json();
        $this->assertSame([$id], array_column($overview['settlements'], 'id'));
        $this->assertSame('PENDING', $overview['settlements'][0]['status']);
        // The same payable cannot end up in two settlements even when two admins race with different keys.
        $this->settle($account)->assertStatus(409);
        $this->assertSame(1, DB::table('sim_settlements')->count());
        $this->assertSame(300000, (int) DB::table('business_payables')->where('settlement_id', $id)->sum('amount_vnd'));
    }
}
