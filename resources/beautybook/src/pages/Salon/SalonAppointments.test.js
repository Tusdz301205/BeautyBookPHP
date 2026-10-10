import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

function harness() {
  const cells = [], requests = []; let cursor = 0;
  const context = {
    initialForm: { branchId: 'branch-a', serviceId: 'service', staffId: '', date: '2026-10-06', appointmentDate: 'old-selected', controlledOverbooking: false },
    useAuthStore: (selector) => selector({ hasRoleAt: () => false }),
    useState: (initial) => { const index = cursor++; cells[index] ??= { value: initial }; return [cells[index].value, (value) => { cells[index].value = typeof value === 'function' ? value(cells[index].value) : value; }]; },
    useRef: (initial) => { const index = cursor++; cells[index] ??= { value: { current: initial } }; return cells[index].value; },
    useEffect: () => {}, useCallback: (fn) => fn,
    bookingsApi: { counterSlots: (args) => new Promise((resolve, reject) => requests.push({ args, resolve, reject })) },
    toast: { error: () => {} },
  };
  const file = readFileSync(new URL('./SalonAppointments.jsx', import.meta.url), 'utf8');
  const source = file.slice(file.indexOf('function WalkInDialog'), file.indexOf('  return <Dialog'));
  vm.runInNewContext(`${source} return { form, slots, slotLoading, slotError, loadSlots, set }; }; this.render = WalkInDialog;`, context);
  return { requests, render: (open = true) => { cursor = 0; return context.render({ open }); } };
}
test('counter request passes WALK_IN; filter edit clears selection immediately and fences stale success/failure', async () => {
  const h = harness(); let view = h.render();
  const old = view.loadSlots();
  assert.equal(h.requests[0].args.source, 'WALK_IN');
  assert.equal(h.requests[0].args.branchId, 'branch-a');
  view = h.render(); assert.equal(view.form.appointmentDate, '');
  view.set('date')({ target: { value: '2026-10-07' } });
  view = h.render(); assert.equal(view.form.appointmentDate, '');
  const fresh = view.loadSlots();
  h.requests[1].resolve({ slots: [{ start: 'fresh' }] }); await fresh;
  h.requests[0].resolve({ slots: [{ start: 'stale' }] }); await old;
  assert.equal(h.render().slots[0].start, 'fresh');
  view = h.render(); const failure = view.loadSlots();
  view.set('staffId')({ target: { value: 'staff-b' } });
  h.requests[2].reject(new Error('old failure')); await failure;
  assert.equal(h.render().slotError, ''); assert.equal(h.render().slots.length, 0);
});

test('counter API carries WALK_IN and normal slot arguments; public endpoint stays unchanged', async () => {
  const source = readFileSync(new URL('../../api/apiClient.js', import.meta.url), 'utf8');
  const methods = source.slice(source.indexOf('  availableSlots:'), source.indexOf('  getScheduler:'));
  const context = { URLSearchParams, request: (path) => path };
  vm.runInNewContext(`this.api = { ${methods} };`, context);
  const args = { branchId: 'branch', serviceIds: ['a', 'b'], staffId: 'staff', date: '2026-10-06', source: 'WALK_IN', variantSelections: { a: 'variant' } };
  const counter = new URL(await context.api.counterSlots(args), 'https://example.test');
  assert.equal(counter.pathname, '/bookings/counter-slots');
  for (const [key, value] of Object.entries({ branchId: 'branch', staffId: 'staff', date: '2026-10-06', serviceIds: 'a,b', source: 'WALK_IN' })) assert.equal(counter.searchParams.get(key), value);
  assert.deepEqual(JSON.parse(counter.searchParams.get('variantSelections')), { a: 'variant' });
  const publicSlots = new URL(await context.api.availableSlots(args), 'https://example.test');
  assert.equal(publicSlots.pathname, '/bookings/available-slots');
  assert.equal(publicSlots.searchParams.has('source'), false);
});
