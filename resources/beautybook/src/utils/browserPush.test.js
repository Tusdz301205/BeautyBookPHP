import test from 'node:test';
import assert from 'node:assert/strict';
import { createBrowserPush } from './browserPush.js';

function fixture({ mode = 'trial', permission = 'granted', inbox = [] } = {}) {
  const calls = [];
  const shown = [];
  const store = new Map();
  const storage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) };
  const devices = new Map();
  const api = {
    pushConfig: async () => ({ mode, reason: mode === 'off' ? 'PUSH_DISABLED' : null }),
    registerDevice: async (token, platform, installationId) => { calls.push(['register', token, platform, installationId]); devices.set(token, true); },
    removeDevice: async (token, installationId) => { calls.push(['remove', token, installationId]); devices.delete(token); },
    trialMessages: async (token) => {
      if (!devices.has(token)) throw Object.assign(new Error('Không tìm thấy'), { status: 404 });
      return { items: inbox.splice(0) };
    },
  };
  const registration = { showNotification: async (title, options) => shown.push({ title, ...options }) };
  const Notification = { permission, requestPermission: async () => { calls.push(['permission']); return permission; } };
  const serviceWorker = { register: async (url, options) => { calls.push(['sw', url, options.scope]); return registration; }, ready: Promise.resolve(registration) };
  const push = createBrowserPush({ api, storage, env: { Notification, serviceWorker, randomId: () => 'abcdef0123456789abcdef0123456789', randomUuid: () => '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f' } });
  return { push, calls, shown, store, devices, inbox };
}

test('enable registers the service worker and a per-browser device, then collect shows what the server pushed, then disable removes it', async () => {
  const f = fixture({ inbox: [{ id: 'm1', title: 'Lịch hẹn đã xác nhận', body: 'Mời bạn đến đúng giờ', data: { notificationId: '42', type: 'BOOKING_STATUS_CHANGED' } }] });
  assert.deepEqual(await f.push.status(7), { supported: true, mode: 'trial', reason: null, permission: 'granted', enabled: false });
  const token = await f.push.enable(7);
  assert.equal(token, 'trial-abcdef0123456789abcdef0123456789');
  assert.deepEqual(f.calls, [['permission'], ['sw', '/beautybook-push-sw.js', '/'], ['register', 'trial-abcdef0123456789abcdef0123456789', 'WEB', '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f']]);
  assert.equal((await f.push.status(7)).enabled, true);

  assert.equal(await f.push.collect(7), 1);
  assert.deepEqual(f.shown, [{ title: 'Lịch hẹn đã xác nhận', body: 'Mời bạn đến đúng giờ', tag: '42', data: { notificationId: '42', type: 'BOOKING_STATUS_CHANGED' }, icon: '/favicon.ico' }]);
  assert.equal(await f.push.collect(7), 0, 'the inbox is consumed, so nothing is shown twice');

  assert.equal(await f.push.disable(7), true);
  assert.deepEqual(f.calls.at(-1), ['remove', 'trial-abcdef0123456789abcdef0123456789', '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f']);
  assert.equal(f.push.hasDevice(7), false);
  assert.equal(await f.push.collect(7), 0);
  assert.equal(await f.push.disable(7), false);
});

test('the device belongs to the signed-in account only; another account in the same browser has none', async () => {
  const f = fixture();
  await f.push.enable(7);
  assert.equal(f.push.hasDevice(7), true);
  assert.equal(f.push.hasDevice(8), false);
  assert.equal((await f.push.status(8)).enabled, false);
  assert.equal(await f.push.collect(8), 0);
});

test('enable is refused without browser permission or when the server has push off, and registers nothing', async () => {
  const denied = fixture({ permission: 'denied' });
  await assert.rejects(denied.push.enable(7), /chưa cho phép/);
  assert.deepEqual(denied.calls, [['permission']]);
  const off = fixture({ mode: 'off' });
  await assert.rejects(off.push.enable(7), /chưa bật thông báo đẩy/);
  assert.deepEqual(off.calls, []);
  assert.equal((await off.push.status(7)).mode, 'off');
});

