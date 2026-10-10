import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { transformSync } from 'esbuild';
import * as correction from '../../../utils/actualTimeCorrection.js';

function harness(send = async () => ({ status: 'COMPLETED' })) {
  const cells = [], sent = []; let cursor = 0, reads = 0;
  const hooks = {
    useState: (initial) => { const index = cursor++; cells[index] ??= { value: typeof initial === 'function' ? initial() : initial }; return [cells[index].value, (value) => { cells[index].value = typeof value === 'function' ? value(cells[index].value) : value; }]; },
    useRef: (initial) => { const index = cursor++; cells[index] ??= { value: { current: initial } }; return cells[index].value; },
    useId: () => 'correction-form', useEffect: () => {},
  };
  const jsx = (type, props) => ({ type, props });
  const context = { module: { exports: {} }, exports: {}, require: (name) => {
    if (name === 'react') return hooks;
    if (name === 'react/jsx-runtime') return { jsx, jsxs: jsx, Fragment: 'Fragment' };
    if (name === 'react-hot-toast') return { __esModule: true, default: { success: () => {} } };
    if (name.includes('apiClient')) return { bookingsApi: { correctActualTime: async (...args) => { sent.push(args); return send(...args); } } };
    if (name.includes('actualTimeCorrection')) return correction;
    if (name.includes('/ui')) return { Button: 'Button', Dialog: 'Dialog', Field: 'Field', Input: 'Input', Select: 'Select', Textarea: 'Textarea' };
    throw new Error(name);
  } };
  const source = readFileSync(new URL('./ActualTimeCorrection.jsx', import.meta.url), 'utf8');
  const code = transformSync(source, { loader: 'jsx', jsx: 'automatic', format: 'cjs' }).code;
  vm.runInNewContext(code, context);
  const item = { bookingServiceId: 'item', name: 'Dịch vụ', revision: 3, status: 'SCHEDULED', itemStartAt: '2026-10-06T15:00:00Z', actualStartedAt: '2026-10-06T15:01:00Z' };
  const render = () => { cursor = 0; return context.module.exports.default({ bookingId: 'booking', item, timezone: 'Asia/Ho_Chi_Minh', serverNow: Date.parse('2026-10-06T16:00:00Z'), onRefresh: async () => { reads++; } }); };
  const find = (predicate, tree = render()) => {
    if (Array.isArray(tree)) { for (const node of tree) { const match = find(predicate, node); if (match) return match; } }
    else if (tree && typeof tree === 'object') { if (predicate(tree)) return tree; return tree.props?.children === undefined ? null : find(predicate, tree.props.children); }
    return null;
  };
  const input = (label) => find((node) => node.props?.['aria-label'] === label);
  const confirm = () => find((node) => node.type === 'input' && node.props?.type === 'checkbox').props.onChange({ target: { checked: true } });
  const submit = () => find((node) => node.type === 'form').props.onSubmit({ preventDefault() {} });
  return { render, find, input, confirm, submit, item, sent, reads: () => reads };
}

test('rendered correction form never pre-fills planned/existing actual times; unknown sends null/null only after explicit confirmation', async () => {
  const h = harness();
  h.find((node) => node.type === 'Button' && node.props.children === 'Bổ sung / hiệu chỉnh thời gian thực tế').props.onClick();
  assert.equal(h.input('Bắt đầu thực tế').props.value, '');
  assert.equal(h.input('Kết thúc thực tế').props.value, '');
  h.input('Cách ghi nhận thời gian thực tế').props.onChange({ target: { value: 'UNKNOWN' } });
  assert.equal(h.input('Bắt đầu thực tế'), null);
  h.input('Lý do bổ sung / hiệu chỉnh').props.onChange({ target: { value: 'Đã đối chiếu hồ sơ thực tế' } });
  await h.submit(); assert.equal(h.sent.length, 0);
  h.confirm(); await h.submit();
  assert.equal(h.sent.length, 1); assert.equal(h.reads(), 1);
  assert.deepEqual(JSON.parse(JSON.stringify(h.sent[0][2])), { expectedRevision: 3, actualStartedAt: null, actualCompletedAt: null, reason: 'Đã đối chiếu hồ sơ thực tế' });
  assert.equal(h.item.status, 'SCHEDULED');
});

test('rendered form rejects future time before dispatch and clears confirmation after a revision conflict/read', async () => {
  const h = harness(async () => { throw Object.assign(new Error('Conflict'), { status: 409 }); });
  h.input('Bắt đầu thực tế').props.onChange({ target: { value: '2026-10-06T22:00' } });
  h.input('Kết thúc thực tế').props.onChange({ target: { value: '2026-10-06T23:01' } });
  h.input('Lý do bổ sung / hiệu chỉnh').props.onChange({ target: { value: 'Đã xác minh thực tế' } });
  h.confirm(); await h.submit(); assert.equal(h.sent.length, 0);
  assert.match(h.find((node) => node.props?.role === 'alert').props.children, /tương lai/);
  h.input('Kết thúc thực tế').props.onChange({ target: { value: '2026-10-06T22:30' } });
  h.confirm(); await h.submit();
  assert.equal(h.sent.length, 1); assert.equal(h.reads(), 1);
  assert.equal(h.find((node) => node.type === 'input' && node.props?.type === 'checkbox').props.checked, false);
  assert.match(h.find((node) => node.props?.role === 'alert').props.children, /xác nhận lại/);
  assert.equal(h.item.status, 'SCHEDULED');
});
