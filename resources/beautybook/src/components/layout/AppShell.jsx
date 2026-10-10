import React, { useEffect, useMemo, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { BarChart3, Bell, Building2, CalendarCheck, ChevronRight, ClipboardCheck, CreditCard, FileClock, Gift, LayoutDashboard, LogOut, Menu, ShieldCheck, Sparkles, Star, Store, Tag, UserCog, Users, X } from 'lucide-react';
import { useAuthStore } from '../../store/authStore';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';
import { businessApi } from '../../api/apiClient';
import { IconButton, cx } from '../ui';
import { useUnreadNotifications } from '../../hooks/useUnreadNotifications';

const zoneMeta = {
  platform: { base: '/admin', eyebrow: 'BeautyBook Platform', title: 'Trung tâm vận hành' },
  salon: { base: '/salon', eyebrow: 'BeautyBook Business', title: 'Không gian cơ sở' },
  customer: { base: '/customer', eyebrow: 'BeautyBook', title: 'Tài khoản của bạn' },
};

const navigation = {
  customer: [
    ['appointments', 'Lịch hẹn của tôi', CalendarCheck],
    ['profile', 'Hồ sơ cá nhân', UserCog],
    ['security', 'Bảo mật', ShieldCheck],
  ],
  salon: [
    ['', 'Tổng quan', LayoutDashboard, ['report:overview:tenant']],
    ['appointments', 'Lịch hẹn', CalendarCheck, ['booking:read:tenant', 'booking:read:branch']],
    ['services', 'Dịch vụ', Sparkles, ['business_service:update:tenant', 'branch_service_offering:status:tenant']],
    ['combos', 'Combo dịch vụ', Gift, ['combo:manage:tenant']],
    ['staff', 'Đội ngũ', UserCog, ['user:read:tenant', 'user:read:branch']],
    ['audit', 'Nhật ký thao tác', FileClock, ['audit:read:tenant']],
    ['payments', 'Thanh toán', CreditCard, ['payment:read:branch', 'payment:read:tenant']],
    ['promotions', 'Khuyến mãi', Tag, ['promotion:manage:tenant']],
    ['reviews', 'Đánh giá', Star, ['review:moderate:tenant']],
    ['stats', 'Báo cáo', BarChart3, ['report:revenue:tenant', 'report:revenue:branch']],
    ['notifications', 'Thông báo', Bell, ['notification:read:self']],
    ['profile', 'Hồ sơ cơ sở', Store, ['branch:update:tenant']],
    ['onboarding', 'Đăng ký doanh nghiệp', ClipboardCheck, ['business:create:self', 'business:update:tenant']],
    ['account', 'Tài khoản', UserCog],
    ['incoming-ownership', 'Lời mời chuyển chủ', Building2],
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
    ['ownership', 'Chuyển quyền sở hữu', Building2, ['business:review:platform']],
    ['notifications', 'Thông báo', Bell, ['notification:read:self']],
  ],
};

const roleLabels = { PLATFORM_ADMIN: 'Quản trị hệ thống', BUSINESS_OWNER: 'Chủ doanh nghiệp', RECEPTIONIST: 'Lễ tân', STAFF: 'Nhân viên', CUSTOMER: 'Khách hàng' };

const roleNavigation = {
  STAFF: new Set(['appointments', 'notifications', 'account', 'security', 'incoming-ownership']),
  RECEPTIONIST: new Set(['appointments', 'operations', 'staff', 'payments', 'notifications', 'account', 'security', 'incoming-ownership']),
};

const roleSpaceTitles = {
  BUSINESS_OWNER: 'Không gian doanh nghiệp',
  RECEPTIONIST: 'Quầy lễ tân',
  STAFF: 'Không gian chuyên viên',
  PLATFORM_ADMIN: 'Trung tâm vận hành nền tảng',
};

const ownerLabels = { '': 'Tổng quan doanh nghiệp', services: 'Dịch vụ toàn doanh nghiệp', staff: 'Nhân sự toàn doanh nghiệp', stats: 'Báo cáo doanh nghiệp' };

function resolveMainRole(user, zone) {
  const codes = new Set([...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)]);
  const order = zone === 'platform'
    ? ['PLATFORM_ADMIN']
    : ['BUSINESS_OWNER', 'RECEPTIONIST', 'STAFF', 'CUSTOMER'];
  return order.find((role) => codes.has(role));
}

