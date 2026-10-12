import React, { useEffect, useState } from 'react';
import {
  AlertTriangle,
  Bell,
  CalendarCheck,
  CheckCheck,
  ChevronLeft,
  ChevronRight,
  Clock3,
  CreditCard,
  Gift,
  Search,
  Settings,
  Star,
  XCircle,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { useNavigate } from 'react-router-dom';
import { notificationsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { FINANCE_WORKSPACE_ENABLED } from '../../config/featureAvailability';
import {
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Input,
  Page,
  PageHeader,
  Select,
  Skeleton,
} from '../ui';

const TYPES = {
  BOOKING_CONFIRMED: ['Lịch đã xác nhận', CalendarCheck, 'success'],
  BOOKING_CANCELLED: ['Lịch đã hủy', XCircle, 'danger'],
  BOOKING_REMINDER: ['Nhắc lịch', Clock3, 'warning'],
  PROMOTION: ['Ưu đãi', Gift, 'brand'],
  PAYMENT: ['Thanh toán', CreditCard, 'info'],
  BOOKING_RESCHEDULE_REQUEST: ['Yêu cầu đổi lịch', CalendarCheck, 'warning'],
  BOOKING_RESCHEDULE_APPROVED: ['Đổi lịch được duyệt', CalendarCheck, 'success'],
  BOOKING_RESCHEDULE_REJECTED: ['Đổi lịch bị từ chối', XCircle, 'danger'],
  BOOKING_PAYMENT_RECEIVED: ['Đã nhận thanh toán', CreditCard, 'success'],
  BOOKING_COMPLETED: ['Dịch vụ hoàn thành', CalendarCheck, 'success'],
  REVIEW_REMINDER: ['Nhắc đánh giá', Star, 'warning'],
  SALON_VIOLATION_ALERT: ['Cảnh báo cơ sở', AlertTriangle, 'danger'],
  SYSTEM: ['Hệ thống', Settings, 'neutral'],
};

const SEVERITIES = {
  INFO: ['Thông tin', 'info'],
  SUCCESS: ['Thành công', 'success'],
  WARNING: ['Cần lưu ý', 'warning'],
  CRITICAL: ['Khẩn cấp', 'danger'],
};

const notificationRoutes = {
  salon: [
    [/^\/salon$/, []],
    [/^\/salon\/services$/, ['business_service:update:tenant', 'branch_service_offering:status:tenant']],
    [/^\/salon\/combos$/, ['combo:manage:tenant']],
    [/^\/salon\/appointments$/, ['booking:read:tenant', 'booking:read:branch']],
    [/^\/salon\/staff$/, ['user:read:tenant', 'user:read:branch']],
    [/^\/salon\/staff\/schedule$/, ['booking:read:tenant', 'booking:read:branch']],
    [/^\/salon\/staff\/[^/]+$/, ['user:read:tenant', 'user:read:branch', 'user:read:self']],
    [/^\/salon\/branches\/new$/, ['branch:create:tenant']],
    [/^\/salon\/branches\/[^/]+\/setup$/, ['branch:update:tenant', 'branch:create:tenant']],
    [/^\/salon\/branches\/[^/]+\/preview$/, ['branch:read:tenant']],
    [/^\/salon\/profile$/, ['branch:update:tenant']],
    [/^\/salon\/promotions$/, ['promotion:manage:tenant']],
    [/^\/salon\/reviews$/, ['review:moderate:tenant']],
    [/^\/salon\/stats$/, ['report:revenue:tenant', 'report:revenue:branch']],
    [/^\/salon\/(security|account|incoming-ownership)$/, []],
    [/^\/salon\/notifications$/, ['notification:read:self']],
    [/^\/salon\/onboarding$/, ['business:create:self', 'business:update:tenant']],
    [/^\/salon\/payments$/, ['payment:read:tenant', 'payment:read:branch']],
    [/^\/salon\/operations$/, ['booking:read:tenant', 'booking:read:branch', 'payment:read:tenant', 'payment:read:branch']],
    [/^\/salon\/audit$/, ['audit:read:tenant']],
  ],
  platform: [
    [/^\/admin$/, []],
    [/^\/admin\/(salons|branches\/[^/]+|businesses\/[^/]+|violations|compliance)$/, ['branch:read:platform']],
    [/^\/admin\/users(?:\/[^/]+)?$/, ['user:read:platform']],
    [/^\/admin\/appointments$/, ['booking:read:platform']],
    [/^\/admin\/reports$/, ['report:overview:platform', 'report:revenue:platform', 'report:user_growth:platform']],
    [/^\/admin\/reviews$/, ['review:moderate:platform']],
    [/^\/admin\/ownership$/, ['business:review:platform']],
    [/^\/admin\/(security|profile)$/, []],
    [/^\/admin\/notifications$/, ['notification:read:self']],
    [/^\/admin\/payments$/, ['payment:read:platform']],
    [/^\/admin\/audit$/, ['audit:read:platform']],
    [/^\/admin\/settings$/, ['platform_setting:manage:platform']],
  ],
  customer: [
    // App.jsx protects this zone by customer session; these routes add no
    // PermissionGate. Do not invent action permissions for viewing a page.
    [/^\/customer\/appointments(?:\/[^/]+)?$/, []],
    [/^\/customer\/(profile|security|vouchers|reviews|benefits|notifications)$/, []],
    [/^\/book(?:\/(staff|time|info|confirm|success))?$/, []],
    [/^\/explore(?:\/(branches|services|staff)\/[^/]+)?$/, []],
  ],
};

function allowedNotificationUrl(url, zone, can, item = {}, financeEnabled = false, isOwner = false) {
  if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//') || /[\\\s]/.test(url)) return '';
  let pathname;
  try {
    pathname = decodeURIComponent(url.split(/[?#]/)[0]);
    if (new URL(url, 'https://notification.invalid').pathname !== url.split(/[?#]/)[0]
      || pathname.split('/').some(part => part === '.' || part === '..') || /[\\%]/.test(pathname)) return '';
  } catch { return ''; }
  if (!financeEnabled && /\/(payments)(\/|$)/.test(pathname)) return '';
  // These destinations remain retired even if a producer retains an old URL.
  if (/\/(privacy|data-requests)(\/|$)/.test(pathname)) return '';
  if ((isOwner && /^\/salon\/(audit|stats|incoming-ownership)(\/|$)/.test(pathname))
    || /^\/admin\/ownership(\/|$)/.test(pathname)) return '';
  const route = notificationRoutes[zone]?.find(([pattern]) => pattern.test(pathname));
  if (!route) return '';
  const booking = item.relatedBooking;
  const ctx = {
    tenantId: booking?.businessId || booking?.branch?.businessId || item.businessId || item.tenantId,
    branchId: booking?.branchId || item.branchId,
    ownerUserId: booking?.customerId || item.ownerUserId,
  };
  return route[1].length === 0 || route[1].some(code => can(code, ctx)) ? url : '';
}

const timeAgo = (value) => {
  if (!value) return '—';
  const seconds = Math.floor((Date.now() - new Date(value).getTime()) / 1000);
  if (seconds < 60) return 'Vừa xong';
  if (seconds < 3600) return `${Math.floor(seconds / 60)} phút trước`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} giờ trước`;
  if (seconds < 604800) return `${Math.floor(seconds / 86400)} ngày trước`;
  return new Date(value).toLocaleDateString('vi-VN');
};

export function NotificationCenter({ platform = false, zone = platform ? 'platform' : 'salon' }) {
  const navigate = useNavigate();
  const can = useAuthStore(store => store.can);
  const user = useAuthStore(store => store.user);
  const isOwner = [...(user?.roles || []), ...(user?.scopes || []).map(scope => scope.code)].includes('BUSINESS_OWNER');
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const [state, setState] = useState('all');
  const [type, setType] = useState('ALL');
  const [severity, setSeverity] = useState('ALL');
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState(null);
  const [page, setPage] = useState(1);
  const [pagination, setPagination] = useState({ page: 1, total: 0, totalPages: 1 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const limit = 20;

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const [result, count] = await Promise.all([
        notificationsApi.getAll({ page, limit, state, type, severity, search }),
        notificationsApi.getUnreadCount(),
      ]);
      setItems(result.data ?? result ?? []);
      setPagination(result.pagination ?? { page, total: result.data?.length ?? 0, totalPages: 1 });
      setUnread(Number(count?.count ?? count ?? 0));
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    void load();
  }, [page, state, type, severity, search]);

  const mark = async (item) => {
    if (item.isRead || item.readAt) return;
    try {
      await notificationsApi.markAsRead(item.id);
      const readAt = new Date().toISOString();
      setItems((list) => list.map((row) => (row.id === item.id ? { ...row, isRead: true, readAt } : row)));
      setSelected((row) => (row?.id === item.id ? { ...row, isRead: true, readAt } : row));
      setUnread((count) => Math.max(0, count - 1));
    } catch (requestError) {
      toast.error(requestError.message);
    }
  };

  const candidateUrl = (item) => {
    const prefixes = {
      customer: ['/customer/', '/book', '/explore'],
      salon: ['/salon/'],
      platform: ['/admin/'],
    };
    if (
      typeof item.actionUrl === 'string'
      && item.actionUrl.startsWith('/')
      && !item.actionUrl.startsWith('//')
      && (prefixes[zone] ?? []).some((prefix) => item.actionUrl.startsWith(prefix))
    ) return item.actionUrl;
    const bookingId = item.relatedBooking?.id || item.relatedBookingId || (item.targetType === 'BOOKING' ? item.targetId : null);
    if (bookingId) {
      if (zone === 'customer') return `/customer/appointments/${encodeURIComponent(bookingId)}`;
      if (zone === 'platform') return `/admin/appointments?bookingId=${encodeURIComponent(bookingId)}`;
      return `/salon/appointments?bookingId=${encodeURIComponent(bookingId)}`;
    }
    const target = String(item.targetType || '').toUpperCase();
    if (zone === 'platform' && item.targetId) {
      const id = encodeURIComponent(item.targetId);
      if (target === 'BUSINESS' || target === 'COMPLIANCE_APPLICATION' || target === 'BUSINESS_APPLICATION') return `/admin/businesses/${id}?tab=compliance`;
      if (target === 'BRANCH') return `/admin/branches/${id}?tab=compliance`;
      if (target === 'USER') return `/admin/users/${id}`;
    }
    const routes = {
      customer: {
        PAYMENT: '/customer/appointments', REFUND: '/customer/appointments', VOUCHER: '/customer/vouchers',
        PROMOTION: '/explore', REVIEW: '/customer/reviews', PRIVACY: '/customer/profile',
        SECURITY: '/customer/security',
      },
      salon: {
        PAYMENT: FINANCE_WORKSPACE_ENABLED ? '/salon/payments' : '', REFUND: FINANCE_WORKSPACE_ENABLED ? '/salon/payments' : '', PROMOTION: '/salon/promotions',
        VOUCHER: '/salon/promotions', REVIEW: '/salon/reviews',
        STAFF: '/salon/staff', BRANCH: '/salon/profile', BUSINESS: '/salon/profile', SECURITY: '/salon/security',
      },
      platform: {
        PAYMENT: FINANCE_WORKSPACE_ENABLED ? '/admin/payments' : '', REFUND: FINANCE_WORKSPACE_ENABLED ? '/admin/payments' : '', PROMOTION: '/admin/salons',
        VOUCHER: '/admin/salons', REVIEW: '/admin/reviews', BRANCH: '/admin/salons',
        BUSINESS: '/admin/salons', USER: '/admin/users', VIOLATION: '/admin/salons',
        AUDIT: '/admin/audit', SECURITY: '/admin/security',
      },
    };
    return routes[zone]?.[target] || '';
  };

  const open = (item) => {
    setSelected(item);
    void mark(item);
  };

  const markAll = async () => {
    try {
      await notificationsApi.markAllAsRead();
      setItems((list) => list.map((item) => ({ ...item, isRead: true, readAt: item.readAt || new Date().toISOString() })));
      setUnread(0);
      toast.success('Đã đánh dấu tất cả là đã đọc');
    } catch (requestError) {
      toast.error(requestError.message);
    }
  };

  const submitSearch = (event) => {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  };
  const contextUrl = item => allowedNotificationUrl(candidateUrl(item), zone, can, item, FINANCE_WORKSPACE_ENABLED, isOwner);
  const relatedUrl = selected ? contextUrl(selected) : '';
  const contextName = zone === 'customer' ? 'Tài khoản của tôi' : zone === 'platform' ? 'Nền tảng' : 'Cơ sở';

  return (
    <Page className="max-w-5xl">
      <PageHeader
        eyebrow={contextName}
        title="Thông báo"
        description={unread ? `${unread} thông báo chưa đọc. Chọn một mục để xem chi tiết trước khi chuyển đến nội dung liên quan.` : 'Bạn đã đọc tất cả thông báo.'}
        actions={unread > 0 && <Button variant="secondary" size="sm" onClick={markAll}><CheckCheck size={15} />Đọc tất cả</Button>}
      />

      <Card className="grid gap-3 p-3 md:grid-cols-[minmax(12rem,1fr)_11rem_11rem_11rem]">
        <form className="relative" onSubmit={submitSearch} role="search">
          <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--bb-muted)]" size={17} aria-hidden="true" />
          <Input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} className="pl-10" placeholder="Tìm tiêu đề hoặc nội dung" aria-label="Tìm thông báo" />
        </form>
        <Select aria-label="Lọc trạng thái" value={state} onChange={(event) => { setState(event.target.value); setPage(1); }}>
          <option value="all">Tất cả trạng thái</option>
          <option value="unread">Chưa đọc</option>
          <option value="read">Đã đọc</option>
        </Select>
        <Select aria-label="Lọc loại thông báo" value={type} onChange={(event) => { setType(event.target.value); setPage(1); }}>
          <option value="ALL">Tất cả loại</option>
          {Object.entries(TYPES).map(([key, [label]]) => <option key={key} value={key}>{label}</option>)}
        </Select>
        <Select aria-label="Lọc mức độ" value={severity} onChange={(event) => { setSeverity(event.target.value); setPage(1); }}>
          <option value="ALL">Tất cả mức độ</option>
          {Object.entries(SEVERITIES).map(([key, [label]]) => <option key={key} value={key}>{label}</option>)}
        </Select>
      </Card>

      {loading ? (
        <Skeleton rows={7} />
      ) : error ? (
        <Card><ErrorState message={error} onRetry={load} /></Card>
      ) : !items.length ? (
        <Card><EmptyState icon={Bell} title="Không có thông báo" description="Không có mục nào phù hợp với bộ lọc hiện tại." /></Card>
      ) : (
        <Card className="divide-y divide-[var(--bb-border)] overflow-hidden">
          {items.map((item) => {
            const [label, Icon, typeTone] = TYPES[item.type] || TYPES.SYSTEM;
            const [severityLabel, severityTone] = SEVERITIES[item.severity] || SEVERITIES.INFO;
            const isUnread = !item.isRead && !item.readAt;
            return (
              <button key={item.id} type="button" onClick={() => open(item)} className="flex min-h-24 w-full items-start gap-3 p-4 text-left transition-colors hover:bg-[var(--bb-surface-subtle)] sm:items-center">
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--bb-surface-subtle)] text-[var(--bb-brand-strong)]"><Icon size={18} aria-hidden="true" /></span>
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-2">
                    <b className="text-sm">{item.title || label}</b>
                    <Badge tone={typeTone}>{label}</Badge>
                    {item.severity && item.severity !== 'INFO' && <Badge tone={severityTone}>{severityLabel}</Badge>}
                    {isUnread && <span className="h-2 w-2 rounded-full bg-[var(--bb-brand)]" aria-label="Chưa đọc" />}
                  </span>
                  <span className="mt-1 line-clamp-2 block text-xs leading-5 text-[var(--bb-muted)]">{item.message || item.body || 'Thông báo không có nội dung bổ sung.'}</span>
                </span>
                <span className="shrink-0 text-[11px] text-[var(--bb-muted)]">{timeAgo(item.createdAt)}</span>
              </button>
            );
          })}
        </Card>
      )}

      {!loading && !error && pagination.total > 0 && (
        <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
          <p className="text-xs text-[var(--bb-muted)]">Hiển thị {items.length} / {pagination.total} thông báo · Trang {pagination.page} / {Math.max(1, pagination.totalPages)}</p>
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}><ChevronLeft size={15} />Trang trước</Button>
            <Button variant="secondary" size="sm" disabled={page >= pagination.totalPages} onClick={() => setPage((value) => value + 1)}>Trang sau<ChevronRight size={15} /></Button>
          </div>
        </div>
      )}

      <Dialog
        open={Boolean(selected)}
        onClose={() => setSelected(null)}
        title={selected?.title || 'Chi tiết thông báo'}
        description={selected?.createdAt ? new Date(selected.createdAt).toLocaleString('vi-VN') : ''}
        footer={<><Button variant="secondary" onClick={() => setSelected(null)}>Đóng</Button>{relatedUrl && <Button onClick={() => { setSelected(null); const url = contextUrl(selected); if (url) navigate(url); }}>Xem nội dung liên quan</Button>}</>}
      >
        <div className="flex flex-wrap gap-2">
          {selected && <Badge tone={(TYPES[selected.type] || TYPES.SYSTEM)[2]}>{(TYPES[selected.type] || TYPES.SYSTEM)[0]}</Badge>}
          {selected?.severity && <Badge tone={(SEVERITIES[selected.severity] || SEVERITIES.INFO)[1]}>{(SEVERITIES[selected.severity] || SEVERITIES.INFO)[0]}</Badge>}
        </div>
        <p className="mt-4 whitespace-pre-wrap text-sm leading-6 text-[var(--bb-ink-soft)]">{selected?.body || selected?.message || 'Thông báo này không có nội dung bổ sung.'}</p>
        {selected?.relatedBooking?.bookingCode && <p className="mt-4 rounded-lg bg-[var(--bb-surface-subtle)] px-3 py-2 text-sm font-semibold">Mã lịch: {selected.relatedBooking.bookingCode}</p>}
      </Dialog>
    </Page>
  );
}
