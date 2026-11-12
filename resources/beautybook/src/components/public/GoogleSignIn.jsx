import React, { useEffect, useRef, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { authApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { registrationReturnPath } from '../../utils/registrationIntent';

// Google Identity Services button (from the web package). The server issues a nonce bound to this browser session
// and verifies the returned ID token; the Client ID is public configuration, no secret reaches the browser.
let sdkPromise;
function loadGoogle() {
  if (window.google?.accounts?.id) return Promise.resolve(window.google.accounts.id);
  if (!sdkPromise) {
    sdkPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = 'https://accounts.google.com/gsi/client';
      script.async = true;
      const timer = setTimeout(() => { script.remove(); reject(new Error('Không tải được đăng nhập Google.')); }, 15000);
      script.onload = () => { clearTimeout(timer); if (window.google?.accounts?.id) resolve(window.google.accounts.id); else reject(new Error('Không tải được đăng nhập Google.')); };
      script.onerror = () => { clearTimeout(timer); script.remove(); reject(new Error('Không tải được đăng nhập Google.')); };
      document.head.append(script);
    }).catch((error) => { sdkPromise = null; throw error; });
  }
  return sdkPromise;
}

const homeFor = (type) => (type === 'admin' ? '/admin' : type === 'salon' ? '/salon' : '/customer/appointments');

export default function GoogleSignIn({ accountType = 'CUSTOMER', disabled = false, onBusyChange }) {
  const host = useRef(null);
  const latest = useRef({ disabled, onBusyChange });
  const navigate = useNavigate();
  const location = useLocation();
  const [state, setState] = useState('loading');
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  latest.current = { disabled, onBusyChange, location };

  useEffect(() => {
    const owner = { active: true, busy: false };
    setState('loading'); setError('');
    (async () => {
      try {
        const config = await authApi.googleConfig();
        if (!owner.active) return;
        if (!config.enabled) { setState('unavailable'); return; }
        const google = await loadGoogle();
        if (!owner.active || !host.current) return;
        google.initialize({
          client_id: config.clientId, nonce: config.nonce, auto_select: false,
          callback: async (response) => {
            if (!owner.active || owner.busy || latest.current.disabled || !response.credential) return;
            owner.busy = true; latest.current.onBusyChange?.(true); setError('');
            try {
              const user = await useAuthStore.getState().googleLogin(response.credential, accountType);
              if (owner.active) navigate(registrationReturnPath(latest.current.location) || homeFor(user.sessionType), { replace: true });
            } catch (problem) {
              if (owner.active) setError(problem.status === 409 ? 'Email này đã có tài khoản. Hãy đăng nhập bằng mật khẩu hoặc dùng Quên mật khẩu.' : problem.status === 401 ? 'Không xác minh được tài khoản Google. Tải lại trang và thử lại.' : problem.message || 'Chưa thể đăng nhập Google. Vui lòng thử lại.');
            } finally { owner.busy = false; if (owner.active) latest.current.onBusyChange?.(false); }
          },
        });
        host.current.replaceChildren();
        google.renderButton(host.current, { type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'rectangular', locale: 'vi', width: Math.min(host.current.parentElement.clientWidth || 320, 400) });
        setState('ready');
      } catch (problem) { if (owner.active) { setState('error'); setError(problem.message || 'Không tải được đăng nhập Google.'); } }
    })();
    return () => { owner.active = false; };
  }, [accountType, retry, navigate]);

  if (state === 'unavailable') return null;
  return <div className="bb-auth-google" style={{ marginTop: '1.25rem' }}>
    <div className="bb-auth-divider" style={{ display: 'flex', alignItems: 'center', gap: '.75rem', marginBottom: '1rem', color: 'var(--bb-muted)', fontSize: '.75rem' }}><span>hoặc</span></div>
    <div style={{ display: 'grid', minHeight: 44, justifyItems: 'center', opacity: disabled ? 0.6 : 1 }}>
      <div ref={host} style={{ display: state === 'ready' ? 'flex' : 'none', width: '100%', justifyContent: 'center', pointerEvents: disabled ? 'none' : undefined }} />
      {state !== 'ready' && <button type="button" disabled className="bb-auth-google__fallback" style={{ minHeight: 44, width: '100%', border: '1px solid var(--bb-border)', borderRadius: '.5rem', fontSize: '.875rem' }}>{state === 'loading' ? 'Đang tải Google…' : 'Tiếp tục với Google'}</button>}
    </div>
    {error && <div className="bb-auth-alert" role="alert">{error} <button type="button" disabled={disabled} onClick={() => setRetry((value) => value + 1)}>Thử lại</button></div>}
  </div>;
}
