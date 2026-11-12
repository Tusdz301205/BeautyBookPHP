<?php

namespace App\Services\GlowMeImport;

use UnexpectedValueException;

/** Imports stored financial evidence only; never calls a payment or messaging service. */
final class FinanceImport
{
    public function run(ImportContext $c): void
    {
        $this->copy($c, 'wallets', 'scope available_vnd held_vnd version created_at', [
            'customer_profile_id' => ['customer_id', 'customer_profiles'],
        ]);
        $this->copy($c, 'payment_connections', 'business_id environment bank_code account_number account_name verification_code version created_at', [
            'business_id' => ['business_id', 'businesses'],
        ], fn (array $r) => ['encrypted_secret' => '', 'enabled' => false, 'status' => 'SETUP', 'verified_at' => null]);
        $c->note('Finance: payment connections imported disabled in SETUP with empty secrets; source encryption is UUID-bound and cannot be reused with integer IDs. Reconfigure credentials before enabling.');

        $this->copy($c, 'booking_deposits', 'funding_source rate_bps payment_code total_vnd amount_vnd remaining_vnd status environment bank_code account_number account_name confirmation_mode pending_hold_minutes cancellation_hours expires_at paid_at store_received_at store_reference version created_at', [
            'booking_id' => ['booking_id', 'bookings'],
            'wallet_id' => ['wallet_id', 'wallets', true],
            'connection_id' => ['connection_id', 'payment_connections', true],
            'store_received_by_id' => ['store_received_by_id', 'users', true],
        ]);

        // The two receipt/top-up FKs form a cycle. Insert top-ups without an applied receipt
        // first, receipts second, and top-ups with an applied receipt last. No FK is disabled.
        $earlyTopUps = [];
        $lateTopUps = [];
        foreach ($c->source('wallet_topups') as $row) {
            $row = (array) $row;
            if (($row['bank_event_id'] ?? null) === null) {
                $this->topUp($c, $row);
                $earlyTopUps[(string) $row['id']] = true;
            } else {
                $lateTopUps[] = $row;
            }
        }
        $omittedCandidates = 0;
        $this->copy($c, 'sepay_webhook_events', 'environment provider_transaction_id channel gateway account_number sub_account transaction_at transfer_type amount_vnd content payment_code reference_code description status processing review_reason fingerprint payload_sha256 received_at reviewed_at', [
            'connection_id' => ['connection_id', 'payment_connections', true],
            'deposit_id' => ['deposit_id', 'booking_deposits', true],
        ], function (array $r) use ($c, $earlyTopUps, &$omittedCandidates): array {
            $topUp = $r['top_up_id'] ?? null;
            if ($topUp !== null && ! isset($earlyTopUps[(string) $topUp])) {
                $omittedCandidates++;
            }

            return [
                'top_up_id' => $topUp !== null && isset($earlyTopUps[(string) $topUp]) ? $this->id($c, 'wallet_topups', $topUp) : null,
                // Historical completed receipts retain their outcome. All actionable inbox
                // states are quarantined so no worker can credit an imported balance again.
                'processing' => in_array($r['processing'], ['APPLIED', 'TOPUP_APPLIED'], true) ? $r['processing'] : 'IMPORTED',
            ];
        });
        foreach ($lateTopUps as $row) {
            $this->topUp($c, $row);
        }
        $c->note('Finance: actionable SePay receipts quarantined as processing=IMPORTED; no webhook replay or delivery. Receipt candidate top_up_id omitted for '.$omittedCandidates.' cyclic/deferred links; applied wallet_topups.bank_event_id is preserved. Original inbox states and links remain in the raw archive.');

        $this->copy($c, 'deposit_refunds', 'source operation_key amount_vnd reason status destination reference reported_at confirmed_at version created_at', [
            'deposit_id' => ['deposit_id', 'booking_deposits'],
            'event_id' => ['event_id', 'sepay_webhook_events', true],
        ], fn (array $r) => ['operation_key' => $this->operationKey($c, $r['operation_key'] ?? null)]);
        $this->copy($c, 'business_bank_accounts', 'bank_code bank_name account_number account_name status is_default version created_at updated_at', [
            'business_id' => ['business_id', 'businesses'],
        ]);
        $this->copy($c, 'sim_settlements', 'wallet_scope operation_key fingerprint status amount_vnd bank_snapshot period_start period_end completed_at created_at', [
            'business_id' => ['business_id', 'businesses'], 'actor_id' => ['actor_id', 'users'],
        ]);
        $this->copy($c, 'business_payables', 'amount_vnd reason created_at', [
            'deposit_id' => ['deposit_id', 'booking_deposits'], 'business_id' => ['business_id', 'businesses'],
            'settlement_id' => ['settlement_id', 'sim_settlements', true],
        ]);
        $this->withdrawals($c);
        $this->copy($c, 'wallet_transfers', 'operation_key kind from_account to_account amount_vnd reason available_after_vnd held_after_vnd wallet_version created_at', [
            'wallet_id' => ['wallet_id', 'wallets', true], 'actor_id' => ['actor_id', 'users', true],
        ], fn (array $r) => [
            'operation_key' => $this->operationKey($c, $r['operation_key']),
            'from_account' => $this->account($c, $r['from_account']),
            'to_account' => $this->account($c, $r['to_account']),
        ]);
        $c->note('Finance: native withdrawals, allocations, attempts, events and all payables retain source amounts and statuses. Existing wallet transfer evidence is inserted without posting balances or replaying payouts.');

        $this->chat($c);
        $this->copy($c, 'audit_logs', 'action entity_type entity_id metadata', [
            'user_id' => ['actor_id', 'users', true], 'business_id' => ['business_id', 'businesses', true],
        ], fn (array $r) => [
            'created_at' => $r['occurred_at'],
            'metadata' => ['source_metadata' => $this->json($r['metadata'] ?? null), 'source_branch_uuid' => $r['branch_id'] ?? null,
                'source_created_at' => $r['created_at'], 'source_updated_at' => $r['updated_at']],
        ]);
        $c->note('Finance: audit entity_id remains a source identifier because entity_type is polymorphic free text; no guessed target link. occurred_at maps to created_at; source metadata, branch UUID and source timestamps are retained in metadata.');
        $c->note('Finance: source Prisma has no native payment transactions, payment ledger entries, cash receipts, invoices, payment policies or platform settings tables. None are fabricated from deposits, bank receipts or store_received fields. Notification/push outboxes are not imported or delivered by FinanceImport.');
    }

