import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function harness() {
  let userId = 'first', snapshot = { userId: null, count: 0 }, previous, effect, cleanup;
  const requests = [], listeners = new Map(), timers = new Set();
  const listen = (name, fn) => listeners.set(name, fn);
  const unlisten = (name, fn) => { if (listeners.get(name) === fn) listeners.delete(name); };
  const context = {
    useAuthStore: selector => selector({ user: userId ? { id: userId } : null }),
    useLocation: () => ({ pathname: '/notifications' }),
    useState: () => [snapshot, value => { snapshot = value; }],
    useEffect: (fn, deps) => { if (JSON.stringify(deps) !== previous) { previous = JSON.stringify(deps); effect = fn; } },
    notificationsApi: { getUnreadCount: () => new Promise(resolve => requests.push(resolve)) },
    window: { setInterval: fn => { timers.add(fn); return fn; }, clearInterval: fn => timers.delete(fn), addEventListener: listen, removeEventListener: unlisten },
    document: { hidden: false, addEventListener: listen, removeEventListener: unlisten },
  };
  const code = readFileSync(new URL('./useUnreadNotifications.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '').replace('export function', 'function');
  vm.runInNewContext(`${code}; this.hook = useUnreadNotifications;`, context);
  const render = () => { const value = context.hook(); if (effect) { cleanup?.(); const next = effect; effect = null; cleanup = next(); } return value; };
  return { render, requests, listeners, timers, setUser: value => { userId = value; }, dispose: () => cleanup?.() };
}
const settle = () => new Promise(resolve => setImmediate(resolve));
test('unread badge clears immediately on account change and late old-account result is fenced', async () => {
  const h = harness(); assert.equal(h.render(), 0);
  h.requests.shift()({ count: 4 }); await settle(); assert.equal(h.render(), 4);
  h.listeners.get('focus')(); const late = h.requests.shift();
  h.setUser('second'); assert.equal(h.render(), 0); assert.equal(h.timers.size, 1);
  late({ count: 99 }); await settle(); assert.equal(h.render(), 0);
  h.requests.shift()({ count: 2 }); await settle(); assert.equal(h.render(), 2);
  h.setUser(null); assert.equal(h.render(), 0);
  h.dispose(); assert.equal(h.timers.size, 0); assert.equal(h.listeners.size, 0);
});
test('read mutation and arrival focus refresh real count; older concurrent response cannot overwrite', async () => {
  const h = harness(); h.render();
  const older = h.requests.shift(); h.listeners.get('beautybook:notifications-changed')();
  h.requests.shift()({ count: 0 }); await settle(); older({ count: 5 }); await settle();
  assert.equal(h.render(), 0);
  h.listeners.get('focus')(); h.requests.shift()({ count: 3 }); await settle();
  assert.equal(h.render(), 3); assert.equal(h.timers.size, 1); h.dispose();
});
