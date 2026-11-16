import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { useUnreadNotifications } from '../../hooks/useUnreadNotifications';
import { useBrowserPushReceiver } from '../notifications/BrowserPush';
import {
  Bell,
  CalendarCheck,
  CalendarPlus,
  ChevronDown,
  ChevronRight,
  Compass,
  LogOut,
  Store,
  UserRound,
  X,
} from 'lucide-react';
import AccountMenuLinks, { accountGroups } from './AccountMenuLinks';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../store/authStore';
import { BrandLogo } from '../brand/BrandLogo';
import { PublicFooter } from '../public/PublicChrome';
import { cx, IconButton } from '../ui';

const primaryLinks = [
  ['/explore', 'Khám phá', Compass],
  ['/customer/appointments', 'Lịch hẹn', CalendarCheck],
];

const accountRoutes = accountGroups.flatMap((group) => group.items.map(([to]) => to));

function AccountNavigation({ onNavigate }) {
  return <AccountMenuLinks onNavigate={onNavigate} />;
}

export function CustomerShell({ children }) {
  const [accountOpen, setAccountOpen] = useState(false);
  const accountPanelRef = useRef(null);
  const accountTriggerRef = useRef(null);
  const location = useLocation();
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);
  const unread=useUnreadNotifications();
  useBrowserPushReceiver();

  useLayoutEffect(() => {
    if (!location.hash) window.scrollTo({top:0,left:0,behavior:'instant'});
  }, [location.pathname]);

  useEffect(() => {
    setAccountOpen(false);
  }, [location.pathname, location.search, location.hash]);

  useLayoutEffect(() => {
    if (!accountOpen) return undefined;
    const align = () => {
      const panel = accountPanelRef.current, trigger = accountTriggerRef.current;
      if (!panel || !trigger || window.innerWidth < 768) return;
      const rect = trigger.getBoundingClientRect();
      const viewportWidth = panel.parentElement.getBoundingClientRect().right;
      const right = Math.max(16, Math.min(viewportWidth - rect.right - 64, viewportWidth - panel.offsetWidth - 16));
      panel.style.setProperty('--gm-account-right', `${right}px`);
      panel.style.setProperty('--gm-account-top', `${rect.bottom + 10}px`);
    };
    align();
    const observer = new ResizeObserver(align);
    if (accountTriggerRef.current) observer.observe(accountTriggerRef.current);
    window.addEventListener('resize', align);
    window.addEventListener('scroll', align, {passive:true});
    return () => { observer.disconnect(); window.removeEventListener('resize', align); window.removeEventListener('scroll', align); };
  }, [accountOpen]);


  useEffect(() => {
    if (!accountOpen) return undefined;
    const mobile = window.matchMedia('(max-width: 47.99rem)').matches;
    const previousOverflow = document.body.style.overflow;
    if (mobile) document.body.style.overflow = 'hidden';
    window.requestAnimationFrame(() => accountPanelRef.current?.querySelector('.bb-customer-account__item')?.focus({preventScroll:true}));

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        setAccountOpen(false);
        window.requestAnimationFrame(() => accountTriggerRef.current?.focus());
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...(accountPanelRef.current?.querySelectorAll('a[href], button:not([disabled])') || [])]
        .filter((element) => element.getClientRects().length);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [accountOpen]);

  const initials = useMemo(() => (user?.fullName || user?.email || 'BB')
    .split(/\s+/)
    .filter(Boolean)
    .slice(-2)
    .map((part) => part[0])
    .join('')
    .toUpperCase(), [user?.email, user?.fullName]);
  const accountActive = accountOpen || accountRoutes.some((route) => location.pathname.startsWith(route));
  const closeAccount = () => {
    setAccountOpen(false);
    accountTriggerRef.current?.focus({preventScroll:true});
  };
  const signOut = async () => {
    try { await logout(); navigate('/login', { replace: true }); }
    catch (error) { navigate('/login', { replace: true, state: { logoutError: error.message || 'Chưa thể xác nhận đăng xuất. Vui lòng thử lại.' } }); }
  };

  return (
    <div className="bb-customer-shell min-h-screen bg-[var(--bb-canvas)] pb-[calc(4rem+1px+env(safe-area-inset-bottom))] text-[var(--bb-ink)] md:pb-0">
      <a href="#main-content" className="bb-sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[var(--z-toast)] focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:shadow-lg">
        Bỏ qua điều hướng
      </a>
      <header className="gm-site-header bb-customer-header bb-customer-header--clear sticky top-0 z-[var(--z-sticky)] border-b border-[var(--bb-border)] bg-[color-mix(in_oklch,var(--bb-surface)_94%,transparent)] backdrop-blur">
        <div className="gm-site-header__inner bb-customer-header__inner mx-auto flex min-h-16 max-w-[var(--maxw-page)] items-center gap-3 px-4 sm:px-6 lg:px-8">
          <Link to="/" className="gm-site-header__brand flex min-h-11 shrink-0 items-center" aria-label={`BeautyBook — trang chủ`}>
            <BrandLogo size={36} />
          </Link>

          <nav className="bb-customer-header__nav" aria-label="Điều hướng khách hàng">
            {primaryLinks.map(([to, label, Icon]) => <NavLink key={to} to={to}><Icon size={15} aria-hidden="true" />{label}</NavLink>)}
          </nav>
          <div className="bb-customer-header__actions ml-auto flex min-w-0 items-center gap-2">
            <Link to="/customer/notifications" onClick={event=>{if(location.pathname==='/customer/notifications')event.preventDefault();}} className="bb-customer-header__notifications relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl hover:bg-[var(--bb-surface-subtle)]" aria-label={unread?`Thông báo: ${unread} chưa đọc`:'Mở thông báo'}><Bell size={19} aria-hidden="true"/>{unread>0&&<span aria-hidden="true" className="bb-customer-header__unread">{unread>99?'99+':unread}</span>}</Link>
            <button
              ref={accountTriggerRef}
              type="button"
              className={cx('bb-customer-account-trigger flex min-w-0 items-center text-left', accountActive && 'bb-customer-account-trigger--active', accountOpen && 'bb-customer-account-trigger--open')}
              aria-haspopup="dialog"
              aria-label={`Mở menu tài khoản ${user?.fullName || 'Khách hàng'}`}
              aria-expanded={accountOpen}
              aria-controls="customer-account-panel"
              onClick={() => setAccountOpen((value) => !value)}
            >
              <span className="bb-customer-account-trigger__avatar" aria-hidden="true">{initials}</span>
              <span className="bb-customer-account-trigger__text hidden lg:grid"><strong>{user?.fullName || 'Tài khoản của tôi'}</strong><small>Tài khoản của bạn</small></span>
              <ChevronDown className={cx('hidden shrink-0 sm:block', accountOpen && 'rotate-180')} size={15} aria-hidden="true" />
            </button>
            <Link to="/book" className="bb-customer-cta"><CalendarPlus size={16} aria-hidden="true" />Đặt lịch mới</Link>
          </div>
        </div>
      </header>

      {accountOpen && (
        <div className="bb-customer-account-layer bb-customer-account-layer--refined" role="presentation">
          <button type="button" className="bb-customer-account__backdrop" aria-label="Đóng menu tài khoản" onClick={closeAccount} />
          <aside ref={accountPanelRef} id="customer-account-panel" className="bb-customer-account" role="dialog" aria-modal="true" aria-labelledby="customer-account-title">
            <header className="bb-customer-account__header">
              <span className="bb-customer-account__avatar" aria-hidden="true">{initials}</span>
              <div>
                <span className="bb-customer-account__eyebrow">KHÔNG GIAN CÁ NHÂN</span>
                <h2 id="customer-account-title">{user?.fullName || `Tài khoản BeautyBook`}</h2>
                <p title={user?.email}>{user?.email || 'Tài khoản khách hàng'}</p>
              </div>
              <IconButton label="Đóng menu tài khoản" onClick={closeAccount}><X size={19} /></IconButton>
            </header>

            <div className="bb-customer-account__scroll bb-scrollbar">
              <AccountNavigation onNavigate={closeAccount} />
              <div className="bb-customer-account__bottom gm-account-menu-footer">
                <Link to="/for-business" onClick={closeAccount} className="bb-customer-account__business">
                  <Store size={18} aria-hidden="true" />
                  <span><strong>Dành cho cơ sở</strong><small>Tìm hiểu {'BeautyBook Business'}</small></span>
                  <ChevronRight size={16} aria-hidden="true" />
                </Link>
                <button type="button" onClick={signOut} className="bb-customer-account__logout">
                  <LogOut size={18} aria-hidden="true" />
                  <span>Đăng xuất</span>
                </button>
              </div>
            </div>
          </aside>
        </div>
      )}

      <main id="main-content" tabIndex="-1" className="bb-customer-main mx-auto min-h-[60dvh] w-full max-w-[var(--maxw-page)] px-4 py-6 pb-24 sm:px-6 sm:py-8 lg:px-8 lg:pb-10">
        {children}
      </main>
      <PublicFooter compact />

      <nav className="bb-customer-bottom-nav fixed inset-x-0 bottom-0 z-[var(--z-sticky)] grid grid-cols-4 border-t border-[var(--bb-border)] bg-[color-mix(in_oklch,var(--bb-surface)_96%,transparent)] pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden" aria-label="Điều hướng nhanh">
        {primaryLinks.map(([to, label, Icon]) => (
          <NavLink key={to} to={to} className={({ isActive }) => cx('relative flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[10px] font-semibold', isActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')}>
            <Icon size={19} aria-hidden="true" /><span className="max-w-full truncate">{label}</span>
          </NavLink>
        ))}
        <NavLink to="/customer/notifications" className={({ isActive }) => cx('relative flex min-h-16 flex-col items-center justify-center gap-1 text-[10px] font-semibold', isActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')}><Bell size={19} aria-hidden="true" /><span>Thông báo</span>{unread > 0 && <span className="absolute right-[25%] top-2 h-2 w-2 rounded-full bg-[var(--bb-brand)]" aria-hidden="true" />}</NavLink>
        <button type="button" onClick={() => setAccountOpen(true)} className={cx('relative flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[10px] font-semibold', accountActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')} aria-label="Mở menu tài khoản" aria-haspopup="dialog" aria-expanded={accountOpen} aria-controls="customer-account-panel">
          <UserRound size={19} aria-hidden="true" /><span>Tài khoản</span>
        </button>
      </nav>
    </div>
  );
}
