<?php

namespace App\Services\Payout;

/** Internal-only adapter (mock-payout.provider.ts): no network call and no bank transaction reference. */
class MockPayoutProvider
{
    /** @return array{success:true,reference:string}|array{success:false,errorCode:string} */
    public function execute(int $withdrawalId, int $amountVnd, array $bankSnapshot): array
    {
        return ['success' => true, 'reference' => 'MOCK-'.$withdrawalId];
    }
}
