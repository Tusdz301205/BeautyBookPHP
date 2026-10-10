import assert from 'node:assert/strict';
import { io } from 'socket.io-client';

// This exercises the deployed Laravel session and Socket.IO proxy, without
// modifying bookings, assignments, or production data.
const base = process.env.BEAUTYBOOK_URL || 'http://localhost:8000';
const cookies = new Map();
let csrf;
async function request(path, body, token) {
  const response = await fetch(`${base}${path}`, {
    method: body === undefined ? 'GET' : 'POST',
    headers: { Accept: 'application/json', Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; '),
      ...(body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }),
      ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
    signal: AbortSignal.timeout(60000),
  });
  for (const value of response.headers.getSetCookie()) {
    const pair = value.split(';')[0]; const equal = pair.indexOf('=');
    cookies.set(pair.slice(0,equal), pair.slice(equal+1));
  }
  csrf = response.headers.get('X-CSRF-TOKEN') || csrf;
  assert.equal(response.status, 200, `${path}: ${await response.clone().text()}`);
  return response;
}
function event(socket, name, timeout = 15000) {
  return new Promise((resolve,reject) => {
    const timer = setTimeout(() => { socket.off(name,done); reject(new Error(`Timed out: ${name}`)); },timeout);
    function done(data) { clearTimeout(timer); resolve(data); }
    socket.once(name,done);
  });
}
const page = await request('/beautybook');
csrf = (await page.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
assert.ok(csrf, 'Laravel CSRF token');
const login = await (await request('/api/v1/auth/login', {
  email: process.env.BEAUTYBOOK_EMAIL || 'owner@beautybook.local',
  password: process.env.BEAUTYBOOK_PASSWORD || 'BeautyBook123!',
})).json();
assert.equal(login.accessToken.length,64);
const socket = io(base, { autoConnect: false, reconnection: false, transports: ['websocket'],
  auth: { token: login.accessToken }, extraHeaders: { Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; ') } });
try {
  const connected = event(socket,'connect');
  const resync = event(socket,'scheduler_resync');
  socket.connect(); await connected; await resync;
  const lease = await (await request('/api/v1/realtime/session', undefined,login.accessToken)).json();
  assert.ok(lease.validUntil * 1000 > Date.now());
  const invalidated = event(socket,'scheduler_auth_failed');
  const disconnected = event(socket,'disconnect');
  const refreshed = await (await request('/api/v1/auth/refresh',{},login.accessToken)).json();
  assert.notEqual(refreshed.accessToken,login.accessToken);
  assert.equal((await invalidated).code,'WS_AUTH_FAILED'); await disconnected;
  await request('/api/v1/auth/logout',{},refreshed.accessToken);
  console.log(JSON.stringify({ laravelLogin: true, websocketProxy: true, schedulerResync: true, realAuthorizationLease: true, tokenRotationDisconnect: true }));
} finally { socket.disconnect(); }
