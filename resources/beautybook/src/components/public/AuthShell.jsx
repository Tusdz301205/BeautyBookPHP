import React, { useState } from 'react';
import { ArrowLeft, ArrowUpRight, Sparkles, BadgeCheck, CalendarCheck, Clock3, Eye, EyeOff, MailCheck, ShieldCheck, Store } from 'lucide-react';
import { Link } from 'react-router-dom';
import { getHomeMedia, homeMediaRatios } from '../../config/homeMedia';
import { BrandLogo } from '../brand/BrandLogo';
import { HomeMedia } from './HomeMedia';
import AuthEditorialVisual from '../auth/AuthEditorialVisual';

const panelCopy = {
  login: {
    title: 'Lịch hẹn đẹp bắt đầu từ một lựa chọn rõ ràng.',
    description: 'Tìm cơ sở, chọn dịch vụ và giữ mọi lịch hẹn của bạn trong cùng một nơi.',
    points: [[BadgeCheck, 'Cơ sở làm đẹp đã qua xét duyệt'], [Clock3, 'Giờ trống được kiểm tra trước khi xác nhận'], [CalendarCheck, 'Theo dõi mọi lịch hẹn ở một nơi']],
  },
  register: {
    title: 'Một điểm bắt đầu, cho khách hàng và người làm đẹp.',
    description: `Đặt lịch chăm sóc cho riêng bạn, hoặc đưa doanh nghiệp lên BeautyBook qua quy trình xét duyệt minh bạch.`,
    points: [[CalendarCheck, 'Đặt lịch và theo dõi lịch hẹn của riêng bạn'], [Store, 'Đăng ký cơ sở với quy trình xét duyệt minh bạch']],
  },
  recovery: {
    title: 'Trở lại lịch hẹn của bạn, theo cách an toàn.',
    description: 'Các liên kết bảo mật có thời hạn và chỉ được gửi tới email đã đăng ký.',
    points: [[ShieldCheck, 'Liên kết đặt lại mật khẩu có thời hạn'], [MailCheck, 'Chỉ gửi tới email đã đăng ký']],
  },
  invitation: {
    title: 'Lời mời đúng phạm vi. Quyền truy cập đúng vai trò.',
    description: 'Tài khoản nhân viên chỉ được tạo từ lời mời đã cấp bởi doanh nghiệp.',
    points: [[ShieldCheck, 'Quyền truy cập theo lời mời của doanh nghiệp']],
  },
};