    private function topUp(ImportContext $c, array $row): void
    {
        $this->write($c, 'wallet_topups', $row, 'key_hash fingerprint code amount_vnd provider status expires_at completed_at receipt_identity receiver_snapshot created_at', [
            'wallet_id' => ['wallet_id', 'wallets'], 'bank_event_id' => ['bank_event_id', 'sepay_webhook_events', true],
        ]);
    }

    private function withdrawals(ImportContext $c): void
    {
        $accounts = $c->source('business_bank_accounts');
        $offset = count($accounts);
        $created = 0;
        foreach ($c->source('business_withdrawals') as $source) {
            $row = (array) $source;
            $snapshot = $this->json($row['bank_snapshot']);
            foreach (['bankCode', 'bankName', 'accountNumber', 'accountName'] as $field) {
                if (! is_array($snapshot) || ! isset($snapshot[$field]) || ! is_string($snapshot[$field])) {
                    $c->note('Finance: withdrawal bank_snapshot lacks a required native bank account field; import stops without omitting payout history.');
                    throw new UnexpectedValueException('Withdrawal snapshot missing bank account field '.$field);
                }
            }
            $accountId = null;
            foreach ($accounts as $account) {
                $account = (array) $account;
                if ($account['business_id'] === $row['business_id'] &&
                    $account['bank_code'] === $snapshot['bankCode'] && $account['bank_name'] === $snapshot['bankName'] &&
                    $account['account_number'] === $snapshot['accountNumber'] && $account['account_name'] === $snapshot['accountName']) {
                    $candidate = $this->id($c, 'business_bank_accounts', $account['id']);
                    $accountId = $accountId === null ? $candidate : min($candidate, $accountId);
                }
            }
            if ($accountId === null) {
                $accountId = $offset + $this->id($c, 'business_withdrawals', $row['id']);
                $c->insert('business_bank_accounts', [
                    'id' => $accountId, 'business_id' => $this->id($c, 'businesses', $row['business_id']),
                    'bank_code' => $snapshot['bankCode'], 'bank_name' => $snapshot['bankName'],
                    'account_number' => $snapshot['accountNumber'], 'account_name' => $snapshot['accountName'],
                    'status' => 'DISABLED', 'is_default' => false, 'version' => 0,
                    'created_at' => $row['created_at'], 'updated_at' => $row['created_at'],
                ]);
                $created++;
            }
            $this->write($c, 'business_withdrawals', $row, 'wallet_scope operation_key fingerprint amount_vnd bank_snapshot status version reviewed_at completed_at reason created_at updated_at', [
                'business_id' => ['business_id', 'businesses'], 'requested_by' => ['requested_by', 'users'],
                'reviewed_by' => ['reviewed_by', 'users', true],
            ], ['bank_account_id' => $accountId]);
        }
        $this->copy($c, 'withdrawal_allocations', 'amount_vnd released', [
            'withdrawal_id' => ['withdrawal_id', 'business_withdrawals'], 'payable_id' => ['payable_id', 'business_payables'],
        ]);
        $this->copy($c, 'withdrawal_attempts', 'sequence provider status reference error_code created_at', [
            'withdrawal_id' => ['withdrawal_id', 'business_withdrawals'],
        ]);
        $this->copy($c, 'withdrawal_events', 'from_status to_status reason created_at', [
            'withdrawal_id' => ['withdrawal_id', 'business_withdrawals'], 'actor_id' => ['actor_id', 'users', true],
        ]);
        $c->note('Finance: withdrawal accounts match source business and all four snapshot bank fields exactly; otherwise a historical DISABLED, non-default account is created. Historical account IDs = source bank account count + mapped withdrawal ID; created '.$created.'. Whole withdrawal bank snapshots, including source account identifiers, remain unchanged. Allocation timestamps are absent in source and remain null.');
    }