export function AppShell({ zone, children }) {
  const unread = useUnreadNotifications();
  const [mobileOpen, setMobileOpen] = useState(false);
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
    if (path === 'payments' && !FINANCE_WORKSPACE_ENABLED) return false;
    if (ownerRestricted && !['onboarding', 'notifications', 'account', 'security', 'incoming-ownership'].includes(path)) return false;
    if (businessApproved && path === 'onboarding') return false;
    if (permissions && !permissions.some((code) => can(code))) return false;
    return !!mainRole && (!roleNavigation[mainRole] || roleNavigation[mainRole].has(path));
  }), [businessApproved, can, mainRole, ownerRestricted, zone]);
  const navLabel = (path, label) => mainRole === 'BUSINESS_OWNER' ? ownerLabels[path] || label : mainRole === 'STAFF' && path === 'appointments' ? 'Lịch hẹn của tôi' : label;
  const initials = (user?.fullName || user?.email || 'BB').split(/\s+/).filter(Boolean).slice(-2).map((part) => part[0]).join('').toUpperCase();

  useEffect(() => { if (!mobileOpen) return undefined; const close = (event) => event.key === 'Escape' && setMobileOpen(false); document.addEventListener('keydown', close); document.body.style.overflow = 'hidden'; return () => { document.removeEventListener('keydown', close); document.body.style.overflow = ''; }; }, [mobileOpen]);
  const signOut = async () => {
    await logout();
    navigate('/login', { replace: true, state: { workspace: zone === 'admin' ? 'PLATFORM' : 'SALON' } });
  };

  return <div className={`bb-app-shell bb-app-shell--${zone} min-h-screen bg-[var(--bb-canvas)] text-[var(--bb-ink)] lg:grid lg:grid-cols-[272px_minmax(0,1fr)]`}>
    <a href="#main-content" className="bb-sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[var(--z-toast)] focus:rounded-lg focus:bg-white focus:px-4 focus:py-3 focus:shadow-lg">Bỏ qua điều hướng</a>
    {mobileOpen && <button className="fixed inset-0 z-[var(--z-sticky)] bg-[var(--color-overlay)] lg:hidden" onClick={() => setMobileOpen(false)} aria-label="Đóng menu" />}
    <aside className={cx('bb-app-sidebar fixed inset-y-0 left-0 z-[var(--z-modal)] flex w-[min(86vw,304px)] flex-col border-r border-white/10 bg-zinc-950 text-white transition-transform duration-200 lg:sticky lg:top-0 lg:h-screen lg:w-auto lg:translate-x-0', mobileOpen ? 'translate-x-0' : '-translate-x-full')} aria-label="Điều hướng chính">
      <div className="flex min-h-20 items-center gap-3 border-b border-white/10 px-5"><span className="grid h-10 w-10 place-items-center rounded-xl bg-[var(--bb-brand)]"><Sparkles size={19} /></span><div className="min-w-0"><p className="bb-display truncate text-lg font-bold">BeautyBook</p><p className="truncate text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">{meta.eyebrow}</p></div><IconButton label="Đóng menu" className="ml-auto text-zinc-300 hover:bg-white/10 hover:text-white lg:hidden" onClick={() => setMobileOpen(false)}><X size={19} /></IconButton></div>
      <nav className="bb-scrollbar flex-1 space-y-1 overflow-y-auto p-3">{items.map(([path, label, Icon]) => <NavLink key={path || 'home'} end={!path} to={path ? `${meta.base}/${path}` : meta.base} onClick={() => setMobileOpen(false)} className={({ isActive }) => cx('group flex min-h-11 items-center gap-3 rounded-lg px-3 text-sm font-medium text-zinc-400 transition-colors hover:bg-white/10 hover:text-white', isActive && 'bg-white/10 text-white')}><Icon size={18} aria-hidden="true" /><span className="min-w-0 flex-1 break-words py-2 leading-snug">{navLabel(path, label)}</span><ChevronRight size={14} className="shrink-0 opacity-0 transition-opacity group-hover:opacity-70" /></NavLink>)}</nav>
      <div className="border-t border-white/10 p-3"><button onClick={signOut} className="flex min-h-11 w-full items-center gap-3 rounded-lg px-3 text-sm font-medium text-zinc-400 transition-colors hover:bg-white/10 hover:text-white"><LogOut size={18} />Đăng xuất</button></div>
    </aside>
    <div className="min-w-0"><header className="sticky top-0 z-30 flex min-h-16 items-center gap-3 border-b border-[var(--bb-border)] bg-white/95 px-3 backdrop-blur sm:px-5 lg:px-8"><IconButton label="Mở menu" className="lg:hidden" onClick={() => setMobileOpen(true)}><Menu size={21} /></IconButton><div className="min-w-0 flex-1"><p className="break-words text-sm font-bold leading-snug sm:text-base"><span className="sm:hidden">{zone === 'platform' ? 'Quản trị BeautyBook' : meta.title}</span><span className="hidden sm:inline">{meta.title}</span></p><p className="hidden truncate text-xs text-[var(--bb-muted)] sm:block">{roleLabels[mainRole] || mainRole || 'Tài khoản BeautyBook'}</p></div>{zone !== 'customer' && <span className="relative"><IconButton label={unread ? `Thông báo: ${unread} chưa đọc` : "Mở thông báo"} onClick={() => navigate(`${meta.base}/notifications`)}><Bell size={19} /></IconButton>{unread > 0 && <span aria-hidden="true" className="pointer-events-none absolute right-1 top-1 h-2.5 w-2.5 rounded-full bg-red-600 ring-2 ring-white" />}</span>}<button onClick={() => navigate(`${meta.base}/${zone === 'salon' ? 'account' : 'profile'}`)} className="flex min-h-11 items-center gap-2 rounded-lg px-1.5 text-left hover:bg-[var(--bb-surface-subtle)]"><span className="grid h-9 w-9 place-items-center rounded-full bg-[var(--bb-brand-soft)] text-xs font-bold text-[var(--bb-brand-strong)]">{initials}</span><span className="hidden max-w-44 sm:block"><span className="block truncate text-xs font-bold">{user?.fullName || 'Chưa cập nhật'}</span><span className="block truncate text-[11px] text-[var(--bb-muted)]">{user?.email}</span></span></button></header><main id="main-content" tabIndex="-1" className="min-h-[calc(100vh-4rem)] p-4 sm:p-6 lg:p-8">{children}</main></div>
  </div>;
}
