import React, { useEffect, useMemo, useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { BarChart3, Bell, CalendarCheck, ChevronRight, ClipboardCheck, CreditCard, FileClock, Gift, LayoutDashboard, LogOut, Menu, MessageCircle, ShieldCheck, Sparkles, Star, Store, Tag, UserCog, Users, X } from 'lucide-react';
import { useAuthStore } from '../../store/authStore';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';
import { businessApi } from '../../api/apiClient';
import { BrandLogo } from '../brand/BrandLogo';
import { IconButton, cx } from '../ui';
import { useUnreadNotifications } from '../../hooks/useUnreadNotifications';
import { useBrowserPushReceiver } from '../notifications/BrowserPush';

const zoneMeta = {
  platform: { base: '/admin', eyebrow: 'BeautyBook Platform', title: 'Trung tâm vận hành' },
  salon: { base: '/salon', eyebrow: 'BeautyBook Business', title: 'Không gian cơ sở' },
  customer: { base: '/customer', eyebrow: 'BeautyBook', title: 'Tài khoản của bạn' },
};

const navigation = {
  customer: [
    ['appointments', 'Lịch hẹn của tôi', CalendarCheck],
    ['messages', 'Tin nhắn', MessageCircle],
    ['profile', 'Hồ sơ cá nhân', UserCog],
    ['security', 'Bảo mật', ShieldCheck],
  ],
  salon: [
    ['', 'Tổng quan', LayoutDashboard, ['report:overview:tenant']],
    ['appointments', 'Lịch hẹn', CalendarCheck, ['booking:read:tenant', 'booking:read:branch']],
    ['messages', 'Tin nhắn', MessageCircle, ['booking:read:tenant', 'booking:read:branch']],
    ['services', 'Dịch vụ', Sparkles, ['business_service:update:tenant', 'branch_service_offering:status:tenant']],
    ['combos', 'Combo dịch vụ', Gift, ['combo:manage:tenant']],
    ['staff', 'Đội ngũ', UserCog, ['user:read:tenant', 'user:read:branch']],
    ['audit', 'Nhật ký thao tác', FileClock, ['audit:read:tenant']],
    ['payments', 'Ví & thanh toán', CreditCard, ['payment:read:branch', 'payment:read:tenant']],
    ['promotions', 'Khuyến mãi', Tag, ['promotion:manage:tenant']],
    ['reviews', 'Đánh giá', Star, ['review:moderate:tenant']],
    ['stats', 'Báo cáo', BarChart3, ['report:revenue:tenant', 'report:revenue:branch']],
    ['notifications', 'Thông báo', Bell, ['notification:read:self']],
    ['profile', 'Hồ sơ cơ sở', Store, ['branch:update:tenant']],
    ['onboarding', 'Đăng ký doanh nghiệp', ClipboardCheck, ['business:create:self', 'business:update:tenant']],
    ['account', 'Tài khoản', UserCog],
    ['security', 'Bảo mật', ShieldCheck],
  ],
  platform: [
    ['', 'Tổng quan', LayoutDashboard, ['report:overview:platform']],
    ['salons', 'Doanh nghiệp & chi nhánh', Store, ['branch:read:platform']],
    ['users', 'Người dùng', Users, ['user:read:platform']],
    ['appointments', 'Lịch hẹn', CalendarCheck, ['booking:read:platform']],
    ['payments', 'Tài chính & hoàn tiền', CreditCard, ['payment:read:platform']],
    ['reports', 'Báo cáo', BarChart3, ['report:overview:platform', 'report:revenue:platform', 'report:user_growth:platform']],
    ['reviews', 'Kiểm duyệt đánh giá', Star, ['review:moderate:platform']],
    ['notifications', 'Thông báo', Bell, ['notification:read:self']],
  ],
};

const roleLabels = { PLATFORM_ADMIN: 'Quản trị hệ thống', BUSINESS_OWNER: 'Chủ doanh nghiệp', RECEPTIONIST: 'Lễ tân', STAFF: 'Nhân viên', CUSTOMER: 'Khách hàng' };

const roleNavigation = {
  STAFF: new Set(['appointments', 'notifications', 'account', 'security']),
  RECEPTIONIST: new Set(['appointments', 'messages', 'operations', 'staff', 'payments', 'notifications', 'account', 'security']),
};

const ownerHiddenNavigation = new Set(['audit', 'stats']);

const roleSpaceTitles = {
  BUSINESS_OWNER: 'Không gian doanh nghiệp',
  RECEPTIONIST: 'Quầy lễ tân',
  STAFF: 'Không gian chuyên viên',
  PLATFORM_ADMIN: 'Trung tâm vận hành nền tảng',
};

const ownerLabels = { '': 'Tổng quan', services: 'Dịch vụ', staff: 'Nhân sự', stats: 'Báo cáo' };

const menuGroups = [
  { label: 'Vận hành', paths: ['', 'appointments', 'messages', 'notifications'] },
  { label: 'Dịch vụ & khách hàng', paths: ['services', 'combos', 'staff', 'promotions', 'reviews', 'salons', 'users'] },
  { label: 'Quản lý', paths: ['payments', 'reports', 'stats', 'audit', 'operations'] },
  { label: 'Tài khoản & cơ sở', paths: ['profile', 'onboarding', 'account', 'security'] },
];

function resolveMainRole(user, zone) {
  const codes = new Set([...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)]);
  const order = zone === 'platform'
    ? ['PLATFORM_ADMIN']
    : ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF', 'CUSTOMER'];
  return order.find((role) => codes.has(role));
}

