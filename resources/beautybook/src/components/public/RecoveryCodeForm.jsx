import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import { authApi } from '../../api/apiClient';
import { AuthField, AuthInput, AuthShell, PasswordControl } from './AuthShell';

// Email -> six-digit code -> new password. The server answers identically for unknown addresses.
export default function RecoveryCodeForm() {
  const [email, setEmail] = useState('');
  const [requestId, setRequestId] = useState(null);
  const [code, setCode] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState('');
  const [resendAt, setResendAt] = useState(0);
  const [now, setNow] = useState(Date.now());
  const alive = useRef(true);
  useEffect(() => { alive.current = true; return () => { alive.current = false; }; }, []);
  useEffect(() => { if (!resendAt) return undefined; const timer = setInterval(() => setNow(Date.now()), 1000); return () => clearInterval(timer); }, [resendAt]);
  const remaining = Math.max(0, Math.ceil((resendAt - now) / 1000));

  const requestCode = async () => {
    if (busy) return;
    setBusy(true); setError('');
    try {
      const result = await authApi.requestRecoveryCode(email.trim().toLowerCase());
      if (alive.current) { const started = Date.now(); setRequestId(result.requestId); setCode(''); setNow(started); setResendAt(started + (result.resendAfter || 60) * 1000); }
    } catch (problem) { if (alive.current) setError(problem.status === 429 ? 'Bạn đã yêu cầu quá nhiều mã. Vui lòng thử lại sau.' : problem.message || 'Chưa thể gửi mã. Kiểm tra kết nối rồi thử lại.'); }
    finally { if (alive.current) setBusy(false); }
  };
  const submit = async (event) => {
    event.preventDefault();
    if (!requestId) { await requestCode(); return; }
    if (busy) return;
    if (!/^\d{6}$/.test(code)) { setError('Nhập đủ 6 chữ số trong email.'); return; }
    if (password.length < 8 || password.length > 128) { setError('Mật khẩu cần từ 8 đến 128 ký tự, gồm chữ hoa, chữ thường và số.'); return; }
    if (password !== confirmation) { setError('Mật khẩu nhập lại chưa khớp.'); return; }
    setBusy(true); setError('');
    try { await authApi.resetWithCode(requestId, code, password); if (alive.current) { setDone(true); setCode(''); setPassword(''); setConfirmation(''); } }
    catch (problem) { if (alive.current) setError(problem.status === 400 ? 'Mã không hợp lệ hoặc đã hết hạn. Kiểm tra lại hoặc yêu cầu mã mới.' : problem.message || 'Không đặt lại được mật khẩu. Vui lòng thử lại.'); }
    finally { if (alive.current) setBusy(false); }
  };

  return <AuthShell mode="recovery" eyebrow="Bảo mật tài khoản" title={done ? 'Đã đặt lại mật khẩu' : requestId ? 'Xác nhận email' : 'Quên mật khẩu?'}
    description={done ? 'Bạn có thể đăng nhập bằng mật khẩu mới.' : requestId ? 'Nhập mã 6 số trong email và tạo mật khẩu mới. Mã có hiệu lực trong 10 phút.' : 'Nhập email đã đăng ký để nhận mã xác nhận.'}>
    {done ? <div className="bb-auth-result" role="status"><CheckCircle2 size={32} aria-hidden="true" /><h2>Mật khẩu đã được thay đổi</h2><p>Các phiên đăng nhập cũ đã được thu hồi. Đăng nhập lại để tiếp tục.</p><Link to="/login">Đăng nhập <ArrowRight size={16} /></Link></div>
      : <form className="bb-auth-form" onSubmit={submit}>
        {!requestId ? <AuthField id="recovery-email" label="Địa chỉ email" required><AuthInput type="email" inputMode="email" autoComplete="email" required maxLength={254} disabled={busy} value={email} onChange={(event) => setEmail(event.target.value)} /></AuthField> : <>
          <div className="bb-auth-recovery-email"><p>Nếu email đã đăng ký, mã sẽ được gửi đến <strong>{email.trim()}</strong>. Bạn nhớ kiểm tra cả thư rác.</p><button type="button" disabled={busy} onClick={() => { setRequestId(null); setCode(''); setPassword(''); setConfirmation(''); setError(''); }}>Đổi email</button></div>
          <AuthField id="recovery-code" label="Mã xác nhận" required hint="Nhập mã gồm 6 chữ số từ email."><AuthInput autoFocus type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" minLength={6} maxLength={6} required disabled={busy} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} /></AuthField>
          <AuthField id="recovery-new-password" label="Mật khẩu mới" required hint="Từ 8 đến 128 ký tự, gồm chữ hoa, chữ thường và số."><PasswordControl label="mật khẩu mới" autoComplete="new-password" minLength={8} maxLength={128} required disabled={busy} value={password} onChange={(event) => setPassword(event.target.value)} /></AuthField>
          <AuthField id="recovery-confirm-password" label="Nhập lại mật khẩu mới" required><PasswordControl label="mật khẩu nhập lại" autoComplete="new-password" minLength={8} maxLength={128} required disabled={busy} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} /></AuthField>
        </>}
        {error && <div className="bb-auth-alert" role="alert">{error}</div>}
        <button type="submit" className="bb-auth-submit" disabled={busy} aria-busy={busy}><span>{busy ? 'Đang xử lý…' : requestId ? 'Xác nhận và đổi mật khẩu' : 'Gửi mã xác nhận'}</span><ArrowRight size={17} aria-hidden="true" /></button>
        {requestId && <div className="bb-auth-resend"><span>Chưa nhận được mã?</span><button type="button" disabled={busy || remaining > 0} onClick={requestCode}>{remaining > 0 ? `Gửi lại sau ${remaining}s` : 'Gửi lại mã'}</button></div>}
      </form>}
    <p className="bb-auth-guest"><Link to="/login">Quay lại đăng nhập</Link></p>
  </AuthShell>;
}