test('a device the server no longer knows is forgotten instead of polled forever', async () => {
  const f = fixture({ inbox: [{ id: 'x', title: 't', body: 'b', data: {} }] });
  await f.push.enable(7);
  f.devices.clear();
  assert.equal(await f.push.collect(7), 0);
  assert.equal(f.push.hasDevice(7), false);
});

test('a browser without Notification or service workers reports unsupported', async () => {
  const push = createBrowserPush({ api: {}, storage: { getItem: () => null, setItem() {}, removeItem() {} }, env: { Notification: null, serviceWorker: null, randomId: () => 'x', randomUuid: () => 'x' } });
  assert.equal((await push.status(1)).supported, false);
  await assert.rejects(push.enable(1), /không hỗ trợ/);
});

test('the installation id belongs to the browser, not the account, and is created once', async () => {
  const f = fixture();
  await f.push.enable(7);
  await f.push.enable(8);
  const installations = f.calls.filter(([kind]) => kind === 'register').map((call) => call[3]);
  assert.deepEqual(installations, ['3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f', '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f']);
  assert.equal(f.store.get('bb_push_installation'), '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f');
});

test('sync registers the existing device again for the new session, and only when there is a device and permission', async () => {
  const f = fixture();
  assert.equal(await f.push.sync(7), false, 'no device yet');
  assert.deepEqual(f.calls, []);
  await f.push.enable(7);
  f.calls.length = 0;
  assert.equal(await f.push.sync(7), true);
  assert.deepEqual(f.calls, [['register', 'trial-abcdef0123456789abcdef0123456789', 'WEB', '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f']]);
  assert.equal(await f.push.sync(8), false, 'another account in the same browser has no device');

  const denied = fixture({ permission: 'denied' });
  denied.store.set('bb_push_device_7', 'trial-x');
  assert.equal(await denied.push.sync(7), false);
  assert.deepEqual(denied.calls, []);
});

test('a refused re-registration does not remove or change the stored device', async () => {
  const f = fixture();
  await f.push.enable(7);
  const refusing = createBrowserPush({ api: { registerDevice: async () => { throw new Error('offline'); } }, storage: { getItem: (k) => f.store.get(k) ?? null, setItem: (k, v) => f.store.set(k, v), removeItem: (k) => f.store.delete(k) },
    env: { Notification: { permission: 'granted' }, serviceWorker: {}, randomId: () => 'x', randomUuid: () => 'y' } });
  assert.equal(await refusing.sync(7), false);
  assert.equal(refusing.hasDevice(7), true);
});

// ---- real Firebase Cloud Messaging for the browser (mode 'fcm') -------------------------------------------------------------

function fcmFixture({ permission = 'granted', token = 'fcm-token-0123456789abcdef-real', getTokenError = null, deleteError = null } = {}) {
  const calls = [];
  const store = new Map();
  const storage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) };
  const firebase = { apiKey: 'k', projectId: 'p', messagingSenderId: '1', appId: 'a', vapidKey: 'v' };
  const api = {
    pushConfig: async () => ({ mode: 'fcm', reason: null, firebase }),
    registerDevice: async (t, platform, installationId) => { calls.push(['register', t, platform, installationId]); },
    removeDevice: async (t, installationId) => { calls.push(['remove', t, installationId]); },
    trialMessages: async () => { throw new Error('never polled in fcm mode'); },
  };
  const registration = { showNotification: async () => { calls.push(['show']); } };
  const Notification = { permission, requestPermission: async () => { calls.push(['permission']); return permission; } };
  const serviceWorker = { register: async (url, options) => { calls.push(['sw', url, options.scope]); return registration; }, ready: Promise.resolve(registration) };
  const env = { Notification, serviceWorker, randomId: () => 'abcdef0123456789abcdef0123456789', randomUuid: () => '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f',
    getFcmToken: async (config, reg) => { calls.push(['getToken', config.vapidKey, reg === registration]); if (getTokenError) throw getTokenError; return token; },
    deleteFcmToken: async (config) => { calls.push(['deleteToken', config.projectId]); if (deleteError) throw deleteError; } };
  return { push: createBrowserPush({ api, storage, env }), calls, store };
}

