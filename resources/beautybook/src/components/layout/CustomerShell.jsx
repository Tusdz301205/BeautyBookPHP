import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useUnreadNotifications } from '../../hooks/useUnreadNotifications';
import {
  Bell,
  CalendarCheck,
  ChevronDown,
  ChevronRight,
  Compass,
  Heart,
  KeyRound,
  LogOut,
  Sparkles,
  Star,
  Store,
  TicketPercent,
  UserRound,
  X,
} from 'lucide-react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../../store/authStore';
import { PublicFooter } from '../public/PublicChrome';
import { cx, IconButton } from '../ui';

const primaryLinks = [
  ['/explore', 'Khám phá', Compass],
  ['/customer/appointments', 'Lịch hẹn', CalendarCheck],
];

const accountGroups = [
  {
    label: 'Tài khoản',
    items: [
      ['/customer/profile', 'Hồ sơ cá nhân', UserRound],
      ['/customer/reviews', 'Đánh giá của tôi', Star],
    ],
  },
  {
    label: 'Ưu đãi',
    items: [
      ['/customer/vouchers', 'Voucher', TicketPercent],
      ['/customer/benefits', 'Quyền lợi & dịch vụ đã lưu', Heart],
    ],
  },
  {
    label: 'Hệ thống',
    items: [
      ['/customer/security', 'Bảo mật & phiên đăng nhập', KeyRound],
    ],
  },
];

const accountRoutes = accountGroups.flatMap((group) => group.items.map(([to]) => to));

function AccountNavigation({ onNavigate }) {
  return (
    <nav className="bb-customer-account__groups" aria-label="Chức năng tài khoản">
      {accountGroups.map((group) => (
        <section className="bb-customer-account__group" key={group.label}>
          <h2>{group.label}</h2>
          <div>
            {group.items.map(([to, label, Icon]) => (
              <NavLink
                key={to}
                to={to}
                onClick={onNavigate}
                className={({ isActive }) => cx('bb-customer-account__item', isActive && 'bb-customer-account__item--active')}
              >
                <Icon size={18} aria-hidden="true" />
                <span>{label}</span>
                <ChevronRight size={15} aria-hidden="true" />
              </NavLink>
            ))}
          </div>
        </section>
      ))}
    </nav>
  );
}

