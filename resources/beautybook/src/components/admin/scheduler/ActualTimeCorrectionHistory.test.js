import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { transformSync } from 'esbuild';
import { formatActualServiceTime } from '../../../utils/bookingActualTime.js';

function harness() {
  const cells = [], requests = []; let cursor = 0, pendingEffects = [], writes = 0;
  const changed = (a, b) => !a || a.length !== b.length || a.some((value, index) => value !== b[index]);
  const hooks = {
    useState(initial) { const index = cursor++; cells[index] ??= { value: initial }; return [cells[index].value, (value) => { writes++; cells[index].value = typeof value === 'function' ? value(cells[index].value) : value; }]; },
    useRef(initial) { const index = cursor++; cells[index] ??= { value: { current: initial } }; return cells[index].value; },
    useCallback(fn, deps) { const index = cursor++; if (!cells[index] || changed(cells[index].deps, deps)) cells[index] = { value: fn, deps }; return cells[index].value; },
    useEffect(fn, deps) { const index = cursor++; if (!cells[index] || changed(cells[index].deps, deps)) { const previous = cells[index]; cells[index] = { deps }; pendingEffects.push(() => { previous?.cleanup?.(); cells[index].cleanup = fn(); }); } },
  };
  const jsx = (type, props) => ({ type, props });
  const context = { module: { exports: {} }, exports: {}, require(name) {
    if (name === 'react') return hooks;
    if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx };
    if (name.includes('apiClient')) return { bookingsApi: { actualTimeCorrectionHistory: (bookingId, itemId) => new Promise((resolve, reject) => requests.push({ bookingId, itemId, resolve, reject })) } };
    if (name.includes('bookingActualTime')) return { formatActualServiceTime };
    if (name.includes('/ui')) return { Button: 'Button' };
    throw new Error(name);
  } };
  const source = readFileSync(new URL('./ActualTimeCorrectionHistory.jsx', import.meta.url), 'utf8');
  vm.runInNewContext(transformSync(source, { loader: 'jsx', jsx: 'automatic', format: 'cjs' }).code, context);
  const props = { bookingId: 'booking-a', itemId: 'item-a', revision: 3, timezone: 'Asia/Ho_Chi_Minh' };
  function render() { cursor = 0; const tree = context.module.exports.default(props); const effects = pendingEffects; pendingEffects = []; effects.forEach((fn) => fn()); return tree; }
  function walk(tree, predicate) {
    if (Array.isArray(tree)) { for (const node of tree) { const match = walk(node, predicate); if (match) return match; } }
    else if (tree && typeof tree === 'object') { if (predicate(tree)) return tree; return walk(tree.props?.children, predicate); }
    return null;
  }
  function text(tree) { return Array.isArray(tree) ? tree.map(text).join('') : tree && typeof tree === 'object' ? text(tree.props?.children) : typeof tree === 'string' || typeof tree === 'number' ? String(tree) : ''; }
  return { props, requests, render, find: (predicate) => walk(render(), predicate), text: () => text(render()), writes: () => writes, dispose: () => cells.forEach((cell) => cell?.cleanup?.()) };
}
const settle = () => new Promise((resolve) => setImmediate(resolve));
test('history is fetched on explicit open and displays only protected actor/at/reason facts with branch timezone', async () => {
  const h = harness(); h.render(); assert.equal(h.requests.length, 0);
  h.find((node) => node.type === 'Button').props.onClick(); h.render();
  assert.equal(h.requests[0].bookingId, 'booking-a'); assert.equal(h.requests[0].itemId, 'item-a');
  h.requests[0].resolve({ data: [{ id: 'correction', version: 1, actorId: 'actor-uuid', correctedAt: '2026-10-06T17:01:00Z', actualStartedAt: null, actualCompletedAt: null, reason: 'Đã đối chiếu; giờ thực tế chưa rõ' }] });
  await settle();
  assert.match(h.text(), /actor-uuid/); assert.match(h.text(), /07\/10\/2026/); assert.match(h.text(), /00:01:00/);
  assert.match(h.text(), /Đã đối chiếu; giờ thực tế chưa rõ/); assert.match(h.text(), /Chưa xác định/);
  h.dispose();
});
test('scope/item switch and unmount fence old protected history; revision refresh replaces old facts', async () => {
  const h = harness(); h.find((node) => node.type === 'Button').props.onClick(); h.render();
  h.props.itemId = 'item-b'; h.render();
  h.requests[1].resolve({ data: [{ id: 'b', version: 1, actorId: 'current-actor', reason: 'Current' }] }); await settle();
  h.requests[0].resolve({ data: [{ id: 'a', version: 1, actorId: 'stale-actor', reason: 'Stale' }] }); await settle();
  assert.match(h.text(), /current-actor/); assert.doesNotMatch(h.text(), /stale-actor/);
  h.props.revision = 4; h.render(); assert.equal(h.requests.length, 3);
  h.dispose(); const writes = h.writes();
  h.requests[2].resolve({ data: [{ id: 'late', actorId: 'late-actor' }] }); await settle();
  assert.equal(h.writes(), writes);
});
