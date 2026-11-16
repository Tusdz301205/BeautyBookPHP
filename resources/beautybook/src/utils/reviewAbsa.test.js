import test from 'node:test';
import assert from 'node:assert/strict';
import {ABSA_ASPECTS, attentionAspect, mentionedAspects, periodRange, segments, validateAbsaReviews, validateAbsaSummary} from './reviewAbsa.js';

const scope = {businessId: '12', branchId: '34'};
const summary = () => ({scope, store: {ready: true},
  totals: {reviews: 4, withText: 3, noText: 1, analyzed: 2, processing: 1, failed: 0, notAnalyzed: 0},
  sentimentReviews: {withPositive: 1, withNegative: 1, noAspect: 1, denominator: 2},
  aspects: ABSA_ASPECTS.map(aspect => ({aspect, counts: {NOT_MENTIONED: 1, POS: 1, NEG: 0, NEU: 0}, mentioned: 1, positiveShare: 1, negativeShare: 0, neutralShare: 0}))});

test('summary contract accepts local string IDs and rejects foreign scope or invalid counts', () => {
  assert.equal(validateAbsaSummary(summary(), scope).totals.analyzed, 2);
  assert.throws(() => validateAbsaSummary(summary(), {...scope, branchId: '35'}));
  const invalid = summary(); invalid.totals.withText = 2;
  assert.throws(() => validateAbsaSummary(invalid, scope));
  const labels = summary(); labels.aspects[0].counts.NOT_MENTIONED = 2;
  assert.throws(() => validateAbsaSummary(labels, scope));
});

test('review contract keeps analysis states distinct and requires the exact four labels', () => {
  const list = {items: [{id: '1', absa: {status: 'PROCESSING', labels: null}}], total: 1, page: 1, limit: 10};
  assert.equal(validateAbsaReviews(list), list);
  list.items[0].absa = {status: 'ANALYZED', labels: Object.fromEntries(ABSA_ASPECTS.map(a => [a, 'NOT_MENTIONED']))};
  assert.equal(validateAbsaReviews(list), list);
  list.items[0].absa.labels.PRICE = 'MIXED';
  assert.throws(() => validateAbsaReviews(list));
  list.items[0].absa = {status: 'FAILED', labels: {PRICE: 'NEG'}};
  assert.throws(() => validateAbsaReviews(list));
});

test('mention shares exclude NOT_MENTIONED and complaint attention needs enough samples', () => {
  const counts = {NOT_MENTIONED: 100, POS: 1, NEU: 1, NEG: 2};
  assert.deepEqual(segments(counts).map(s => s.width), [25, 25, 50]);
  assert.deepEqual(mentionedAspects({SERVICE_QUALITY: 'NOT_MENTIONED', STAFF_ATTITUDE: 'NOT_MENTIONED', WAIT_TIME: 'NEG', PRICE: 'POS'}),
    [{aspect: 'WAIT_TIME', label: 'NEG'}, {aspect: 'PRICE', label: 'POS'}]);
  assert.equal(attentionAspect([{aspect: 'PRICE', counts, mentioned: 4}]), null);
  assert.equal(attentionAspect([{aspect: 'PRICE', counts: {...counts, POS: 2}, mentioned: 5}]), 'PRICE');
});

test('period presets include today in Vietnam at the UTC day boundary', () => {
  assert.deepEqual(periodRange('7d', new Date('2026-11-01T17:00:00Z')), {from: '2026-10-27', to: '2026-11-02'});
  assert.deepEqual(periodRange('all'), {from: '', to: ''});
});
