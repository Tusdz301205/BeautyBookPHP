import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createChatSync, mergeMessages } from './chatSync.js';

// A fake of ChatService: one conversation, per-conversation sequence, `after`/`before` cursors, pages of 50,
// client-message-id idempotency and a read sequence per side. Both scopes talk to the same instance.
function fakeServer(conversationId = '7') {
  const rows = [];
  const read = { customer: 0, branch: 0 };
  const calls = { read: [] };
  const present = (row) => ({ id: String(row.sequence * 10), conversationId, sequence: row.sequence, body: row.body, fromBranch: row.fromBranch, clientMessageId: row.clientMessageId, sender: { fullName: row.fromBranch ? 'Lễ tân' : 'Khách' } });
  const api = (failures = {}) => ({
    async messages(scope, id, query) {
      if (failures.messages?.()) throw new Error('mạng lỗi');
      const params = new URLSearchParams(query.replace(/^\?/, ''));
      let page;
      if (params.has('after')) {
        page = rows.filter((row) => row.sequence > Number(params.get('after'))).sort((a, b) => a.sequence - b.sequence);
      } else {
        page = rows.filter((row) => !params.has('before') || row.sequence < Number(params.get('before'))).sort((a, b) => b.sequence - a.sequence);
      }
      const hasMore = page.length > 50;
      page = page.slice(0, 50);
      if (!params.has('after')) page.reverse();
      return { id, items: page.map(present), hasMore, lastSequence: rows.length, peerReadSequence: scope === 'customer' ? read.branch : read.customer, canSend: true };
    },
    async send(scope, id, payload) {
      if (failures.send?.()) throw Object.assign(new Error('mạng lỗi'), { status: 500 });
      const duplicate = rows.find((row) => row.clientMessageId === payload.clientMessageId);
      if (duplicate) return present(duplicate);
      const row = { sequence: rows.length + 1, body: payload.body, fromBranch: scope === 'branch', clientMessageId: payload.clientMessageId };
      rows.push(row);
      return present(row);
    },
    async read(scope, id, sequence) {
      if (failures.read?.()) throw new Error('mạng lỗi');
      if (sequence > rows.length) throw new Error('Vị trí đã đọc không hợp lệ.');
      calls.read.push({ scope, sequence });
      read[scope] = Math.max(read[scope], sequence);
      return { sequence: read[scope] };
    },
  });
  const unread = (scope) => rows.filter((row) => row.fromBranch === (scope === 'customer') && row.sequence > read[scope]).length;
  const post = (scope, body) => rows.push({ sequence: rows.length + 1, body, fromBranch: scope === 'branch', clientMessageId: `peer-${rows.length + 1}` });

  return { api, rows, read, calls, unread, post };
}

const sequences = (sync) => sync.messages.map((message) => message.sequence);

test('a peer message that arrives between the last poll and our send is still loaded, in order, and not marked read unseen', async () => {
  const server = fakeServer();
  const customer = createChatSync({ api: server.api(), scope: 'customer', id: '7' });
  const branch = createChatSync({ api: server.api(), scope: 'branch', id: '7' });
  await customer.send({ clientMessageId: 'c-1', body: 'Xin chào' });
  await branch.poll();
  await branch.send({ clientMessageId: 'b-1', body: 'Chào bạn' });
  await customer.poll();
  assert.deepEqual(sequences(customer), [1, 2]);
  assert.equal(customer.cursor, 2);

  // 3 = the branch replies after the customer's last poll, 4 = the customer sends before the next poll.
  await branch.send({ clientMessageId: 'b-2', body: 'Lịch đã xác nhận' });
  assert.equal(server.unread('customer'), 1);
  const sent = await customer.send({ clientMessageId: 'c-2', body: 'Cảm ơn' });
  assert.equal(sent.sequence, 4);

  assert.deepEqual(sequences(customer), [1, 2, 3, 4], 'the branch reply at sequence 3 must be present');
  assert.equal(customer.cursor, 4);
  assert.equal(server.unread('customer'), 0, 'read state follows what was really loaded');
  assert.equal(server.read.customer, 4);
  // And the branch sees the customer's message plus its own history on its next tick.
  await branch.poll();
  assert.deepEqual(sequences(branch), [1, 2, 3, 4]);
  assert.equal(branch.meta.peerReadSequence, 4);
});

test('the cursor never moves past what a poll returned, so nothing is acknowledged before it is loaded', async () => {
  const server = fakeServer();
  let failPoll = false;
  const customer = createChatSync({ api: server.api({ messages: () => failPoll }), scope: 'customer', id: '7' });
  await customer.poll();
  server.post('branch', 'tin 1');
  await customer.poll();
  // The peer writes, then our send succeeds but the catch-up poll fails (network blip).
  server.post('branch', 'tin 2');
  failPoll = true;
  const sent = await customer.send({ clientMessageId: 'c-1', body: 'Mình gửi' });
  failPoll = false;
  assert.equal(sent.sequence, 3);
  assert.equal(customer.cursor, 1, 'sending must not move the cursor');
  assert.equal(server.unread('customer'), 1, 'tin 2 is still unread because it was never loaded');
  assert.ok(server.calls.read.every((call) => call.sequence <= 1), JSON.stringify(server.calls.read));
  await customer.poll();
  assert.deepEqual(sequences(customer), [1, 2, 3]);
  assert.equal(server.unread('customer'), 0);
});

