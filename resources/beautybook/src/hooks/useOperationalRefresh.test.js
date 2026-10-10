import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

test('foreground/focus/online refresh scoped data only while visible and clean up listeners', () => {
  const listeners = new Map(); let cleanup, calls = 0;
  const target = { addEventListener: (name, handler) => listeners.set(name, handler), removeEventListener: (name) => listeners.delete(name) };
  const context = { useEffect: (fn) => { cleanup = fn(); }, window: target, document: { ...target, visibilityState: 'hidden' } };
  const source = readFileSync(new URL('./useOperationalRefresh.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '').replace('export function', 'function');
  vm.runInNewContext(`${source}; this.hook = useOperationalRefresh;`, context);
  context.hook(() => calls++);
  listeners.get('online')(); assert.equal(calls, 0);
  context.document.visibilityState = 'visible';
  for (const name of ['focus', 'visibilitychange', 'online']) listeners.get(name)();
  assert.equal(calls, 3);
  cleanup(); assert.equal(listeners.size, 0);
});
