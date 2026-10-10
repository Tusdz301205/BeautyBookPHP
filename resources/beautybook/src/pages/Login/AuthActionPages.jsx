import React, { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowRight, CheckCircle2 } from 'lucide-react';
import { authApi, staffApi } from '../../api/apiClient';
import { AuthField, AuthInput, AuthShell, PasswordControl } from '../../components/public/AuthShell';
import { useAuthStore } from '../../store/authStore';

function ActionResult({ title, message }) {
  return (
    <div className="bb-auth-result" role="status">
      <CheckCircle2 size={32} aria-hidden="true" />
      <h2>{title}</h2>
      <p>{message}</p>
      <Link to="/login">Đăng nhập <ArrowRight size={16} /></Link>
    </div>
  );
}

function SubmitButton({ busy, children }) {
  return (
    <button className="bb-auth-submit" type="submit" disabled={busy} aria-busy={busy}>
      <span>{busy ? 'Đang xử lý…' : children}</span><ArrowRight size={17} aria-hidden="true" />
    </button>
  );
}

export function ForgotPassword() {
  const [email, setEmail] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState('');
  const submit = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await authApi.forgotPassword(email.trim());
      setDone(true);
    } catch (requestError) {
      setError(requestError.message || 'Chưa thể gửi hướng dẫn. Kiểm tra kết nối rồi thử lại.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <AuthShell mode="recovery" eyebrow="Bảo mật tài khoản" title="Khôi phục mật khẩu" description="Nếu email tồn tại, BeautyBook sẽ gửi một liên kết đặt lại có hiệu lực trong 30 phút.">
      {done ? <ActionResult title="Yêu cầu đã được tiếp nhận" message="Kiểm tra hộp thư đến và thư rác. Vì lý do bảo mật, BeautyBook không xác nhận email có tồn tại hay không." /> : (
        <form onSubmit={submit} className="bb-auth-form">
          <AuthField id="recovery-email" label="Địa chỉ email" required>
            <AuthInput type="email" inputMode="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} />
          </AuthField>
          {error ? <div className="bb-auth-alert" role="alert">{error}</div> : null}
          <SubmitButton busy={busy}>Gửi hướng dẫn</SubmitButton>
        </form>
      )}
      <p className="bb-auth-guest"><Link to="/login">Quay lại đăng nhập</Link></p>
    </AuthShell>
  );
}