    private function chat(ImportContext $c): void
    {
        $this->copy($c, 'chat_conversations', 'last_sequence customer_read_sequence branch_read_sequence last_message_at created_at updated_at', [
            'customer_profile_id' => ['customer_id', 'customer_profiles'], 'branch_id' => ['branch_id', 'branches'],
        ]);
        $mediaOffset = count($c->source('media_files'));
        foreach ($c->source('chat_attachments') as $row) {
            $row = (array) $row;
            $mediaId = $mediaOffset + $this->id($c, 'chat_attachments', $row['id']);
            $c->insert('media_files', [
                'id' => $mediaId, 'disk' => 'local', 'path' => 'glowme/chat/'.$row['storage_key'],
                'mime_type' => $row['mime_type'], 'file_size' => $row['size_bytes'], 'sha256' => $row['sha256'],
                'uploaded_by' => $this->id($c, 'users', $row['uploader_id']),
                'entity_type' => 'ChatAttachment', 'entity_id' => $this->id($c, 'chat_conversations', $row['conversation_id']),
                'visibility' => 'PRIVATE', 'created_at' => $row['created_at'], 'updated_at' => $row['created_at'],
            ]);
            $this->write($c, 'chat_attachments', $row, 'mime_type size_bytes sha256 created_at', [
                'conversation_id' => ['conversation_id', 'chat_conversations'], 'uploader_user_id' => ['uploader_id', 'users'],
            ], ['media_file_id' => $mediaId]);
        }
        foreach ($c->source('chat_messages') as $row) {
            $row = (array) $row;
            $this->write($c, 'chat_messages', $row, 'sequence from_branch client_message_id body created_at', [
                'conversation_id' => ['conversation_id', 'chat_conversations'], 'sender_user_id' => ['sender_id', 'users'],
                'booking_id' => ['booking_id', 'bookings', true], 'attachment_id' => ['attachment_id', 'chat_attachments', true],
            ]);
        }
        $c->note('Finance/chat: all attachments have private native media_files on disk local, path glowme/chat/{source storage_key}, ID = source media_files count + mapped chat_attachments ID. Media entity_id is the conversation ID, matching ChatService. Root must copy and verify attachment bytes separately; no messages or attachments are omitted. Reserve this media ID range before creating any other derived media.');
    }