test('a backlog of more than one page is drained in a single poll and acknowledged once', async () => {
  const server = fakeServer();
  const customer = createChatSync({ api: server.api(), scope: 'customer', id: '7' });
  await customer.poll();
  for (let i = 1; i <= 120; i += 1) server.post('branch', `tin ${i}`);
  await customer.poll();
  assert.equal(customer.messages.length, 120);
  assert.equal(customer.cursor, 120);
  assert.equal(server.unread('customer'), 0);
  assert.deepEqual(server.calls.read.map((call) => call.sequence), [120]);
});

test('a first load shows the latest page and offers older messages without moving the cursor backwards', async () => {
  const server = fakeServer();
  for (let i = 1; i <= 70; i += 1) server.post(i % 2 ? 'branch' : 'customer', `tin ${i}`);
  const customer = createChatSync({ api: server.api(), scope: 'customer', id: '7' });
  await customer.poll();
  assert.equal(customer.messages.length, 50);
  assert.equal(customer.hasOlder, true);
  assert.equal(customer.cursor, 70);
  await customer.loadOlder();
  assert.equal(customer.messages.length, 70);
  assert.equal(customer.hasOlder, false);
  assert.equal(customer.cursor, 70);
});

test('a retried send with the same client id returns the same message and a polled copy does not duplicate it', async () => {
  const server = fakeServer();
  const customer = createChatSync({ api: server.api(), scope: 'customer', id: '7' });
  const payload = { clientMessageId: 'c-1', body: 'Một lần thôi' };
  const first = await customer.send(payload);
  const again = await customer.send(payload);
  assert.equal(again.id, first.id);
  assert.equal(customer.messages.length, 1);
  assert.equal(server.rows.length, 1);
});

test('overlapping polls run one at a time and a poll requested mid-flight is repeated afterwards', async () => {
  const server = fakeServer();
  let release;
  let held = true;
  const base = server.api();
  const customer = createChatSync({
    api: { ...base, messages: async (...args) => { const result = await base.messages(...args); if (held) { held = false; await new Promise((resolve) => { release = resolve; }); } return result; } },
    scope: 'customer', id: '7',
  });
  const first = customer.poll();
  await new Promise((resolve) => setTimeout(resolve, 5));
  server.post('branch', 'đến giữa chừng');
  const second = customer.poll();
  assert.strictEqual(first, second);
  release();
  await second;
  assert.deepEqual(sequences(customer), [1], 'the message that arrived during the first request is fetched by the repeat');
});

test('messages for another conversation are rejected instead of being merged', async () => {
  const server = fakeServer();
  const foreign = { ...server.api(), messages: async () => ({ items: [{ id: '1', conversationId: '99', sequence: 1 }], hasMore: false }) };
  const customer = createChatSync({ api: foreign, scope: 'customer', id: '7' });
  await assert.rejects(customer.poll(), /Chưa đọc được/);
  assert.equal(customer.messages.length, 0);
});

test('mergeMessages is idempotent and keeps sequence order', () => {
  const a = { id: '1', sequence: 1 }; const b = { id: '2', sequence: 2 }; const c = { id: '3', sequence: 3 };
  assert.deepEqual(mergeMessages([c, a], [b, a]).map((m) => m.sequence), [1, 2, 3]);
});

test('the previous strategy (cursor jumps to our own sequence) loses the peer message; this documents the defect the sync fixes', async () => {
  const server = fakeServer();
  const api = server.api();
  let cursor = 0; const seen = [];
  const legacyPoll = async () => { const r = await api.messages('customer', '7', cursor ? `?after=${cursor}` : ''); r.items.forEach((m) => seen.push(m.sequence)); cursor = Math.max(cursor, ...r.items.map((m) => m.sequence)); };
  server.post('customer', 'a'); server.post('branch', 'b');
  await legacyPoll();
  server.post('branch', 'đến giữa lần poll và lần gửi');
  const sent = await api.send('customer', '7', { clientMessageId: 'x', body: 'gửi' });
  cursor = Math.max(cursor, sent.sequence);
  await legacyPoll();
  assert.deepEqual(seen, [1, 2], 'sequence 3 was never fetched by the legacy cursor');
});

test('ChatInbox drives the thread through the sync engine and never assigns its own cursor', () => {
  const source = readFileSync(new URL('./ChatInbox.jsx', import.meta.url), 'utf8');
  assert.match(source, /createChatSync/);
  assert.doesNotMatch(source, /cursor\.current/);
  assert.doesNotMatch(source, /\?after=/);
});
