# FinanceImport mapping

`App\Services\GlowMeImport\FinanceImport::run(ImportContext $c): void` uses only
`source`, `id`, `insert`, and `note`. No common contract changes are required.
Run after identity/customer profiles, businesses/branches, bookings, and media.
The root importer owns transactions, raw archival, normalization, and constraints.

## Native records

- Wallets, top-ups, transfers, booking deposits and deposit refunds retain source
  money amounts, versions, snapshots, state and deterministic IDs. Monetary values
  must be exact nonnegative integer strings or PHP integers; floats are rejected.
  No balance calculation or payment service is invoked.
- FK keys always use source table names, including `customer_profiles` and `users`.
  Wallet/business account strings and recognized `hold`, `resolve`, `topup`,
  `settled`, and `withdrawal` operation keys translate their embedded source IDs.
  Other opaque keys and snapshots retain source values.
- Bank accounts and simulated settlements map directly when compatible with target
  constraints. No new bank account, payout, or ledger movement is synthesized.
- All payables, withdrawals, allocations, attempts and events import natively,
  retaining source amounts, statuses and reservation/release flags. Withdrawal
  accounts reuse an existing source account only when the business and all four
  bank snapshot fields match exactly. Otherwise each withdrawal receives a
  historical DISABLED, non-default account with ID equal to the source bank account
  count plus its mapped withdrawal ID. No payment amount is inferred. Whole bank
  snapshots retain all original fields, including source account identifiers.
  Source allocation timestamps do not exist and remain null in the target.
- Payment connections import disabled, SETUP, with an empty encrypted secret and
  no verified date. UUID-bound source ciphertext is not usable with integer IDs.
  Fresh credentials and verification are required before activation.
- SePay receipts retain completed APPLIED/TOPUP_APPLIED outcomes. Other processing
  states become IMPORTED to keep them outside automatic processing. The source
  states remain in the raw archive. The receipt/top-up FK cycle is resolved by
  inserting receipt-free top-ups first, receipts second, receipt-linked top-ups
  last. Candidate receipt links to deferred top-ups are omitted and counted in
  notes; the authoritative top-up bank_event_id remains intact. No FK is disabled.
- All chat conversations, attachments and messages map natively. Each attachment
  creates a private media_file on disk `local`, path `glowme/chat/{storage_key}`,
  ID equal to the source media_files count plus its mapped chat_attachments ID.
  Hash, size, MIME, uploader and timestamps come from the attachment. Media
  entity_type is ChatAttachment and entity_id is the conversation ID, matching
  ChatService. Root must reserve this derived media ID range and copy/verify bytes
  separately. Conversation sequence/read cursors retain source values.
- Audit actor/business IDs translate; occurred_at becomes target created_at.
  Metadata wraps source metadata, branch UUID and original timestamps. Polymorphic
  entity IDs remain source identifiers; no entity type association is guessed.

## Absent contracts and incompatibilities

Source withdrawals have no bank_account_id; the snapshot-derived historical
accounts above supply this target-only relationship without changing snapshots.
Missing required bank snapshot fields stop the import with a PII-free error and
note; payout history is never silently omitted. Target constraint failures also
stop the import without truncating fields or weakening constraints. No payout or
money movement is replayed.

The source Prisma schema has no payment_transactions, payment_ledger_entries,
native cash receipts, invoices, payment policies or platform_settings tables.
The read-only 73-table export also contains no settings table.
Deposits and store-received snapshots are not promoted to those different contracts.
This class does not import or deliver notification, push, or webhook outboxes.

## Validation

PHP syntax and project Pint checks; snapshot inspection and an in-memory context
exercise against the read-only source export. No Laravel bootstrap, migration,
database query or database mutation is performed. In-memory validation does not
replace a later root-owned constrained import and reconciliation.

The current export passes complete row coverage for the imported history:
40 withdrawals, 47 allocations, 16 attempts, 88 events, 24 payables, three chat
attachments, 77 messages and 11,054 audit records. Checks cover parent insertion
order, native payout status values, derived ID uniqueness, unchanged withdrawal
snapshots and amounts, and exact existing bank account reuse. Forty disabled
historical accounts and three private attachment media records are generated.
