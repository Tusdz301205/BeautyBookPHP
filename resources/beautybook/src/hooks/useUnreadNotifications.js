import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { notificationsApi } from '../api/apiClient';
import { useAuthStore } from '../store/authStore';

// One polling lease per mounted zone shell; mutations request an immediate refresh.
export function useUnreadNotifications() {
  const userId = useAuthStore(state => state.user?.id);
  const { pathname } = useLocation();
  const [snapshot, setSnapshot] = useState({ userId: null, count: 0 });
  useEffect(() => {
    let active = true;
    let sequence = 0;
    const refresh = async () => {
      const request = ++sequence;
      if (!userId) return;
      try {
        const result = await notificationsApi.getUnreadCount();
        const count = Number(result?.count ?? result ?? 0);
        if (active && request === sequence) setSnapshot({ userId, count: Number.isFinite(count) ? Math.max(0, count) : 0 });
      } catch { /* Retain the last known count through transient network failures. */ }
    };
    void refresh();
    const timer = window.setInterval(refresh, 60_000);
    const focus = () => { if (!document.hidden) void refresh(); };
    window.addEventListener('beautybook:notifications-changed', refresh);
    window.addEventListener('focus', focus);
    document.addEventListener('visibilitychange', focus);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('beautybook:notifications-changed', refresh);
      window.removeEventListener('focus', focus);
      document.removeEventListener('visibilitychange', focus);
    };
  }, [userId, pathname]);
  return snapshot.userId === userId ? snapshot.count : 0;
}