export function CustomerShell({ children }) {
  const [accountOpen, setAccountOpen] = useState(false);
  const unread = useUnreadNotifications();
  const accountPanelRef = useRef(null);
  const accountTriggerRef = useRef(null);
  const location = useLocation();
  const navigate = useNavigate();
  const user = useAuthStore((state) => state.user);
  const logout = useAuthStore((state) => state.logout);

  useEffect(() => {
    setAccountOpen(false);
  }, [location.pathname, location.search]);


  useEffect(() => {
    if (!accountOpen) return undefined;
    const mobile = window.matchMedia('(max-width: 47.99rem)').matches;
    const previousOverflow = document.body.style.overflow;
    if (mobile) document.body.style.overflow = 'hidden';
    window.requestAnimationFrame(() => accountPanelRef.current?.querySelector('.bb-customer-account__item')?.focus());

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
  const closeAccount = () => setAccountOpen(false);
  const signOut = async () => {
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="bb-customer-shell min-h-screen bg-[var(--bb-canvas)] text-[var(--bb-ink)]">
      <a href="#main-content" className="bb-sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[var(--z-toast)] focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:shadow-lg">
        Bỏ qua điều hướng
      </a>
      <header className="bb-customer-header sticky top-0 z-[var(--z-sticky)] border-b border-[var(--bb-border)] bg-[color-mix(in_oklch,var(--bb-surface)_94%,transparent)] backdrop-blur">
        <div className="mx-auto flex min-h-16 max-w-[var(--maxw-page)] items-center gap-3 px-4 sm:px-6 lg:px-8">
          <Link to="/" className="flex min-h-11 shrink-0 items-center gap-2" aria-label="BeautyBook — trang chủ">
            <span className="grid h-9 w-9 place-items-center rounded-[var(--bb-radius-control)] bg-[var(--bb-brand)] text-[var(--color-accent-ink)]" aria-hidden="true"><Sparkles size={18} /></span>
            <span className="bb-display text-lg font-bold tracking-tight">BeautyBook</span>
          </Link>

          <nav className="ml-3 hidden flex-1 items-center gap-1 md:flex" aria-label="Điều hướng khách hàng">
            {primaryLinks.map(([to, label, Icon]) => (
              <NavLink key={to} to={to} className={({ isActive }) => cx('flex min-h-11 items-center gap-2 whitespace-nowrap rounded-[var(--bb-radius-control)] px-3 text-sm font-semibold transition-colors', isActive ? 'bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]' : 'text-[var(--bb-ink-soft)] hover:bg-[var(--bb-surface-subtle)] hover:text-[var(--bb-ink)]')}>
                <Icon size={16} aria-hidden="true" />{label}
              </NavLink>
            ))}
          </nav>

          <div className="ml-auto flex min-w-0 items-center gap-1.5">
            <Link to="/customer/notifications" className="relative grid h-11 w-11 shrink-0 place-items-center rounded-[var(--bb-radius-control)] text-[var(--bb-ink-soft)] hover:bg-[var(--bb-surface-subtle)]" aria-label={unread ? `${unread} thông báo chưa đọc` : 'Thông báo'}>
              <Bell size={19} aria-hidden="true" />
              {unread > 0 && <span className="absolute right-1.5 top-1.5 grid min-h-4 min-w-4 place-items-center rounded-full bg-[var(--bb-brand)] px-1 text-[10px] font-bold leading-none text-[var(--color-accent-ink)]">{unread > 99 ? '99+' : unread}</span>}
            </Link>
            <button
              ref={accountTriggerRef}
              type="button"
              className={cx('bb-customer-account-trigger flex min-h-11 min-w-0 items-center gap-2 rounded-[var(--bb-radius-control)] px-1.5 text-left', accountActive && 'bb-customer-account-trigger--active')}
              aria-haspopup="dialog"
              aria-label={`Mở menu tài khoản ${user?.fullName || 'Khách hàng'}`}
              aria-expanded={accountOpen}
              aria-controls="customer-account-panel"
              onClick={() => setAccountOpen((value) => !value)}
            >
              <span className="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-[var(--bb-brand-soft)] text-xs font-bold text-[var(--bb-brand-strong)]">{initials}</span>
              <span className="hidden min-w-0 max-w-40 lg:block"><span className="block truncate text-xs font-bold">{user?.fullName || 'Tài khoản'}</span><span className="block truncate text-[11px] text-[var(--bb-muted)]">Khách hàng</span></span>
              <ChevronDown className={cx('hidden shrink-0 sm:block', accountOpen && 'rotate-180')} size={15} aria-hidden="true" />
            </button>
          </div>
        </div>
      </header>

      {accountOpen && (
        <div className="bb-customer-account-layer" role="presentation">
          <button type="button" className="bb-customer-account__backdrop" aria-label="Đóng menu tài khoản" onClick={closeAccount} />
          <aside ref={accountPanelRef} id="customer-account-panel" className="bb-customer-account" role="dialog" aria-labelledby="customer-account-title">
            <header className="bb-customer-account__header">
              <span className="bb-customer-account__avatar" aria-hidden="true">{initials}</span>
              <div>
                <h2 id="customer-account-title">{user?.fullName || 'Tài khoản BeautyBook'}</h2>
                <p>{user?.email || 'Khách hàng'}</p>
                <span>Khách hàng</span>
              </div>
              <IconButton label="Đóng menu tài khoản" onClick={closeAccount}><X size={19} /></IconButton>
            </header>

            <div className="bb-customer-account__scroll bb-scrollbar">
              <AccountNavigation onNavigate={closeAccount} />
              <div className="bb-customer-account__bottom">
                <Link to="/for-business" onClick={closeAccount} className="bb-customer-account__business">
                  <Store size={18} aria-hidden="true" />
                  <span><strong>Dành cho doanh nghiệp</strong><small>Tìm hiểu BeautyBook Business</small></span>
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
      <PublicFooter />

      <nav className="fixed inset-x-0 bottom-0 z-[var(--z-sticky)] grid grid-cols-4 border-t border-[var(--bb-border)] bg-[color-mix(in_oklch,var(--bb-surface)_96%,transparent)] pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden" aria-label="Điều hướng nhanh">
        {primaryLinks.map(([to, label, Icon]) => (
          <NavLink key={to} to={to} className={({ isActive }) => cx('relative flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[10px] font-semibold', isActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')}>
            <Icon size={19} aria-hidden="true" /><span className="max-w-full truncate">{label}</span>
          </NavLink>
        ))}
        <NavLink to="/customer/notifications" className={({ isActive }) => cx('relative flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[10px] font-semibold', isActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')}>
          <Bell size={19} aria-hidden="true" /><span>Thông báo</span>{unread > 0 ? <span className="absolute right-[25%] top-2 h-2 w-2 rounded-full bg-[var(--bb-brand)]" aria-hidden="true" /> : null}
        </NavLink>
        <button type="button" onClick={() => setAccountOpen(true)} className={cx('relative flex min-h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[10px] font-semibold', accountActive ? 'text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)]')} aria-label="Mở menu tài khoản" aria-haspopup="dialog" aria-expanded={accountOpen} aria-controls="customer-account-panel">
          <UserRound size={19} aria-hidden="true" /><span>Tài khoản</span>
        </button>
      </nav>
    </div>
  );
}
