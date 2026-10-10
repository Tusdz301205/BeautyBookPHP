import test from 'node:test';
import assert from 'node:assert/strict';
import { createCoalescedRefresh } from './coalescedRefresh.js';
function fixture(refresh) {
  let index = 0; const timers = new Map();
  const queue = createCoalescedRefresh(refresh, { setTimer: callback => { timers.set(++index, callback); return index; }, clearTimer: id => timers.delete(id) });
  const flush = () => { const [id, callback] = timers.entries().next().value; timers.delete(id); return callback(); };
  return { queue, timers, flush };
}
test('100 invalidations in a burst fetch once, without losing a trailing event during the fetch', async () => {
  let calls = 0, release;
  const first = new Promise(resolve => { release = resolve; });
  const { queue, timers, flush } = fixture(async () => { calls++; if (calls === 1) await first; });
  for (let n = 0; n < 100; n++) queue.request();
  assert.equal(timers.size, 1);
  const pending = flush(); assert.equal(calls, 1);
  for (let n = 0; n < 100; n++) queue.request();
  assert.equal(timers.size, 0);
  release(); await pending; assert.equal(timers.size, 1);
  await flush(); assert.equal(calls, 2); assert.equal(timers.size, 0);
});
test('cleanup discards an old scope pending refresh', () => {
  let calls = 0; const { queue, timers } = fixture(() => { calls++; });
  queue.request(); queue.cancel(); queue.request();
  assert.equal(timers.size, 0); assert.equal(calls, 0);
});
test('unexpected refresh rejection is not converted into silent success', async () => {
  const { queue, flush } = fixture(async () => { throw new Error('unexpected failure'); });
  queue.request(); await assert.rejects(flush(), /unexpected failure/);
});
