import { useEffect } from 'react';

// Sockets remain responsible for reconnect/resync; also refresh after a tab resumes.
export function useOperationalRefresh(refresh) {
  useEffect(() => {
    const visible = () => { if (document.visibilityState === 'visible') refresh(); };
    window.addEventListener('focus', visible);
    window.addEventListener('online', visible);
    document.addEventListener('visibilitychange', visible);
    return () => {
      window.removeEventListener('focus', visible);
      window.removeEventListener('online', visible);
      document.removeEventListener('visibilitychange', visible);
    };
  }, [refresh]);
}
