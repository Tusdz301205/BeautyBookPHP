// Browser push for the trial/preview build: register this browser as a device, show what the server pushed, remove it.
// Framework-free with injected dependencies so it can be tested without a browser. The device token is bound to the
// signed-in account in storage, so another account in the same browser never inherits it.

export const SERVICE_WORKER_URL = '/beautybook-push-sw.js';
const storageKey = (userId) => `bb_push_device_${userId}`;
// An installation is this browser profile, not an account: it keeps its id when another account signs in (push-devices.service.ts).
const INSTALLATION_KEY = 'bb_push_installation';

// Firebase failures the customer can act on (codes of the Firebase Messaging web SDK), in plain Vietnamese.
export function fcmFailureMessage(error) {
  const code = String(error?.code || '');
  if (code.includes('permission-blocked') || code.includes('notifications-blocked')) return 'Trình duyệt đang chặn thông báo cho trang này. Hãy cho phép trong cài đặt trình duyệt rồi thử lại.';
  if (code.includes('unsupported-browser') || code.includes('failed-service-worker-registration')) return 'Trình duyệt này không hỗ trợ thông báo đẩy qua Firebase.';
  if (code.includes('token-subscribe-failed') || code.includes('token-unsubscribe-failed') || code.includes('invalid-vapid-key')) return 'Không đăng ký được với Firebase. Hãy thử lại sau hoặc báo cho cơ sở nếu lỗi tiếp tục.';
  return 'Không bật được thông báo đẩy. Hãy thử lại sau.';
}

export function createBrowserPush({ api, storage, env }) {
  const read = (userId) => { try { return storage.getItem(storageKey(userId)); } catch { return null; } };
  const write = (userId, token) => { try { if (token) storage.setItem(storageKey(userId), token); else storage.removeItem(storageKey(userId)); } catch { /* private mode */ } };
  const supported = () => Boolean(env.Notification && env.serviceWorker);
  const installationId = () => {
    let id = null;
    try { id = storage.getItem(INSTALLATION_KEY); } catch { /* private mode */ }
    if (!id) {
      id = env.randomUuid();
      try { storage.setItem(INSTALLATION_KEY, id); } catch { /* private mode: a fresh id each time still works */ }
    }
    return id;
  };

  async function status(userId) {
    if (!supported()) return { supported: false, mode: 'off', permission: 'denied', enabled: false, reason: 'UNSUPPORTED' };
    const config = await api.pushConfig();
    return { supported: true, mode: config.mode, reason: config.reason ?? null, permission: env.Notification.permission, enabled: Boolean(read(userId)) && env.Notification.permission === 'granted' };
  }

  async function enable(userId) {
    if (!supported()) throw new Error('Trình duyệt này không hỗ trợ thông báo đẩy.');
    const config = await api.pushConfig();
    const fcm = config.mode === 'fcm' && config.firebase && typeof env.getFcmToken === 'function';
    if (config.mode !== 'trial' && !fcm) throw new Error('Máy chủ chưa bật thông báo đẩy cho trình duyệt này.');
    const permission = await env.Notification.requestPermission();
    if (permission !== 'granted') throw new Error('Bạn chưa cho phép thông báo trong trình duyệt.');
    const registration = await env.serviceWorker.register(SERVICE_WORKER_URL, { scope: '/' });
    await env.serviceWorker.ready;
    let token;
    if (fcm) {
      // The real channel: the browser takes its own FCM token on the registered worker; that very token is what the server stores and pushes to.
      try { token = await env.getFcmToken(config.firebase, registration); } catch (error) { throw new Error(fcmFailureMessage(error)); }
      if (!token) throw new Error(fcmFailureMessage(null));
    } else {
      token = read(userId) || `trial-${env.randomId()}`;
    }
    await api.registerDevice(token, 'WEB', installationId());
    write(userId, token);
    return token;
  }

  // A new sign-in is a new session, and the server pushes only to the session a device is bound to: register again after
  // every sign-in. Without a device, permission or support this does nothing; a refusal leaves the device as it was.
  async function sync(userId) {
    const token = read(userId);
    if (!token || !supported() || env.Notification.permission !== 'granted') return false;
    try { await api.registerDevice(token, 'WEB', installationId()); return true; } catch { return false; }
  }

  async function disable(userId) {
    const token = read(userId);
    if (!token) return false;
    try { await api.removeDevice(token, installationId()); } finally {
      write(userId, null);
      // The FCM token is useless after the account stops using it: delete it too, best effort (the server no longer pushes to it either way).
      if (typeof env.deleteFcmToken === 'function') { try { const config = await api.pushConfig(); if (config.mode === 'fcm' && config.firebase) await env.deleteFcmToken(config.firebase); } catch { /* the device is already off */ } }
    }
    return true;
  }

  // Shows every message the server delivered to this device; a 404 means the device was removed, so stop.
  async function collect(userId) {
    const token = read(userId);
    if (!token || env.Notification.permission !== 'granted') return 0;
    // Real FCM delivers through the service worker's push event; only the trial sink is read back from the server.
    if (!token.startsWith('trial-')) return 0;
    let items;
    try { ({ items } = await api.trialMessages(token)); } catch (error) { if (error?.status === 404) write(userId, null); return 0; }
    if (!items.length) return 0;
    const registration = await env.serviceWorker.ready;
    for (const item of items) {
      await registration.showNotification(item.title, { body: item.body, tag: String(item.data?.notificationId ?? item.id), data: item.data, icon: '/favicon.ico' });
    }
    return items.length;
  }

  return { status, enable, disable, sync, collect, hasDevice: (userId) => Boolean(read(userId)) };
}

export function browserEnv() {
  return {
    Notification: typeof Notification === 'undefined' ? null : Notification,
    serviceWorker: typeof navigator !== 'undefined' && 'serviceWorker' in navigator ? navigator.serviceWorker : null,
    randomId: () => crypto.randomUUID().replaceAll('-', ''),
    randomUuid: () => crypto.randomUUID(),
    // Firebase is loaded only when the server says the browser channel is real: the default build never downloads it.
    getFcmToken: async (config, registration) => {
      const [{ initializeApp, getApps }, { getMessaging, getToken, isSupported }] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
      if (!(await isSupported())) throw Object.assign(new Error('unsupported'), { code: 'messaging/unsupported-browser' });
      const app = getApps()[0] || initializeApp({ apiKey: config.apiKey, projectId: config.projectId, messagingSenderId: config.messagingSenderId, appId: config.appId });
      return getToken(getMessaging(app), { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
    },
    deleteFcmToken: async (config) => {
      const [{ getApps }, { getMessaging, deleteToken }] = await Promise.all([import('firebase/app'), import('firebase/messaging')]);
      const app = getApps()[0];
      if (app) await deleteToken(getMessaging(app));
    },
  };
}
