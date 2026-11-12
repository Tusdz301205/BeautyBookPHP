import test from 'node:test';
import assert from 'node:assert/strict';
import { depositReadiness, depositSummary, seriesTerms } from './walletDeposit.js';

const terms = { totalVnd: 500000, depositVnd: 150000, remainingVnd: 350000, cancellationHours: 4, quote: 'q' };

test('without wallet mode nothing changes for the booking flow', () => {
  assert.deepEqual(depositReadiness({ walletMode: false, terms: null }), { ok: true, required: false, reason: null, shortfall: 0 });
});

test('a funded wallet inside the lab can book; a short one reports the exact shortfall', () => {
  assert.equal(depositReadiness({ walletMode: true, terms, wallet: { availableVnd: '150000' }, labAvailable: true }).ok, true);
  assert.deepEqual(depositReadiness({ walletMode: true, terms, wallet: { availableVnd: '100000' }, labAvailable: true }), { ok: false, required: true, reason: 'INSUFFICIENT_BALANCE', shortfall: 50000 });
});

test('accounts outside the lab and missing terms are blocked with a reason', () => {
  assert.equal(depositReadiness({ walletMode: true, terms, wallet: { availableVnd: '999999' }, labAvailable: false }).reason, 'NOT_IN_LAB');
  assert.equal(depositReadiness({ walletMode: true, terms: null, labAvailable: true }).reason, 'TERMS_UNAVAILABLE');
});

test('a zero deposit needs no wallet at all', () => {
  assert.deepEqual(depositReadiness({ walletMode: true, terms: { ...terms, depositVnd: 0 }, wallet: null, labAvailable: false }), { ok: true, required: false, reason: null, shortfall: 0 });
});

test('the summary splits the price into deposit and amount due at the branch', () => {
  assert.deepEqual(depositSummary(terms, { availableVnd: '200000' }), { total: 500000, deposit: 150000, remaining: 350000, availableAfter: 50000, cancellationHours: 4 });
});

test('a series holds one deposit per bookable occurrence and quotes are positional by occurrence', () => {
  const deposit = { depositVnd: 54000, totalVnd: 180000, remainingVnd: 126000, cancellationHours: 24, quote: 'a' };
  const preview = { walletDepositTotalVnd: 108000, occurrences: [{ available: true, walletDeposit: deposit }, { available: false, walletDeposit: null }, { available: true, walletDeposit: { ...deposit, quote: 'c' } }] };
  const series = seriesTerms(preview);
  assert.deepEqual({ ...series, rows: undefined }, { count: 2, depositVnd: 108000, perVnd: 54000, uniform: true, totalVnd: 360000, remainingVnd: 252000, cancellationHours: 24, rows: undefined, quotes: ['a', null, 'c'] });
  assert.deepEqual(series.rows.map((row) => row.index), [0, 2]);
  assert.equal(depositReadiness({ walletMode: true, terms: series, wallet: { availableVnd: '100000' }, labAvailable: true }).shortfall, 8000);
  assert.equal(depositReadiness({ walletMode: true, terms: seriesTerms({ walletDepositTotalVnd: 0, occurrences: [] }), wallet: null, labAvailable: false }).required, false);
});

test('a direct deposit is required without any wallet and needs the current terms', () => {
  assert.deepEqual(depositReadiness({ walletMode: false, directMode: true, terms }), { ok: true, required: true, direct: true, reason: null, shortfall: 0 });
  assert.deepEqual(depositReadiness({ walletMode: false, directMode: true, terms: null }), { ok: false, required: true, direct: true, reason: 'TERMS_UNAVAILABLE', shortfall: 0 });
  assert.deepEqual(depositReadiness({ walletMode: false, directMode: true, terms: { ...terms, depositVnd: 0 } }), { ok: true, required: false, reason: null, shortfall: 0 });
  assert.equal(depositReadiness({ walletMode: true, directMode: true, terms, wallet: { availableVnd: '0' }, labAvailable: true }).reason, 'INSUFFICIENT_BALANCE', 'wallet mode wins');
});

test('occurrences priced differently keep their own amounts and the series total is exactly their sum', () => {
  const at = (index, total, deposit) => ({ index, appointmentDate: `2026-11-${18 + index * 7}T10:00:00+07:00`, available: true, walletDeposit: { totalVnd: total, depositVnd: deposit, remainingVnd: total - deposit, cancellationHours: 4, quote: `q${index}` } });
  const preview = { walletDepositTotalVnd: 54000 + 66000, occurrences: [at(0, 180000, 54000), { index: 1, available: false, walletDeposit: null }, at(2, 220000, 66000)] };
  const series = seriesTerms(preview);
  assert.equal(series.uniform, false);
  assert.equal(series.perVnd, null);
  assert.deepEqual(series.rows.map((row) => [row.index, row.totalVnd, row.depositVnd]), [[0, 180000, 54000], [2, 220000, 66000]]);
  assert.equal(series.depositVnd, preview.walletDepositTotalVnd);
  assert.equal(series.totalVnd, 400000);
  assert.equal(series.remainingVnd, 280000);
  assert.deepEqual(series.quotes, ['q0', null, 'q2']);
  assert.equal(depositReadiness({ walletMode: true, terms: series, wallet: { availableVnd: '100000' }, labAvailable: true }).shortfall, 20000);
});