export function AppShell({ zone, children }) {
  const unread = useUnreadNotifications();
  useBrowserPushReceiver();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [desktop, setDesktop] = useState(() => window.matchMedia('(min-width: 64rem)').matches);
  const sidebarRef = useRef(null);
  const menuRef = useRef(null);
  const location = useLocation();
  const [ownerRestricted, setOwnerRestricted] = useState(false);
  const [businessApproved, setBusinessApproved] = useState(false);
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const can = useAuthStore((s) => s.can);
  const logout = useAuthStore((s) => s.logout);
  const baseMeta = zoneMeta[zone];
  const mainRole = resolveMainRole(user, zone);
  const meta = { ...baseMeta, title: roleSpaceTitles[mainRole] || baseMeta.title };
  useEffect(() => {
    let active = true;
    if (zone !== 'salon' || mainRole !== 'BUSINESS_OWNER') {
      setOwnerRestricted(false);
      setBusinessApproved(false);
      return () => { active = false; };
    }
    setOwnerRestricted(true);
    businessApi.getMyOnboarding()
      .then((business) => {
        if (active) {
          const approved = ['APPROVED', 'ACTIVE'].includes(business?.status);
          setOwnerRestricted(!approved);
          setBusinessApproved(approved);
        }
      })
      .catch(() => {
        if (active) {
          setOwnerRestricted(true);
          setBusinessApproved(false);
        }
      });
    return () => { active = false; };
  }, [mainRole, zone]);

  const items = useMemo(() => navigation[zone].filter(([path, , , permissions]) => {
    if (zone === 'salon' && mainRole === 'BUSINESS_OWNER' && ownerHiddenNavigation.has(path)) return false;
    if (path === 'payments' && !FINANCE_WORKSPACE_ENABLED) return false;
    if (ownerRestricted && !['onboarding', 'notifications', 'account', 'security'].includes(path)) return false;
    if (businessApproved && path === 'onboarding') return false;
    if (permissions && !permissions.some((code) => can(code))) return false;
    return !!mainRole && (!roleNavigation[mainRole] || roleNavigation[mainRole].has(path));
  }), [businessApproved, can, mainRole, ownerRestricted, zone]);
  const navLabel = (path, label) => mainRole === 'BUSINESS_OWNER' ? ownerLabels[path] || label : mainRole === 'STAFF' && path === 'appointments' ? 'Lịch hẹn của tôi' : label;
  const initials = (user?.fullName || user?.email || 'BB').split(/\s+/).filter(Boolean).slice(-2).map((part) => part[0]).join('').toUpperCase();

  useEffect(() => {
    const media = window.matchMedia('(min-width: 64rem)');
    const update = () => { setDesktop(media.matches); if (media.matches) setMobileOpen(false); };
    media.addEventListener('change', update);
    return () => media.removeEventListener('change', update);
  }, []);
  useEffect(() => { setMobileOpen(false); }, [location.pathname]);
  useEffect(() => {
    if (!mobileOpen || desktop) return undefined;
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const frame = requestAnimationFrame(() => sidebarRef.current?.querySelector('button')?.focus());
    const close = (event) => {
      if (event.key === 'Escape') { setMobileOpen(false); menuRef.current?.focus(); }
      if (event.key !== 'Tab') return;
      const controls = [...(sidebarRef.current?.querySelectorAll('a[href],button:not([disabled])') || [])].filter((item) => item.getClientRects().length);
      const first = controls[0], last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    };
    document.addEventListener('keydown', close);
    return () => { cancelAnimationFrame(frame); document.body.style.overflow = previousOverflow; document.removeEventListener('keydown', close); };
  }, [mobileOpen, desktop]);
  const signOut = async () => {
    const state = { workspace: zone === 'platform' ? 'PLATFORM' : 'SALON' };
    try { await logout(); navigate('/login', { replace: true, state }); }
    catch (error) { navigate('/login', { replace: true, state: { ...state, logoutError: error.message || 'Chưa thể xác nhận đăng xuất. Vui lòng thử lại.' } }); }
  };

  const currentItem = [...items].reverse().find(([path]) => path && location.pathname.startsWith(`${meta.base}/${path}`)) || items.find(([path]) => !path);
  const currentLabel = currentItem ? navLabel(currentItem[0], currentItem[1]) : meta.title;
  return <div className={`bb-app-shell bb-app-shell--${zone} min-h-screen bg-[var(--bb-canvas)] text-[var(--bb-ink)] lg:grid lg:grid-cols-[272px_minmax(0,1fr)]`}>
    <a href="#main-content" className="bb-sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[var(--z-toast)] focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:shadow-lg">Bỏ qua điều hướng</a>
    {mobileOpen && !desktop && <button className="fixed inset-0 z-[var(--z-sticky)] bg-[var(--color-overlay)] lg:hidden" onClick={() => { setMobileOpen(false); menuRef.current?.focus(); }} aria-label="Đóng menu" />}
    <aside ref={sidebarRef} className={cx('bb-app-sidebar bb-app-sidebar--light fixed inset-y-0 left-0 z-[var(--z-modal)] flex w-[min(86vw,304px)] flex-col border-r border-[var(--bb-border)] text-[var(--bb-ink)] transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-auto lg:translate-x-0', mobileOpen ? 'translate-x-0 visible' : '-translate-x-full invisible lg:visible')} aria-label="Điều hướng chính" role={mobileOpen && !desktop ? 'dialog' : undefined} aria-modal={mobileOpen && !desktop ? true : undefined} aria-hidden={!desktop && !mobileOpen ? true : undefined} inert={!desktop && !mobileOpen ? '' : undefined}>
      <div className="bb-app-sidebar__brand flex min-h-20 items-center gap-3 border-b border-[var(--bb-border)] px-5">
        <Link to={meta.base} aria-label={`${meta.eyebrow} — trang chính`}><BrandLogo size={40} subtitle={meta.eyebrow} /></Link>
        <IconButton label="Đóng menu" className="ml-auto text-[var(--bb-muted)] hover:bg-[var(--bb-surface-subtle)] lg:hidden" onClick={() => { setMobileOpen(false); menuRef.current?.focus(); }}><X size={19} /></IconButton>
      </div>
      <nav className="bb-scrollbar flex-1 space-y-5 overflow-y-auto p-3" aria-label="Chức năng không gian làm việc">
        {menuGroups.map((group) => {
          const visible = items.filter(([path]) => group.paths.includes(path));
          return visible.length ? <section key={group.label}>
            <h2 className="mb-2 px-3 pt-2 text-[10px] font-semibold uppercase tracking-wider text-[var(--bb-muted)]">{group.label}</h2>
            {visible.map(([path, label, Icon]) => <NavLink key={path || 'home'} end={!path} to={path ? `${meta.base}/${path}` : meta.base} onClick={() => setMobileOpen(false)} className={({ isActive }) => cx('bb-app-nav-link group flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium text-[var(--bb-ink-soft)] transition-colors hover:bg-[var(--bb-brand-soft)] hover:text-[var(--bb-brand-strong)]', isActive && 'bb-app-nav-link--active bg-[var(--bb-brand-soft)] font-semibold text-[var(--bb-brand-strong)]')}>
              <Icon size={18} aria-hidden="true" /><span className="min-w-0 flex-1 break-words py-2 leading-snug">{navLabel(path, label)}</span><ChevronRight size={14} aria-hidden="true" className="shrink-0 opacity-0 transition-opacity group-hover:opacity-70" />
            </NavLink>)}
          </section> : null;
        })}
      </nav>
      <div className="border-t border-[var(--bb-border)] p-3">
        <Link to="/explore" className="flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium text-[var(--bb-ink-soft)] hover:bg-[var(--bb-surface-subtle)]"><Store size={18} aria-hidden="true" />Xem trang khách hàng</Link>
        <button onClick={signOut} className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-[var(--bb-ink-soft)] transition-colors hover:bg-[var(--bb-surface-subtle)] hover:text-[var(--bb-danger)]"><LogOut size={18} aria-hidden="true" />Đăng xuất</button>
      </div>
    </aside>
    <div className="min-w-0">
      <header className="sticky top-0 z-30 flex min-h-16 items-center gap-3 border-b border-[var(--bb-border)] bg-white/95 px-3 backdrop-blur sm:px-5 lg:px-8">
        <IconButton ref={menuRef} label="Mở menu" className="lg:hidden" aria-expanded={mobileOpen} onClick={() => setMobileOpen(true)}><Menu size={21} /></IconButton>
        <div className="min-w-0 flex-1"><p className="break-words text-sm font-bold leading-snug sm:text-base">{currentLabel}</p><p className="hidden truncate text-xs text-[var(--bb-muted)] sm:block">{meta.title}</p></div>
        {zone !== 'customer' && <span className="relative"><IconButton label={unread ? `Thông báo: ${unread} chưa đọc` : 'Mở thông báo'} onClick={() => navigate(`${meta.base}/notifications`)}><Bell size={19} /></IconButton>{unread > 0 && <span aria-hidden="true" className="pointer-events-none absolute right-1 top-1 h-2.5 w-2.5 rounded-full bg-[var(--bb-brand)] ring-2 ring-white" />}</span>}
        <button onClick={() => navigate(`${meta.base}/${zone === 'salon' ? 'account' : 'profile'}`)} className="flex min-h-11 items-center gap-2 rounded-lg px-1.5 text-left hover:bg-[var(--bb-surface-subtle)]" aria-label="Mở tài khoản">
          <span className="grid h-9 w-9 place-items-center rounded-full bg-[var(--bb-brand-soft)] text-xs font-bold text-[var(--bb-brand-strong)]">{initials}</span><span className="hidden max-w-44 sm:block"><span className="block truncate text-xs font-bold">{user?.fullName || 'Tài khoản'}</span><span className="block truncate text-[11px] text-[var(--bb-muted)]">{roleLabels[mainRole] || 'BeautyBook'}</span></span>
        </button>
      </header>
      <main id="main-content" tabIndex="-1" className="min-h-[calc(100vh-4rem)] p-4 sm:p-6 lg:p-8">{children}</main>
    </div>
  </div>;
}
