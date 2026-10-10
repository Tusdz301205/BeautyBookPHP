import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { bindSchedulerSocketEvents } from './schedulerSocketEvents.js';

function fixture() {
  const socket = new EventEmitter();
  const calls = [];
  const unbind = bindSchedulerSocketEvents(socket, {
    refresh: () => calls.push('refresh'), clearScopedData: () => calls.push('clear'), authFailed: () => calls.push('auth-failed'),
  });
  return { socket, calls, unbind };
}
test('scope changes clear the previous scheduler data before fetching the new scope', () => {
  const { socket, calls } = fixture();
  socket.emit('scheduler_access_changed');
  assert.deepEqual(calls, ['clear', 'refresh']);
});
test('auth failure does not automatically retry into the rejected session', () => {
  const { socket, calls } = fixture();
  socket.emit('scheduler_auth_failed', { code: 'WS_AUTH_FAILED' });
  assert.deepEqual(calls, ['auth-failed']);
});
test('connect rejection clears protected data only for an authentication rejection, not an ordinary network error', () => {
  const { socket, calls } = fixture();
  socket.emit('connect_error', new Error('network offline'));
  assert.deepEqual(calls, []);
  socket.emit('connect_error', { data: { code: 'WS_AUTH_FAILED' } });
  assert.deepEqual(calls, ['auth-failed']);
});
test('reconnection and resync fetch any events missed during authorization checks', () => {
  const { socket, calls } = fixture();
  socket.emit('connect');
  socket.emit('scheduler_resync');
  assert.deepEqual(calls, ['refresh', 'refresh']);
});
test('booking events still refresh the scheduler and cleanup prevents late callbacks', () => {
  const { socket, calls, unbind } = fixture();
  for (const event of ['booking_created', 'booking_updated', 'booking_deleted']) socket.emit(event);
  assert.equal(calls.length, 3);
  unbind();
  socket.emit('booking_updated');
  socket.emit('scheduler_auth_failed');
  socket.emit('scheduler_access_changed');
  assert.equal(calls.length, 3);
});

test('authorization change refreshes the session model before any scoped data request', () => {
  const socket = new EventEmitter();
  const calls = [];
  bindSchedulerSocketEvents(socket, {
    refresh: () => calls.push('stale-data-request'),
    clearScopedData: () => calls.push('clear'),
    authFailed: () => calls.push('auth-failed'),
    authorizationChanged: () => calls.push('refresh-session-model'),
  });
  socket.emit('scheduler_access_changed');
  assert.deepEqual(calls, ['refresh-session-model']);
});
