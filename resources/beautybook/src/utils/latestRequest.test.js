import test from 'node:test';
import assert from 'node:assert/strict';
import { createLatestRequest } from './latestRequest.js';

const deferred = () => {
  let resolve;
  let reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return { promise, resolve, reject };
};

test('switching booking date cannot replace current slots with a slower previous response', async () => {
  const gate = createLatestRequest();
  const first = deferred();
  const second = deferred();
  const received = [];
  const oldRequest = gate.run(() => first.promise, (data) => received.push(data), assert.fail);
  const currentRequest = gate.run(() => second.promise, (data) => received.push(data), assert.fail);
  second.resolve(['2026-09-08T10:00:00']);
  await currentRequest;
  first.resolve(['2026-09-07T09:00:00']);
  await oldRequest;
  assert.deepEqual(received, [['2026-09-08T10:00:00']]);
});

test('an outdated price failure cannot clear a successfully recalculated quote', async () => {
  const gate = createLatestRequest();
  const first = deferred();
  const errors = [];
  const quotes = [];
  const oldRequest = gate.run(() => first.promise, (value) => quotes.push(value), (error) => errors.push(error));
  await gate.run(async () => ({ finalAmount: 200000 }), (value) => quotes.push(value), (error) => errors.push(error));
  first.reject(new Error('Old request failed'));
  await oldRequest;
  assert.deepEqual(quotes, [{ finalAmount: 200000 }]);
  assert.deepEqual(errors, []);
});

test('leaving a booking page invalidates both pending success and failure callbacks', async () => {
  for (const fail of [false, true]) {
    const gate = createLatestRequest();
    const pending = deferred();
    const callback = () => assert.fail('A disposed page must not receive a result');
    const request = gate.run(() => pending.promise, callback, callback);
    gate.invalidate();
    if (fail) pending.reject(new Error('Request failed')); else pending.resolve({});
    await request;
  }
});

test('the current availability error is delivered so the user can retry', async () => {
  const gate = createLatestRequest();
  const error = new Error('Unavailable');
  let received;
  await gate.run(async () => { throw error; }, assert.fail, (value) => { received = value; });
  assert.equal(received, error);
});
