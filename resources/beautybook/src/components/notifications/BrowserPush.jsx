import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { BellRing, BellOff } from 'lucide-react';
import { notificationsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { browserEnv, createBrowserPush } from '../../utils/browserPush';

export const browserPush = createBrowserPush({
  api: {
    pushConfig: () => notificationsApi.pushConfig(),
    registerDevice: (token, platform, installationId) => notificationsApi.registerDeviceToken(token, platform, installationId),
    removeDevice: (token, installationId) => notificationsApi.removeDeviceToken(token, installationId),
    trialMessages: (token) => notificationsApi.trialMessages(token),
  },
  storage: typeof localStorage === 'undefined' ? { getItem: () => null, setItem: () => {}, removeItem: () => {} } : localStorage,
  env: browserEnv(),
});

const POLL_MS = 5000;

/** Shows messages pushed to this browser while any signed-in page is open and visible. */
export function useBrowserPushReceiver() {
  const userId = useAuthStore((state) => state.user?.id);
  useEffect(() => {
    if (!userId) return undefined;
    let busy = false;
    // The sign-in that brought us here is a new session: bind this browser's device to it before polling.
    void browserPush.sync(userId);
    const tick = async () => {
      if (busy || document.hidden || !browserPush.hasDevice(userId)) return;
      busy = true;
      try { await browserPush.collect(userId); } catch { /* the next tick retries */ } finally { busy = false; }
    };
    void tick();
    const timer = setInterval(tick, POLL_MS);
    return () => clearInterval(timer);
  }, [userId]);
}

/** Switch on the notification page: register, receive and remove push on this browser. */
export function BrowserPushPanel() {
  const userId = useAuthStore((state) => state.user?.id);
  const [state, setState] = useState(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState('');
  const refresh = useCallback(async () => {
    if (!userId) return;
    try { setState(await browserPush.status(userId)); } catch { setState({ supported: false, mode: 'off', enabled: false }); }
  }, [userId]);
  useEffect(() => { void refresh(); }, [refresh]);
  const label = useMemo(() => (state?.enabled ? 'Đang nhận thông báo đẩy trên trình duyệt này' : 'Nhận thông báo đẩy trên trình duyệt này'), [state]);
  if (!userId || !state?.supported || !['trial', 'fcm'].includes(state.mode)) return null;

  const toggle = async () => {
    setBusy(true); setMessage('');
    try {
      if (state.enabled) { await browserPush.disable(userId); setMessage('Đã tắt thông báo đẩy trên trình duyệt này.'); }
      else { await browserPush.enable(userId); setMessage('Đã bật. Thông báo mới sẽ hiện ngay cả khi bạn đang ở trang khác.'); }
    } catch (error) { setMessage(error.message || 'Không thể thay đổi thông báo đẩy.'); }
    finally { setBusy(false); await refresh(); }
  };

  return <section className="flex flex-wrap items-center gap-3 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface)] p-3" aria-label="Thông báo đẩy">
    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--bb-surface-subtle)] text-[var(--bb-brand-strong)]">{state.enabled ? <BellRing size={18} aria-hidden="true" /> : <BellOff size={18} aria-hidden="true" />}</span>
    <span className="min-w-0 flex-1"><b className="block text-sm">{label}</b>
      <span className="block text-xs text-[var(--bb-muted)]" role="status">{message || (state.permission === 'denied' ? 'Trình duyệt đang chặn thông báo cho trang này.' : 'Chế độ thử nghiệm: thiết bị được đăng ký riêng cho tài khoản đang đăng nhập.')}</span></span>
    <button type="button" onClick={toggle} disabled={busy} aria-pressed={state.enabled} className="min-h-10 rounded-lg border border-[var(--bb-border)] px-4 text-sm font-semibold hover:bg-[var(--bb-surface-subtle)] disabled:opacity-60">{busy ? 'Đang xử lý…' : state.enabled ? 'Tắt' : 'Bật'}</button>
  </section>;
}
