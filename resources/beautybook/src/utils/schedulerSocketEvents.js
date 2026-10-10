import { createCoalescedRefresh } from './coalescedRefresh.js';
export function bindSchedulerSocketEvents(socket, { refresh, clearScopedData, authFailed, authorizationChanged, coalesceRefresh = false }) {
  const queue = coalesceRefresh ? createCoalescedRefresh(refresh) : null;
  const refreshNow = queue ? () => queue.request() : refresh;
  const handlers = {
    connect: refreshNow,
    booking_updated: refreshNow,
    booking_created: refreshNow,
    booking_deleted: refreshNow,
    scheduler_resync: refreshNow,
    scheduler_access_changed: () => { queue?.cancel(); return authorizationChanged ? authorizationChanged() : (clearScopedData(), refresh()); },
    scheduler_auth_failed: () => { queue?.cancel(); authFailed(); },
    connect_error: (error) => {
      if (error?.data?.code === 'WS_AUTH_FAILED') { queue?.cancel(); authFailed(); }
    },
  };
  for (const [event, handler] of Object.entries(handlers)) socket.on(event, handler);
  return () => {
    queue?.cancel();
    for (const [event, handler] of Object.entries(handlers)) socket.off(event, handler);
  };
}