test('fcm mode: enable asks permission, registers the worker, takes the real FCM token and registers that very token with the server', async () => {
  const f = fcmFixture();
  assert.equal(await f.push.enable(7), 'fcm-token-0123456789abcdef-real');
  assert.deepEqual(f.calls.map((c) => c[0]), ['permission', 'sw', 'getToken', 'register']);
  assert.deepEqual(f.calls[2], ['getToken', 'v', true], 'the token is requested with the VAPID key on the registered worker');
  assert.deepEqual(f.calls[3], ['register', 'fcm-token-0123456789abcdef-real', 'WEB', '3f1c2d4e-5a6b-4c7d-8e9f-0a1b2c3d4e5f']);
  assert.equal(f.push.hasDevice(7), true);
});

test('fcm mode: nothing is polled (the service worker receives the push) and a new sign-in registers the stored token again', async () => {
  const f = fcmFixture();
  await f.push.enable(7);
  assert.equal(await f.push.collect(7), 0);
  assert.equal(await f.push.sync(7), true);
  assert.equal(f.calls.filter((c) => c[0] === 'register').length, 2);
});

test('fcm mode: disable removes the device on the server and deletes the FCM token, even when deleting it fails', async () => {
  const f = fcmFixture({ deleteError: new Error('offline') });
  await f.push.enable(7);
  assert.equal(await f.push.disable(7), true);
  assert.deepEqual(f.calls.filter((c) => ['remove', 'deleteToken'].includes(c[0])).map((c) => c[0]), ['remove', 'deleteToken']);
  assert.equal(f.push.hasDevice(7), false, 'the local mark goes either way');
});

test('fcm mode: a refusal or a Firebase failure becomes a clear message and registers nothing', async () => {
  const denied = fcmFixture({ permission: 'denied' });
  await assert.rejects(() => denied.push.enable(7), /chưa cho phép thông báo/);
  assert.equal(denied.calls.some((c) => c[0] === 'register'), false);

  const blocked = fcmFixture({ getTokenError: Object.assign(new Error('x'), { code: 'messaging/permission-blocked' }) });
  await assert.rejects(() => blocked.push.enable(7), /Trình duyệt đang chặn thông báo/);
  const unsupported = fcmFixture({ getTokenError: Object.assign(new Error('x'), { code: 'messaging/unsupported-browser' }) });
  await assert.rejects(() => unsupported.push.enable(7), /không hỗ trợ thông báo đẩy/);
  const failed = fcmFixture({ getTokenError: Object.assign(new Error('x'), { code: 'messaging/token-subscribe-failed' }) });
  await assert.rejects(() => failed.push.enable(7), /Không đăng ký được với Firebase/);
  for (const f of [blocked, unsupported, failed]) { assert.equal(f.calls.some((c) => c[0] === 'register'), false); assert.equal(f.push.hasDevice(7), false); }
});

test('fcm mode without the Firebase settings or the SDK helpers refuses to enable', async () => {
  const f = fcmFixture();
  const noSdk = createBrowserPush({ api: { pushConfig: async () => ({ mode: 'fcm', firebase: null }), registerDevice: async () => {}, removeDevice: async () => {}, trialMessages: async () => ({ items: [] }) }, storage: { getItem: () => null, setItem: () => {}, removeItem: () => {} },
    env: { Notification: { permission: 'granted', requestPermission: async () => 'granted' }, serviceWorker: { register: async () => ({}), ready: Promise.resolve({}) }, randomId: () => 'a', randomUuid: () => 'b' } });
  await assert.rejects(() => noSdk.enable(7), /chưa bật thông báo đẩy/);
  assert.equal(f.calls.length, 0);
});
