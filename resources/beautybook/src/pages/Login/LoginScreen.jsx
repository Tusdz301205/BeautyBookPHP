import React, { useState } from 'react';
import { Link, Navigate, useLocation, useNavigate } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import toast from 'react-hot-toast';
import { useAuthStore } from '../../store/authStore';
import { AuthField, AuthInput, AuthShell, PasswordControl } from '../../components/public/AuthShell';

import { registrationReturnPath } from '../../utils/registrationIntent';

const homeFor = (type) => type === 'admin' ? '/admin' : type === 'salon' ? '/salon' : '/customer/appointments';

export function LoginScreen() {
  const navigate = useNavigate();
  const location = useLocation();
  const { login, isAuthenticated, user } = useAuthStore();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const requestedPath = registrationReturnPath(location);
  if (isAuthenticated()) return <Navigate to={requestedPath || homeFor(user?.sessionType)} replace />;

  const submit = async (event) => {
    event.preventDefault();
    if (loading) return;
    setLoading(true);
    setError('');
    try {
      // Workspace selection is intentionally disabled on this screen.
      // The API resolves the account's single valid workspace from active roles.
      const loggedIn = await login(email.trim(), password);
      toast.success(`Chào mừng ${loggedIn.fullName || 'bạn'}`);
      navigate(requestedPath || homeFor(loggedIn.sessionType), { replace: true });
    } catch (err) {
      setError(err.message || 'Không thể đăng nhập. Kiểm tra kết nối rồi thử lại.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AuthShell
      mode="login"
      eyebrow="Chào mừng trở lại"
      title="Đăng nhập BeautyBook"
      description="Dùng tài khoản đã đăng ký hoặc thông tin được doanh nghiệp cấp."
    >
      <form onSubmit={submit} className="bb-auth-form" noValidate>
        <AuthField id="login-email" label="Địa chỉ email" required>
          <AuthInput type="email" inputMode="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} />
        </AuthField>
        <AuthField id="login-password" label="Mật khẩu" required>
          <PasswordControl label="mật khẩu" autoComplete="current-password" required minLength={6} value={password} onChange={(event) => setPassword(event.target.value)} />
        </AuthField>
        {error ? <div className="bb-auth-alert" role="alert">{error}</div> : null}
        <div className="bb-auth-form__between">
          <Link to="/forgot-password">Quên mật khẩu?</Link>
        </div>
        <button className="bb-auth-submit" type="submit" disabled={loading} aria-busy={loading}>
          <span>{loading ? 'Đang đăng nhập…' : 'Đăng nhập'}</span><ArrowRight size={17} aria-hidden="true" />
        </button>
      </form>
      <div className="bb-auth-alternate">
        <p>Chưa có tài khoản BeautyBook?</p>
        <Link to={requestedPath === "/register/business" ? requestedPath : requestedPath ? `/register?from=${encodeURIComponent(requestedPath)}` : "/register"} state={requestedPath ? { from: requestedPath } : undefined}>Tạo tài khoản</Link>
      </div>
      <p className="bb-auth-guest">Bạn có thể khám phá cơ sở trước khi đăng nhập. <Link to="/explore">Khám phá BeautyBook</Link></p>
    </AuthShell>
  );
}

export default LoginScreen;
