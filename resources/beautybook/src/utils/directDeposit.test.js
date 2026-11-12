import test from 'node:test';
import assert from 'node:assert/strict';
import { maskAccount, minutesToPay, paymentInstructions, refundStep, storePaymentView } from './directDeposit.js';

const now = Date.parse('2026-11-11T10:00:00Z');
const deposit = { fundingSource: 'DIRECT_SEPAY', status: 'AWAITING_PAYMENT', canPay: true, environment: 'test', bankCode: 'ACB', accountNumber: '0000000001', accountName: 'CONG TY', amountVnd: '54000', paymentCode: 'GM0123', expiresAt: '2026-11-11T10:20:00Z', qrUrl: null };

test('the customer sees where, how much and with which content to pay, with the minutes left', () => {
  assert.deepEqual(paymentInstructions(deposit, now), { bank: 'ACB', accountNumber: '0000000001', accountName: 'CONG TY', amountVnd: 54000, content: 'GM0123', qrUrl: null, testOnly: true, minutes: 20 });
  assert.equal(paymentInstructions({ ...deposit, environment: 'live', qrUrl: 'https://qr' }, now).testOnly, false);
  assert.equal(minutesToPay(deposit, Date.parse('2026-11-11T10:19:30Z')), 1);
});

test('nothing is offered once the deposit cannot be paid any more', () => {
  assert.equal(paymentInstructions({ ...deposit, canPay: false }, now), null);
  assert.equal(paymentInstructions({ ...deposit, status: 'PAID' }, now), null);
  assert.equal(paymentInstructions(deposit, Date.parse('2026-11-11T10:21:00Z')), null, 'past the deadline');
  assert.equal(minutesToPay(deposit, Date.parse('2026-11-11T10:21:00Z')), 0);
  assert.equal(paymentInstructions({ ...deposit, fundingSource: 'SIMULATED_WALLET' }, now), null);
  assert.equal(minutesToPay(null, now), null);
});

test('each party sees only its own next refund step, in order', () => {
  const pending = { status: 'PENDING', destination: null };
  assert.deepEqual(refundStep(pending, 'CUSTOMER'), { action: 'destination', waitingFor: null });
  assert.deepEqual(refundStep(pending, 'OWNER'), { action: null, waitingFor: 'CUSTOMER' });
  const declared = { status: 'PENDING', destination: { bankCode: 'ACB' } };
  assert.deepEqual(refundStep(declared, 'OWNER'), { action: 'report', waitingFor: null });
  assert.deepEqual(refundStep(declared, 'RECEPTIONIST'), { action: null, waitingFor: 'OWNER' }, 'a receptionist cannot report a transfer');
  assert.deepEqual(refundStep(declared, 'CUSTOMER'), { action: null, waitingFor: 'OWNER' });
  const reported = { status: 'REPORTED', destination: { bankCode: 'ACB' } };
  assert.deepEqual(refundStep(reported, 'CUSTOMER'), { action: 'confirm', waitingFor: null });
  assert.deepEqual(refundStep(reported, 'OWNER'), { action: null, waitingFor: 'CUSTOMER' });
  assert.deepEqual(refundStep({ status: 'CONFIRMED' }, 'CUSTOMER'), { action: null, waitingFor: null });
  assert.deepEqual(refundStep(null, 'CUSTOMER'), { action: null, waitingFor: null });
});

test('account numbers are masked except for the last four digits', () => {
  assert.equal(maskAccount('0123456789'), '••••••6789');
  assert.equal(maskAccount('123'), '123');
  assert.equal(maskAccount(null), '');
});

test('the payment book keeps each payment apart: own method, own reference, reversed ones marked, per-method totals', () => {
  const money = (value) => `${value.toLocaleString('vi-VN')}₫`;
  const payment = { storeCollectedVnd: '20000.00', pendingVnd: '0.00', collectableVnd: '96000.00', dueVnd: '106000.00', initialRemainingVnd: '126000.00', breakdown: [{ method: 'CASH', amountVnd: '20000.00' }],
    entries: [{ id: 1, method: 'CASH', status: 'VERIFIED', amountVnd: '30000.00', refundedVnd: '10000.00', reference: 'phieu 1', recordedAt: 'a', recordedBy: { name: 'Chủ' }, reversed: false },
      { id: 2, method: 'BANK_TRANSFER', status: 'VERIFIED', amountVnd: '96000.00', refundedVnd: '0.00', reference: 'FT-1', recordedAt: 'b', recordedBy: { name: 'Lễ tân' }, reversed: true }] };
  const view = storePaymentView(payment, money);
  assert.equal(view.collected, '20.000₫');
  assert.equal(view.collectable, '96.000₫');
  assert.equal(view.initialRemaining, '126.000₫', 'the remainder promised by the deposit terms is not the balance now');
  assert.equal(view.pending, null);
  assert.deepEqual(view.breakdown, ['tiền mặt 20.000₫']);
  assert.deepEqual(view.entries.map((e) => [e.method, e.reference, e.reversed, e.refunded]), [['tiền mặt', 'phieu 1', false, '10.000₫'], ['chuyển khoản', 'FT-1', true, null]]);
  assert.equal(storePaymentView(null, money), null);
  assert.equal(storePaymentView({ entries: [], breakdown: [], storeCollectedVnd: '0', collectableVnd: '0', dueVnd: '0', pendingVnd: '40000' }, money).pending, '40.000₫');
});