    private function copy(ImportContext $c, string $table, string $columns, array $links, ?callable $extra = null): void
    {
        foreach ($c->source($table) as $row) {
            $row = (array) $row;
            $this->write($c, $table, $row, $columns, $links, $extra ? $extra($row) : []);
        }
    }

    private function write(ImportContext $c, string $table, array $source, string $columns, array $links, array $extra = []): void
    {
        $row = ['id' => $this->id($c, $table, $source['id'])];
        foreach (explode(' ', $columns) as $column) {
            if (array_key_exists($column, $source)) {
                $row[$column] = $source[$column];
            }
        }
        foreach ($links as $target => $link) {
            [$column, $sourceTable] = $link;
            $value = $source[$column] ?? null;
            $row[$target] = $value === null && ($link[2] ?? false) ? null : $this->id($c, $sourceTable, $value);
        }
        $row = array_replace($row, $extra);
        foreach ($row as $column => $value) {
            if ($value !== null && (str_ends_with($column, '_vnd') || $column === 'provider_transaction_id')) {
                // Never round, multiply, cast through float, or accept already lossy JSON numbers.
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^\d+$/D', (string) $value)) {
                    throw new UnexpectedValueException('Finance import requires exact nonnegative integers: '.$table.'.'.$column);
                }
            }
        }
        if (! isset($row['updated_at']) && isset($row['created_at']) && ! in_array($table, ['wallet_transfers', 'chat_messages', 'audit_logs'], true)) {
            $row['updated_at'] = $row['created_at'];
        }
        $c->insert($table, $row);
    }

    private function id(ImportContext $c, string $table, mixed $uuid): int
    {
        $id = $uuid === null ? null : $c->id($table, $uuid);
        if ($id === null) {
            throw new UnexpectedValueException('Finance import missing source ID mapping for '.$table);
        }

        return $id;
    }

    private function operationKey(ImportContext $c, ?string $key): ?string
    {
        if ($key !== null && preg_match('/^(hold|resolve|topup|settled|withdrawal):([0-9a-f-]{36})$/iD', $key, $parts)) {
            $table = match ($parts[1]) {
                'hold', 'resolve' => 'booking_deposits', 'topup' => 'wallet_topups',
                'settled' => 'sim_settlements', 'withdrawal' => 'business_withdrawals',
            };

            return $parts[1].':'.$this->id($c, $table, $parts[2]);
        }

        return $key;
    }

    private function account(ImportContext $c, string $account): string
    {
        if (preg_match('/^(wallet|business):([^:]+):(available|held|payable)$/D', $account, $parts)) {
            return $parts[1].':'.$this->id($c, $parts[1] === 'wallet' ? 'wallets' : 'businesses', $parts[2]).':'.$parts[3];
        }

        return $account;
    }

    private function json(mixed $value): mixed
    {
        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING) : $value;
    }
}
