import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { io } from 'socket.io-client';

const token = 'a'.repeat(64);
const cookie = 'beautybook_session=actual-cookie-forwarding-test';
const delay = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
async function waitEvent(socket, event) {
  return Promise.race([once(socket, event), delay(12000).then(() => { throw new Error(`Timed out: ${event}`); })]);
}

test('actual Socket.IO forwards session credentials, delivers durable events and revokes access', async t => {
  let revoked = false;
  let changed = false;
  let cursor = 0;
  const requests = [];
  const backend = http.createServer((request, response) => {
    requests.push({ cookie: request.headers.cookie, authorization: request.headers.authorization });
    response.setHeader('Content-Type', 'application/json');
    if (revoked || request.headers.cookie !== cookie || request.headers.authorization !== `Bearer ${token}`) {
      response.writeHead(403); response.end('{"message":"Revoked"}'); return;
    }
    const url = new URL(request.url, 'http://localhost');
    const after = url.searchParams.get('after');
    response.end(JSON.stringify({ cursor, authorization: changed ? 'new-assignment' : 'selected-assignment', validUntil: Math.floor(Date.now() / 1000) + 15, events: after !== null && Number(after) < cursor ? [{ event: 'booking_updated', payload: { id: '42', branchId: '7', status: 'CONFIRMED' } }] : [] }));
  });
  backend.listen(0, '127.0.0.1'); await once(backend, 'listening');
  const reservation = http.createServer(); reservation.listen(0, '127.0.0.1'); await once(reservation, 'listening');
  const port = reservation.address().port; await new Promise(resolve => reservation.close(resolve));
  const child = spawn(process.execPath, ['realtime/server.mjs'], { env: { ...process.env, PORT: String(port), LARAVEL_URL: `http://127.0.0.1:${backend.address().port}` }, stdio: 'pipe' });
  const sockets = [];
  t.after(() => { for (const socket of sockets) socket.disconnect(); child.kill(); backend.close(); });
  for (let i = 0; i < 100; i++) {
    try { if ((await fetch(`http://127.0.0.1:${port}/health`)).ok) break; } catch {}
    await delay(50);
  }
  const rejected = io(`http://127.0.0.1:${port}`, { transports: ['websocket'], reconnection: false, auth: { token: 'bad' } }); sockets.push(rejected);
  const [error] = await waitEvent(rejected, 'connect_error'); assert.equal(error.data.code, 'WS_AUTH_FAILED');
  const socket = io(`http://127.0.0.1:${port}`, { transports: ['websocket'], reconnection: false, auth: { token }, extraHeaders: { Cookie: cookie } }); sockets.push(socket);
  await waitEvent(socket, 'connect');
  assert.equal(requests.at(-1).cookie, cookie); assert.equal(requests.at(-1).authorization, `Bearer ${token}`);
  const updated = waitEvent(socket, 'booking_updated'); cursor = 1;
  const [payload] = await updated; assert.deepEqual(payload, { id: '42', branchId: '7', status: 'CONFIRMED' });
  const accessChanged = waitEvent(socket, 'scheduler_access_changed'); changed = true; await accessChanged;
  const failed = waitEvent(socket, 'scheduler_auth_failed'); const disconnected = waitEvent(socket, 'disconnect'); revoked = true;
  const [failure] = await failed; assert.equal(failure.code, 'WS_AUTH_FAILED'); await disconnected; assert.equal(socket.connected, false);
});
