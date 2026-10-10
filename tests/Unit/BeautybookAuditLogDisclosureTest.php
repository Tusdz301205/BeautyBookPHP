<?php

namespace Tests\Unit;

use App\Services\Beautybook\AuditLogDisclosure;
use PHPUnit\Framework\TestCase;

class BeautybookAuditLogDisclosureTest extends TestCase
{
    public function test_restricts_arbitrary_audit_payloads_to_explicit_state_fields(): void
    {
        $changes = AuditLogDisclosure::changes([
            'reason' => 'Private customer phone 0900000000',
            'before' => ['status' => 'PENDING', 'password' => 'old-secret', 'nested' => ['token' => 'old-token']],
            'after' => ['status' => 'CONFIRMED', 'token' => 'new-token', 'email' => 'customer@example.test'],
            'authorization' => 'Bearer private-token',
        ]);

        $this->assertSame(['reason' => null, 'oldData' => ['status' => 'PENDING'], 'newData' => ['status' => 'CONFIRMED']], $changes);
        $this->assertStringNotContainsString('secret', json_encode($changes, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('customer@example.test', json_encode($changes, JSON_THROW_ON_ERROR));
    }

    public function test_preserves_safe_finance_state_without_exposing_notes(): void
    {
        $this->assertSame([
            'reason' => null,
            'oldData' => null,
            'newData' => ['from_status' => 'DRAFT', 'to_status' => 'APPROVED'],
        ], AuditLogDisclosure::changes(['from_status' => 'DRAFT', 'to_status' => 'APPROVED', 'reason' => 'private bank details']));
    }

    public function test_handles_unexpected_metadata_types_without_leaking_values(): void
    {
        $this->assertSame(['reason' => null, 'oldData' => null, 'newData' => null], AuditLogDisclosure::changes('not-json'));
        $this->assertSame(['reason' => null, 'oldData' => null, 'newData' => null], AuditLogDisclosure::changes(['after' => ['status' => ['token' => 'hidden']]]));
    }
}
