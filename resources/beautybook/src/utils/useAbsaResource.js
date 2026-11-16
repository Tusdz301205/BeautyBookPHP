import {useEffect, useRef, useState} from 'react';
import {useAuthStore} from '../store/authStore';
import {roleAt} from './authScope';

// Bind each response and callback to the current PHP session and branch.
export function useOwnerResource({businessId, branchId, resourceKey, read}) {
  const user = useAuthStore(s => s.user), token = useAuthStore(s => s.accessToken);
  const allowed = Boolean(businessId && branchId && token && roleAt(user, 'BUSINESS_OWNER', businessId));
  const key = JSON.stringify([user?.id, token, businessId, branchId, resourceKey, allowed]);
  const latest = useRef({key, read}); latest.current = {key, read};
  const [version, setVersion] = useState(0);
  const [state, setState] = useState({key: null, value: null, error: ''});
  useEffect(() => {
    let active = true;
    const current = () => {
      const auth = useAuthStore.getState();
      return active && latest.current.key === key && auth.accessToken === token && auth.user?.id === user?.id
        && auth.activeContext().tenantId === businessId && roleAt(auth.user, 'BUSINESS_OWNER', businessId);
    };
    setState({key: null, value: null, error: ''});
    const readCurrent = latest.current.read;
    if (allowed) Promise.resolve().then(() => current() ? readCurrent() : null).then(value => {
      if (current()) setState({key, value, error: ''});
    }).catch(error => { if (current()) setState({key, value: null, error: error.message || 'Không thể tải dữ liệu.'}); });
    return () => {active = false;};
  }, [key, version]);
  const matches = allowed && state.key === key;
  return {allowed, ready: matches && !state.error, value: matches ? state.value : null,
    error: matches ? state.error : '', reload: () => { if (latest.current.key === key) setVersion(v => v + 1); }};
}