export function ResetPassword() {
  const [params] = useSearchParams();
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState('');
  const token = params.get('token');
  const validPassword = password.length >= 8 && /[a-z]/.test(password) && /[A-Z]/.test(password) && /\d/.test(password);
  const submit = async (event) => {
    event.preventDefault();
    if (busy || !validPassword) {
      if (!validPassword) setError('Dùng ít nhất 8 ký tự, gồm chữ hoa, chữ thường và số.');
      return;
    }
    setBusy(true);
    setError('');
    try {
      await authApi.resetPassword(token, password);
      setDone(true);
    } catch (requestError) {
      setError(requestError.message || 'Liên kết không hợp lệ hoặc đã hết hạn. Yêu cầu một liên kết mới.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <AuthShell mode="recovery" eyebrow="Bảo mật tài khoản" title="Đặt lại mật khẩu" description="Mật khẩu mới sẽ thu hồi các phiên đăng nhập đang hoạt động.">
      {!token ? <div className="bb-auth-alert" role="alert">Liên kết đặt lại không hợp lệ. Hãy yêu cầu một liên kết mới.</div> : done ? (
        <ActionResult title="Mật khẩu đã được thay đổi" message="Đăng nhập lại bằng mật khẩu mới để tiếp tục." />
      ) : (
        <form onSubmit={submit} className="bb-auth-form">
          <AuthField id="reset-password" label="Mật khẩu mới" required error={error} hint="Ít nhất 8 ký tự, có chữ hoa, chữ thường và số.">
            <PasswordControl label="mật khẩu mới" autoComplete="new-password" maxLength={128} value={password} onChange={(event) => { setPassword(event.target.value); setError(''); }} />
          </AuthField>
          <SubmitButton busy={busy}>Đặt lại mật khẩu</SubmitButton>
        </form>
      )}
      <p className="bb-auth-guest"><Link to="/forgot-password">Yêu cầu liên kết mới</Link></p>
    </AuthShell>
  );
}

export function VerifyEmail() {
  const [params] = useSearchParams();
  const [state, setState] = useState({ loading: true, error: '' });
  const token = params.get('token');
  useEffect(() => {
    if (!token) {
      setState({ loading: false, error: 'Liên kết xác minh không hợp lệ hoặc thiếu token.' });
      return;
    }
    authApi.verifyEmail(token)
      .then(() => setState({ loading: false, error: '' }))
      .catch((requestError) => setState({ loading: false, error: requestError.message || 'Liên kết đã hết hạn hoặc đã được sử dụng.' }));
  }, [token]);
  return (
    <AuthShell mode="recovery" eyebrow="Xác minh email" title="Kiểm tra liên kết xác minh" description="BeautyBook đang xác nhận liên kết được gửi tới email đăng ký.">
      {state.loading ? <div className="bb-auth-loading" role="status">Đang xác minh…</div> : state.error ? <div className="bb-auth-alert" role="alert">{state.error}</div> : <ActionResult title="Email đã được xác minh" message="Tài khoản của bạn đã sẵn sàng để đăng nhập và quản lý lịch hẹn." />}
    </AuthShell>
  );
}

export function AcceptInvitation() {
  const [params] = useSearchParams();
  const [form, setForm] = useState({ fullName: '', password: '', phone: '' });
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState('');
  const [context, setContext] = useState(null);
  const [loadingContext, setLoadingContext] = useState(true);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated);
  const token = params.get('token');
  useEffect(() => {
    if (!token) {
      setLoadingContext(false);
      return;
    }
    staffApi.getInvitationContext(token)
      .then(setContext)
      .catch((requestError) => setError(requestError.message || 'Lời mời không hợp lệ hoặc đã hết hạn.'))
      .finally(() => setLoadingContext(false));
  }, [token]);
  const update = (key) => (event) => setForm((value) => ({ ...value, [key]: event.target.value }));
  const submit = async (event) => {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await staffApi.acceptInvitation({ ...form, token });
      setDone(true);
    } catch (requestError) {
      setError(requestError.message || 'Không thể hoàn tất lời mời. Kiểm tra lại liên kết rồi thử lại.');
    } finally {
      setBusy(false);
    }
  };
  const acceptExisting = async () => {
    if (busy) return;
    setBusy(true);
    setError('');
    try {
      await staffApi.acceptExistingInvitation(token);
      setDone(true);
    } catch (requestError) {
      setError(requestError.message || 'Không thể chấp nhận lời mời bằng tài khoản hiện tại.');
    } finally {
      setBusy(false);
    }
  };
  return (
    <AuthShell mode="invitation" eyebrow="Lời mời nhân viên" title="Hoàn tất tài khoản" description="Vai trò và phạm vi truy cập được lấy từ lời mời, không phải từ biểu mẫu này.">
      {!token ? <div className="bb-auth-alert" role="alert">Liên kết lời mời không hợp lệ hoặc thiếu token.</div> : loadingContext ? (
        <div className="bb-auth-loading" role="status">Đang kiểm tra lời mời…</div>
      ) : done ? (
        <ActionResult title="Lời mời đã được chấp nhận" message="Bạn có thể đăng nhập vào không gian Cơ sở để bắt đầu làm việc." />
      ) : error && !context ? (
        <div className="bb-auth-alert" role="alert">{error}</div>
      ) : context?.existingAccount ? (
        <div className="bb-auth-form">
          <div className="bb-auth-alert">Email <strong>{context.email}</strong> đã có tài khoản BeautyBook. Hệ thống sẽ liên kết hồ sơ nhân sự này, không tạo User mới.</div>
          {error ? <div className="bb-auth-alert" role="alert">{error}</div> : null}
          {isAuthenticated() ? (
            <button className="bb-auth-submit" type="button" disabled={busy} onClick={acceptExisting}>Chấp nhận bằng tài khoản hiện tại</button>
          ) : (
            <Link className="bb-auth-submit" to="/login" state={{ from: `/accept-invitation?token=${encodeURIComponent(token)}`, workspace: 'SALON' }}>Đăng nhập để chấp nhận</Link>
          )}
        </div>
      ) : (
        <form onSubmit={submit} className="bb-auth-form">
          <AuthField id="invite-name" label="Họ và tên" required><AuthInput autoComplete="name" required value={form.fullName} onChange={update('fullName')} /></AuthField>
          <AuthField id="invite-phone" label="Số điện thoại" required><AuthInput type="tel" inputMode="tel" autoComplete="tel" required value={form.phone} onChange={update('phone')} /></AuthField>
          <AuthField id="invite-password" label="Mật khẩu" required hint="Ít nhất 8 ký tự."><PasswordControl label="mật khẩu" autoComplete="new-password" minLength={8} required value={form.password} onChange={update('password')} /></AuthField>
          {error ? <div className="bb-auth-alert" role="alert">{error}</div> : null}
          <SubmitButton busy={busy}>Tạo tài khoản</SubmitButton>
        </form>
      )}
    </AuthShell>
  );
}
