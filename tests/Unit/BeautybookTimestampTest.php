<?php

namespace Tests\Unit;

use App\Services\Beautybook\GovernancePresenter;
use App\Services\Beautybook\ManagementPresenter;
use Tests\TestCase;

class BeautybookTimestampTest extends TestCase
{
    public function test_source_dtos_keep_audit_instants_and_utc_transfer_effective_time_on_vietnamese_clients(): void
    {
        config(['app.timezone' => 'Asia/Ho_Chi_Minh']);
        $dto = app(GovernancePresenter::class)->row(['created_at' => '2026-10-07 18:30:00', 'effective_at' => '2026-10-08 03:00:00', 'birth_date' => '2000-01-02']);
        $this->assertSame('2026-10-07T11:30:00.000000Z', $dto['createdAt']);
        $this->assertSame('2026-10-08T03:00:00.000000Z', $dto['effectiveAt']);
        $this->assertSame('2000-01-02', $dto['birthDate']);
        $management = app(ManagementPresenter::class)->camel(['audit_trail' => [['created_at' => '2026-10-07 18:30:00']]]);
        $this->assertSame($dto['createdAt'], $management['auditTrail'][0]['createdAt']);
    }
}
