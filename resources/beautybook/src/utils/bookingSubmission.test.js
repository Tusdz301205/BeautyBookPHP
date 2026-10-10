import test from 'node:test';
import assert from 'node:assert/strict';
import { beginBookingSubmission, canReplayBookingSubmission, clearBookingSubmission, readBookingSubmission, REPLAY_WINDOW_MS, submitBookingSubmission, validBookingResult } from './bookingSubmission.js';

const payload = { branchId: 'qa-branch', serviceIds: ['qa-service'], appointmentDate: '2030-10-05T03:00:00Z', note: 'Synthetic QA' };
const booking = { id: 'qa-booking', bookingCode: 'QA-BOOKING', status: 'PENDING' };

test('lost response preserves exact payload/key across remount and editable wizard changes', async () => {
  const attempt = beginBookingSubmission('customer-a', 'single', payload);
  await submitBookingSubmission(attempt, async () => { throw new TypeError('Failed to fetch'); });
  const restored = readBookingSubmission('customer-a'); assert.equal(restored.status, 'unknown');
  const changed = beginBookingSubmission('customer-a', 'single', { ...payload, serviceIds: ['different'] });
  assert.deepEqual(changed, restored); assert.equal(changed.key, attempt.key);
  const result = await submitBookingSubmission(restored, async (body,key) => { assert.deepEqual(body,payload); assert.equal(key,attempt.key); return booking; });
  assert.equal(result.kind,'success'); assert.equal(readBookingSubmission('customer-a'),null);
});

test('double click and remounted component cannot dispatch overlapping writes', async () => {
  const attempt = beginBookingSubmission('customer-b', 'single', payload); let release;
  const first = submitBookingSubmission(attempt, () => new Promise(resolve => { release=resolve; }));
  const second = await submitBookingSubmission(readBookingSubmission('customer-b'), () => { throw new Error('Must not dispatch'); });
  assert.equal(second.kind,'busy'); release(booking); assert.equal((await first).kind,'success');
});

test('confirmed 4xx permits editing but a rejection after unknown cannot prove no commit', async () => {
  const rejection = Object.assign(new Error('Rejected'), { status: 400 });
  let a = beginBookingSubmission('customer-c', 'single', payload);
  assert.equal((await submitBookingSubmission(a, async () => { throw rejection; })).kind,'rejected');
  assert.equal(readBookingSubmission('customer-c'),null);
  a = beginBookingSubmission('customer-c', 'single', payload);
  await submitBookingSubmission(a, async () => { throw new Error('Network'); });
  const result = await submitBookingSubmission(readBookingSubmission('customer-c'), async () => { throw rejection; });
  assert.equal(result.kind,'unknown'); assert.equal(readBookingSubmission('customer-c').key,a.key); clearBookingSubmission('customer-c');
});

test('5xx, interrupted body, invalid success and in-flight idempotency conflict remain unknown', async () => {
  for (const error of [Object.assign(new Error('Unavailable'),{status:503}), new SyntaxError('JSON'), Object.assign(new Error('Request trùng đang được xử lý'),{status:409}), Object.assign(new Error('Không thể đọc lại lịch hẹn vừa tạo'),{status:409}), Object.assign(new Error('Timeout'),{status:408})]) {
    const attempt = beginBookingSubmission('customer-d','single',payload);
    assert.equal((await submitBookingSubmission(attempt,async()=>{throw error;})).kind,'unknown'); clearBookingSubmission('customer-d');
  }
  const attempt = beginBookingSubmission('customer-d','single',payload);
  assert.equal((await submitBookingSubmission(attempt,async()=>({}))).kind,'unknown'); clearBookingSubmission('customer-d');
});

test('account isolation, recurring and expired attempts cannot replay', () => {
  const a = beginBookingSubmission('customer-e','single',payload);
  assert.equal(readBookingSubmission('different-customer'),null);
  assert.equal(canReplayBookingSubmission(a,a.startedAt+100),true);
  assert.equal(canReplayBookingSubmission(a,a.startedAt+REPLAY_WINDOW_MS),false);
  assert.equal(canReplayBookingSubmission(a,a.startedAt-1),false);
  assert.equal(canReplayBookingSubmission({...a,kind:'recurring'}),false); clearBookingSubmission('customer-e');
});

test('success requires server identity, booking code and creation status', () => {
  assert.equal(validBookingResult(booking),true); assert.equal(validBookingResult({...booking,status:'CONFIRMED'}),true);
  for(const value of [null,{}, {...booking,bookingCode:null}, {...booking,status:'CANCELLED'}]) assert.equal(validBookingResult(value),false);
});

test('unknown recurring and expired single submissions never dispatch a replay', async () => {
  for (const kind of ['recurring','single']) {
    const attempt = {...beginBookingSubmission('customer-f',kind,payload), status:'unknown', startedAt:Date.now()-REPLAY_WINDOW_MS};
    const result = await submitBookingSubmission(attempt,()=>{throw new Error('Must not dispatch');});
    assert.equal(result.kind,'unknown'); clearBookingSubmission('customer-f');
  }
});

test('restored pending journal remains uncertain after a rejected replay', async () => {
  beginBookingSubmission('customer-g','single',payload);
  const restored=readBookingSubmission('customer-g'); assert.equal(restored.status,'unknown');
  const result=await submitBookingSubmission(restored,async()=>{throw Object.assign(new Error('Conflict'),{status:409});});
  assert.equal(result.kind,'unknown'); clearBookingSubmission('customer-g');
});
