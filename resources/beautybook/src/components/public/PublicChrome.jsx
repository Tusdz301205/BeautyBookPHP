import { useEffect, useRef, useState } from 'react';
import { ArrowRight, Menu, Sparkles, X } from 'lucide-react';
import { Link, useLocation } from 'react-router-dom';
import { useAuthStore } from '../../store/authStore';

const discoveryLinks = [
  { label: 'Khám phá', to: '/explore' },
  { label: 'Dịch vụ', to: '/#services' },
  { label: 'Cơ sở', to: '/#salons' },
  { label: 'Cách đặt', to: '/#how-it-works' },
];

function portalPath(sessionType) {
  if (sessionType === 'admin') return '/admin';
  if (sessionType === 'salon') return '/salon';
  return '/customer/appointments';
}

export function PublicHeader() {
  const location = useLocation();
  const menuButtonRef = useRef(null);
  const closeButtonRef = useRef(null);
  const drawerRef = useRef(null);
  const [open, setOpen] = useState(false);
  const user = useAuthStore((state) => state.user);
  const isAuthenticated = useAuthStore((state) => state.isAuthenticated());

  useEffect(() => {
    setOpen(false);
  }, [location.pathname, location.search, location.hash]);

  useEffect(() => {
    if (!open) return undefined;
    const previousOverflow = document.body.style.overflow;
    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        setOpen(false);
        menuButtonRef.current?.focus();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...(drawerRef.current?.querySelectorAll('a[href], button:not([disabled])') || [])]
        .filter((element) => element.getClientRects().length);
      if (!focusable.length) return;
      if (event.shiftKey && document.activeElement === focusable[0]) {
        event.preventDefault();
        focusable[focusable.length - 1].focus();
      } else if (!event.shiftKey && document.activeElement === focusable[focusable.length - 1]) {
        event.preventDefault();
        focusable[0].focus();
      }
    };
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKeyDown);
    closeButtonRef.current?.focus();
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const accountLink = isAuthenticated ? portalPath(user?.sessionType) : '/login';
  const accountLabel = isAuthenticated ? 'Vào hệ thống' : 'Đăng nhập';

  return (
    <>
      <a className="bb-home-skip" href="#main-content">Bỏ qua điều hướng</a>
      <header className="bb-public-header">
        <div className="bb-public-utility">
          <div className="bb-public-utility__inner">
            <span>Marketplace đặt lịch làm đẹp</span>
            <nav aria-label="Liên kết tài khoản">
              <Link to="/for-business">Dành cho cơ sở</Link>
              <Link to={accountLink}>{accountLabel}</Link>
            </nav>
          </div>
        </div>
        <div className="bb-public-header__inner">
          <Link to="/" className="bb-public-brand" aria-label="BeautyBook — trang chủ">
            <span className="bb-public-brand__mark" aria-hidden="true"><Sparkles size={18} /></span>
            <span className="bb-public-brand__copy">
              <strong>BeautyBook</strong>
              <small>Beauty, thoughtfully booked</small>
            </span>
          </Link>

          <nav className="bb-public-nav" aria-label="Điều hướng chính">
            {discoveryLinks.map((item) => <Link key={item.to} to={item.to}>{item.label}</Link>)}
          </nav>

          <div className="bb-public-actions">
            <Link className="bb-public-account-link" to={accountLink}>{accountLabel}</Link>
            <Link className="bb-home-button bb-home-button--primary bb-home-button--small" to="/book">
              Đặt lịch <ArrowRight size={15} aria-hidden="true" />
            </Link>
          </div>

          <button
            ref={menuButtonRef}
            type="button"
            className="bb-public-menu-button"
            aria-label="Mở menu"
            aria-expanded={open}
            aria-controls="public-mobile-menu"
            onClick={() => setOpen(true)}
          >
            <Menu size={21} aria-hidden="true" />
          </button>
        </div>
      </header>

      {open ? (
        <div className="bb-public-drawer" role="presentation" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setOpen(false);
        }}>
          <nav ref={drawerRef} id="public-mobile-menu" className="bb-public-drawer__panel" aria-label="Điều hướng di động">
            <div className="bb-public-drawer__top">
              <span className="bb-public-drawer__label">Khám phá BeautyBook</span>
              <button ref={closeButtonRef} type="button" aria-label="Đóng menu" onClick={() => setOpen(false)}>
                <X size={22} aria-hidden="true" />
              </button>
            </div>
            <div className="bb-public-drawer__links">
              {discoveryLinks.map((item, index) => (
                <Link key={item.to} to={item.to}><span>0{index + 1}</span>{item.label}</Link>
              ))}
            </div>
            <div className="bb-public-drawer__footer">
              <Link to="/for-business">Dành cho cơ sở</Link>
              <Link to={accountLink}>{accountLabel}</Link>
              <Link className="bb-home-button bb-home-button--primary" to="/book">Bắt đầu đặt lịch <ArrowRight size={16} /></Link>
            </div>
          </nav>
        </div>
      ) : null}
    </>
  );
}

export function PublicFooter() {
  return (
    <footer className="bb-public-footer">
      <div className="bb-public-footer__mast">
        <p className="bb-home-kicker">BeautyBook</p>
        <p className="bb-public-footer__statement">Chọn nơi bạn tin tưởng. Đặt thời gian phù hợp. Dành phần còn lại cho chính mình.</p>
      </div>
      <div className="bb-public-footer__lower">
        <div>
          <strong>BeautyBook</strong>
          <p>Nền tảng tìm kiếm và đặt lịch dịch vụ làm đẹp.</p>
        </div>
        <nav aria-label="Điều hướng cuối trang">
          <Link to="/explore">Khám phá</Link>
          <Link to="/#services">Dịch vụ</Link>
          <Link to="/#how-it-works">Cách đặt lịch</Link>
          <Link to="/for-business">Dành cho cơ sở</Link>
          <Link to="/login">Đăng nhập</Link>
        </nav>
      </div>
    </footer>
  );
}
