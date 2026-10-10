import React, { useState } from 'react';
import { ArrowLeft, Eye, EyeOff, Sparkles } from 'lucide-react';
import { Link } from 'react-router-dom';
import { getHomeMedia, homeMediaRatios } from '../../config/homeMedia';
import { HomeMedia } from './HomeMedia';

const panelCopy = {
  login: {
    title: 'Lịch hẹn đẹp bắt đầu từ một lựa chọn rõ ràng.',
    description: 'Tìm cơ sở, chọn dịch vụ và giữ mọi lịch hẹn của bạn trong cùng một nơi.',
  },
  register: {
    title: 'Một điểm bắt đầu, cho khách hàng và người làm đẹp.',
    description: 'Đặt lịch chăm sóc cho riêng bạn, hoặc đưa doanh nghiệp lên BeautyBook qua quy trình xét duyệt minh bạch.',
  },
  recovery: {
    title: 'Trở lại lịch hẹn của bạn, theo cách an toàn.',
    description: 'Các liên kết bảo mật có thời hạn và chỉ được gửi tới email đã đăng ký.',
  },
  invitation: {
    title: 'Lời mời đúng phạm vi. Quyền truy cập đúng vai trò.',
    description: 'Tài khoản nhân viên chỉ được tạo từ lời mời đã cấp bởi doanh nghiệp.',
  },
};

export function AuthShell({ mode = 'login', eyebrow, title, description, children }) {
  const copy = panelCopy[mode] || panelCopy.login;
  return (
    <div className="bb-auth-shell">
      <a className="bb-home-skip" href="#auth-main">Bỏ qua phần giới thiệu</a>
      <aside className="bb-auth-editorial" aria-label="Giới thiệu BeautyBook">
        <Link to="/" className="bb-auth-brand" aria-label="BeautyBook — về trang chủ">
          <span aria-hidden="true"><Sparkles size={19} /></span>
          <strong>BeautyBook</strong>
        </Link>
        <div className="bb-auth-editorial__copy">
          <p className="bb-home-kicker">Beauty, thoughtfully booked</p>
          <p>{copy.title}</p>
          <span>{copy.description}</span>
        </div>
        <HomeMedia
          src={getHomeMedia('auth', mode)}
          alt=""
          ratio={homeMediaRatios.auth}
          label="Your beauty ritual"
          className="bb-auth-editorial__media"
        />
      </aside>

      <main id="auth-main" className="bb-auth-main">
        <div className="bb-auth-main__inner">
          <Link to="/" className="bb-auth-back"><ArrowLeft size={16} /> Trang chủ</Link>
          <div className="bb-auth-mobile-brand" aria-hidden="true">
            <span><Sparkles size={17} /></span><strong>BeautyBook</strong>
          </div>
          <header className="bb-auth-heading">
            <p>{eyebrow}</p>
            <h1>{title}</h1>
            <span>{description}</span>
          </header>
          {children}
        </div>
      </main>
    </div>
  );
}

export function AuthField({ id, label, required = false, hint, error, children }) {
  const describedBy = error ? `${id}-error` : hint ? `${id}-hint` : undefined;
  const control = React.isValidElement(children) ? React.cloneElement(children, {
    id,
    'aria-required': required || undefined,
    'aria-invalid': Boolean(error),
    'aria-describedby': describedBy,
  }) : children;
  return (
    <div className={`bb-auth-field ${error ? 'has-error' : ''}`}>
      <label htmlFor={id}>{label}{required ? <><span aria-hidden="true"> *</span><span className="bb-sr-only"> (bắt buộc)</span></> : null}</label>
      {control}
      <span id={describedBy} className="bb-auth-field__message" role={error ? 'alert' : undefined}>
        {error || hint || '\u00a0'}
      </span>
    </div>
  );
}

export const AuthInput = React.forwardRef(function AuthInput(props, ref) {
  return <input ref={ref} className="bb-auth-input" {...props} />;
});

export const PasswordControl = React.forwardRef(function PasswordControl({ label = 'mật khẩu', ...props }, ref) {
  const [visible, setVisible] = useState(false);
  return (
    <div className="bb-auth-password">
      <input ref={ref} className="bb-auth-input" type={visible ? 'text' : 'password'} {...props} />
      <button
        type="button"
        aria-label={`${visible ? 'Ẩn' : 'Hiện'} ${label}`}
        aria-pressed={visible}
        onClick={() => setVisible((value) => !value)}
      >
        {visible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
      </button>
    </div>
  );
});
