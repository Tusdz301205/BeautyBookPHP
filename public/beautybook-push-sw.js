// Service worker for BeautyBook device notifications. Trial/preview builds hand it messages through
// registration.showNotification(); a real FCM web channel would deliver the same payload in the `push` event.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  let payload = {};
  try { payload = event.data ? event.data.json() : {}; } catch { payload = {}; }
  const notification = payload.notification || payload;
  if (!notification.title) return;
  event.waitUntil(self.registration.showNotification(notification.title, { body: notification.body || '', tag: String(payload.data?.notificationId || ''), data: payload.data || {} }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const target = clients.find((client) => client.url.includes('/beautybook'));
    if (target) return target.focus();
    return self.clients.openWindow('/beautybook/');
  })());
});