export function AuthShell({ mode = 'login', audience, eyebrow, title, description, children }) {
  audience = audience || (eyebrow?.includes('Business') ? 'business' : 'customer');
  const copy = audience==='business'?{title:'Đưa cơ sở của bạn đến gần khách hàng hơn.',description:'Một không gian để quản lý lịch hẹn, dịch vụ và đội ngũ mỗi ngày.',points:[[Store,'Hoàn thiện hồ sơ cơ sở theo từng bước'],[CalendarCheck,'Sắp xếp lịch hẹn và đội ngũ'],[ShieldCheck,'Công khai sau khi hồ sơ được xét duyệt']]}:panelCopy[mode] || panelCopy.login;
  if(mode==='register'||mode==='login')return <div className={`bb-auth-shell bb-auth-shell--refined bb-auth-shell--renewed bb-auth-shell--signup ${mode==='login'?'bb-auth-shell--signin':''}`}>
    <a className="bb-home-skip" href="#auth-main">{mode==='login'?'Đến phần đăng nhập':'Đến phần đăng ký'}</a>
    <header className="bb-signup-nav"><Link to="/" aria-label={`BeautyBook — trang chủ`}><BrandLogo size={38}/></Link><Link to={audience==='business'?'/for-business':'/'}>{audience==='business'?'Dành cho cơ sở':'Khám phá BeautyBook'}<ArrowUpRight size={16}/></Link></header>
    <aside className="bb-signup-story" aria-label="Giới thiệu BeautyBook"><span className="bb-signup-story__tag"><Sparkles size={14}/>{audience==='business'?'DÀNH CHO NGƯỜI LÀM ĐẸP':'MỘT CHÚT CHĂM SÓC, MỖI NGÀY'}</span>
      <h2>{mode==='login'?(audience==='business'?<>Chào ngày mới.<br/>Sẵn sàng <em>tỏa sáng.</em></>:<>Chào bạn trở lại.<br/>Hẹn một ngày <em>thật đẹp.</em></>):audience==='business'?<>Cơ sở của bạn.<br/>Khởi đầu <em>mới.</em></>:<>Một khởi đầu đẹp,<br/>dành riêng cho <em>bạn.</em></>}</h2>
      <p>{mode==='login'?(audience==='business'?'Tiếp tục quản lý cuộc hẹn, kết nối đội ngũ và mang đến những trải nghiệm chăm sóc chu đáo.':'Những cuộc hẹn yêu thích, những trải nghiệm mới và một chút thời gian dành riêng cho bạn — tất cả ở đây.'):audience==='business'?'Kết nối với khách hàng, sắp xếp lịch hẹn và chăm chút trải nghiệm dịch vụ từ một không gian chung.':'Tìm nơi làm đẹp bạn yêu thích. Chọn chuyên viên phù hợp. Dành thời gian cho chính mình.'}</p>
      <AuthEditorialVisual mode={mode} audience={audience}/>
      <div className="bb-signup-story__benefits">{(audience==='business'?[[Store,'Hồ sơ cơ sở'],[CalendarCheck,'Quản lý lịch hẹn'],[ShieldCheck,'Xét duyệt minh bạch']]:[[BadgeCheck,'Cơ sở được xét duyệt'],[CalendarCheck,'Đặt lịch dễ dàng'],[Clock3,'Theo dõi mọi cuộc hẹn']]).map(([Icon,text])=><span key={text}><Icon size={16}/>{text}</span>)}</div>
    </aside>
    <main id="auth-main" tabIndex={-1} className="bb-auth-main"><div className="bb-auth-main__inner"><header className="bb-auth-heading"><span className="bb-signup-form-icon" aria-hidden="true">{audience==='business'?<Store size={23}/>:<Sparkles size={23}/>}</span><p>{eyebrow}</p><h1>{title}</h1><span>{description}</span></header>{children}</div></main>
    <footer className="bb-signup-footer"><span>© {new Date().getFullYear()} BeautyBook</span><span>Chăm sóc bản thân. Tỏa sáng theo cách của bạn.</span></footer>
  </div>;
  return (
    <div className={`bb-auth-shell bb-auth-shell--refined ${mode==='recovery'?'':'bb-auth-shell--renewed'}`}>
      <a className="bb-home-skip" href="#auth-main">Bỏ qua phần giới thiệu</a>
      <aside className="bb-auth-editorial" aria-label={`Giới thiệu BeautyBook`}>
        <HomeMedia
          src={getHomeMedia('auth', mode)}
          alt=""
          ratio={homeMediaRatios.auth}
          label="Chăm sóc bản thân theo cách của bạn"
          className="bb-auth-editorial__media"
        />
        <Link to="/" className="bb-auth-brand" aria-label={`BeautyBook — về trang chủ`}>
          <BrandLogo size={42} className="bb-brand--on-dark" subtitle="Một cuộc hẹn dành riêng cho bạn" />
        </Link>
        <div className="bb-auth-editorial__copy">
          <p>{copy.title}</p>
          <span>{copy.description}</span>
          <ul className="bb-auth-editorial__points">
            {copy.points.map(([Icon, text]) => <li key={text}><Icon size={16} aria-hidden="true" />{text}</li>)}
          </ul>
        </div>
      </aside>

      <main id="auth-main" tabIndex={-1} className="bb-auth-main">
        <div className="bb-auth-main__inner">
          <div className="bb-auth-main__top">
            <Link to="/" className="bb-auth-back"><ArrowLeft size={16} /> Trang chủ</Link>
            <Link to="/" className="bb-auth-mobile-brand" aria-label={`BeautyBook — về trang chủ`}><BrandLogo size={34} /></Link>
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
