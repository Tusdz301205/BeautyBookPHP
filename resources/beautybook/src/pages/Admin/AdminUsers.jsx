import React, { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { BriefcaseBusiness, CheckCircle2, ChevronLeft, ChevronRight, Eye, LockKeyhole, RefreshCw, Search, UserRound, UsersRound } from 'lucide-react';
import { format } from 'date-fns';
import toast from 'react-hot-toast';
import { usersApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Button, Dialog, Drawer, Field, Select, Textarea } from '../../components/ui';

const roleLabels = {
  PLATFORM_ADMIN: 'Quản trị nền tảng',
  BUSINESS_OWNER: 'Chủ doanh nghiệp', RECEPTIONIST: 'Lễ tân', STAFF: 'Nhân viên', CUSTOMER: 'Khách hàng',
};

const roleOptions = [
  ['', 'Tất cả vai trò'], ['CUSTOMER', 'Khách hàng'], ['BUSINESS_OWNER', 'Chủ doanh nghiệp'], ['RECEPTIONIST', 'Lễ tân'], ['STAFF', 'Nhân viên'], ['PLATFORM_ADMIN', 'Quản trị nền tảng'],
];

export function AdminUsers() {
  const navigate = useNavigate();
  const can = useAuthStore((state) => state.can);
  const currentUser = useAuthStore((state) => state.user);
  const [users, setUsers] = useState([]);
  const [meta, setMeta] = useState({ page: 1, totalPages: 1, total: 0, summary: {} });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [role, setRole] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [actionTarget, setActionTarget] = useState(null);

  useEffect(() => {
    const timer = window.setTimeout(() => { setDebouncedSearch(search.trim()); setPage(1); }, 300);
    return () => window.clearTimeout(timer);
  }, [search]);

  const fetchUsers = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const response = await usersApi.getAll({ search: debouncedSearch, role, status, page, limit: 20 });
      setUsers(Array.isArray(response) ? response : response?.data || []);
      setMeta(response?.meta || { page: 1, totalPages: 1, total: 0, summary: {} });
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải danh sách người dùng.');
    } finally { setLoading(false); }
  }, [debouncedSearch, page, role, status]);

  useEffect(() => { fetchUsers(); }, [fetchUsers]);

  const openUser = async (user) => {
    navigate(`/admin/users/${user.id}`);
    return;
    setSelected(user); setDetailLoading(true);
    try { setSelected(await usersApi.getById(user.id)); }
    catch (requestError) { toast.error(requestError.message || 'Không thể tải chi tiết người dùng'); }
    finally { setDetailLoading(false); }
  };

  const toggleSuspension = async (reason) => {
    if (!actionTarget) return;
    try {
      await usersApi.suspend(actionTarget.id, { reason, isActive: !actionTarget.isActive, expectedIsActive: actionTarget.isActive, acknowledgeImpact: false });
      toast.success(actionTarget.isActive ? 'Đã khóa tài khoản' : 'Đã mở khóa tài khoản');
      setActionTarget(null); setSelected(null); fetchUsers();
    } catch (requestError) { toast.error(requestError.message || 'Không thể cập nhật tài khoản'); }
  };

  const summary = meta.summary || {};
  return (
    <div className="min-h-full bg-[#f7f6f3] p-4 sm:p-6 lg:p-8">
      <div className="mx-auto max-w-[1500px]">
        <header className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"><div><p className="text-xs font-bold uppercase tracking-[0.16em] text-pink-700">Identity & access</p><h1 className="mt-1 text-2xl font-bold tracking-tight text-zinc-950 sm:text-3xl">Người dùng</h1><p className="mt-1 text-sm text-zinc-500">Quản lý tài khoản, vai trò, trạng thái truy cập và phạm vi hoạt động trên toàn nền tảng.</p></div><button type="button" onClick={fetchUsers} className="inline-flex min-h-11 w-fit items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"><RefreshCw size={16} className={loading ? 'animate-spin' : ''} /> Làm mới</button></header>

        <section className="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-5" aria-label="Tổng quan người dùng">
          <Metric icon={UsersRound} label="Tổng người dùng" value={summary.total ?? '—'} tone="zinc" />
          <Metric icon={CheckCircle2} label="Đang hoạt động" value={summary.active ?? '—'} tone="green" />
          <Metric icon={LockKeyhole} label="Đang bị khóa" value={summary.suspended ?? '—'} tone="red" />
          <Metric icon={UserRound} label="Khách hàng" value={summary.customers ?? '—'} tone="pink" />
          <Metric icon={BriefcaseBusiness} label="Thành viên doanh nghiệp" value={summary.teamMembers ?? '—'} tone="blue" />
        </section>

        <section className="mt-5 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
          <div className="border-b border-zinc-200 p-4 sm:p-5">
            <div className="flex flex-col gap-3 xl:flex-row xl:items-center">
              <label className="relative min-w-0 flex-1"><span className="sr-only">Tìm người dùng</span><Search size={17} className="absolute left-3 top-1/2 -translate-y-1/2 text-zinc-400" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tìm theo tên, email hoặc số điện thoại..." className="min-h-11 w-full rounded-xl border border-zinc-200 pl-10 pr-3 text-sm" /></label>
              <Select value={role} onChange={(event) => { setRole(event.target.value); setPage(1); }} className="xl:w-60" aria-label="Lọc vai trò" searchable>{roleOptions.map(([value, label]) => <option key={value || 'all'} value={value}>{label}</option>)}</Select>
              <div className="flex rounded-xl bg-zinc-100 p-1" role="group" aria-label="Lọc trạng thái">{[['', 'Tất cả'], ['ACTIVE', 'Hoạt động'], ['SUSPENDED', 'Bị khóa']].map(([value, label]) => <button key={value || 'all'} type="button" aria-pressed={status === value} onClick={() => { setStatus(value); setPage(1); }} className={`min-h-9 rounded-lg px-3 text-sm font-semibold ${status === value ? 'bg-white text-zinc-950 shadow-sm' : 'text-zinc-600'}`}>{label}</button>)}</div>
            </div>
            <p className="mt-3 text-xs font-medium text-zinc-500">{meta.total || 0} kết quả phù hợp</p>
          </div>

          {loading ? <TableSkeleton /> : error ? <ErrorState message={error} onRetry={fetchUsers} /> : users.length === 0 ? <EmptyState /> : (
            <div className="overflow-x-auto"><table className="w-full min-w-[980px] text-sm"><thead className="bg-zinc-50 text-left text-xs uppercase tracking-wide text-zinc-500"><tr><th className="px-5 py-3 font-bold">Người dùng</th><th className="px-4 py-3 font-bold">Liên hệ</th><th className="px-4 py-3 font-bold">Vai trò</th><th className="px-4 py-3 font-bold">Lịch hẹn</th><th className="px-4 py-3 font-bold">Ngày tham gia</th><th className="px-4 py-3 font-bold">Trạng thái</th><th className="px-5 py-3 text-right font-bold">Thao tác</th></tr></thead><tbody className="divide-y divide-zinc-100">{users.map((user) => <tr key={user.id} className="hover:bg-zinc-50/80"><td className="px-5 py-4"><div className="flex items-center gap-3"><Avatar name={user.name} /><div className="min-w-0"><p className="truncate font-bold text-zinc-950">{user.name || 'Chưa cập nhật'}</p><p className="truncate text-xs text-zinc-500">ID: {user.id.slice(0, 8)}</p></div></div></td><td className="px-4 py-4"><p className="text-zinc-800">{user.email}</p><p className="mt-0.5 text-xs text-zinc-500">{user.phone || 'Chưa có SĐT'}</p></td><td className="max-w-[280px] px-4 py-4"><RoleChips roles={user.roles} /></td><td className="px-4 py-4 font-bold tabular-nums text-zinc-800">{user.bookings || 0}</td><td className="px-4 py-4 text-zinc-600">{formatDate(user.joined)}</td><td className="px-4 py-4"><AccountStatus active={user.isActive} /></td><td className="px-5 py-4 text-right"><button type="button" onClick={() => openUser(user)} className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-zinc-200 px-3 text-xs font-bold text-zinc-700 hover:bg-zinc-100"><Eye size={14} /> Chi tiết</button></td></tr>)}</tbody></table></div>
          )}

          {!loading && !error && meta.totalPages > 1 && <footer className="flex flex-col gap-3 border-t border-zinc-200 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"><p className="text-sm text-zinc-500">Trang <strong className="text-zinc-800">{meta.page}</strong> / {meta.totalPages} · {meta.total.toLocaleString('vi-VN')} người dùng</p><div className="flex gap-2"><button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="inline-flex min-h-10 items-center gap-1 rounded-lg border border-zinc-200 px-3 text-sm font-bold disabled:opacity-40"><ChevronLeft size={16} /> Trước</button><button type="button" disabled={page >= meta.totalPages} onClick={() => setPage((value) => value + 1)} className="inline-flex min-h-10 items-center gap-1 rounded-lg border border-zinc-200 px-3 text-sm font-bold disabled:opacity-40">Sau <ChevronRight size={16} /></button></div></footer>}
        </section>
      </div>

      <UserDrawer user={selected} loading={detailLoading} canSuspend={can('user:suspend:platform') && selected?.id !== currentUser?.id} onSuspend={() => setActionTarget({ id: selected.id, name: selected.fullName || selected.name, isActive: selected.isActive })} onClose={() => setSelected(null)} />
      <SuspendDialog user={actionTarget} onCancel={() => setActionTarget(null)} onConfirm={toggleSuspension} />
    </div>
  );
}

function UserDrawer({ user, loading, canSuspend, onSuspend, onClose }) {
  if (!user) return null;
  const roles = user.userRoles?.map((assignment) => assignment.role?.code).filter(Boolean) || user.roles || [];
  return <Drawer open onClose={onClose} title={user.fullName || user.name || 'Chưa cập nhật'} description="Hồ sơ người dùng" footer={canSuspend ? <Button className="w-full" variant={user.isActive ? 'danger' : 'primary'} onClick={onSuspend}>{user.isActive ? 'Khóa tài khoản' : 'Mở khóa tài khoản'}</Button> : null}>{loading ? <div className="animate-pulse space-y-3">{Array.from({ length: 5 }, (_, i) => <div key={i} className="h-14 rounded-xl bg-zinc-100" />)}</div> : <div className="space-y-5"><div className="flex items-center gap-3"><Avatar name={user.fullName || user.name} large /><div className="flex flex-wrap items-center gap-2"><AccountStatus active={user.isActive} /><RoleChips roles={roles} /></div></div><div className="grid gap-3 sm:grid-cols-2"><Info label="Email" value={user.email || '—'} /><Info label="Số điện thoại" value={user.phone || '—'} /><Info label="Ngày tham gia" value={formatDate(user.createdAt || user.joined)} /><Info label="Đăng nhập gần nhất" value={formatDateTime(user.lastLoginAt)} /><Info label="Xác minh email" value={user.isEmailVerified ? 'Đã xác minh' : 'Chưa xác minh'} /><Info label="Giới tính" value={user.gender || 'Chưa cập nhật'} /></div>{user.userRoles?.length > 0 && <section><h3 className="text-sm font-bold text-zinc-950">Phạm vi vai trò</h3><div className="mt-2 divide-y divide-zinc-100 rounded-xl border border-zinc-200">{user.userRoles.map((assignment) => <div key={assignment.id} className="p-3 text-sm"><p className="font-bold text-zinc-900">{roleLabels[assignment.role?.code] || assignment.role?.code}</p><p className="mt-0.5 text-xs text-zinc-500">{assignment.business?.name || 'Toàn nền tảng'}{assignment.branch?.name ? ` · ${assignment.branch.name}` : ''}</p></div>)}</div></section>}</div>}</Drawer>;
}

function SuspendDialog({ user, onCancel, onConfirm }) {
  const [reason, setReason] = useState('');
  useEffect(() => { if (!user) setReason(''); }, [user]);
  return <Dialog open={Boolean(user)} onClose={onCancel} title={user?.isActive ? 'Khóa tài khoản' : 'Mở khóa tài khoản'} description={user ? `Người dùng: ${user.name}. Mọi thay đổi đều được ghi vào nhật ký kiểm toán.` : ''} footer={<><Button variant="secondary" onClick={onCancel}>Hủy</Button><Button variant="danger" disabled={!reason.trim()} onClick={() => onConfirm(reason.trim())}>Xác nhận</Button></>}><Field label="Lý do" required><Textarea autoFocus value={reason} onChange={(event) => setReason(event.target.value)} placeholder="Nhập lý do nghiệp vụ..." /></Field></Dialog>;
}

function Metric({ icon: Icon, label, value, tone }) { const colors = { zinc: 'bg-zinc-100 text-zinc-700', green: 'bg-emerald-100 text-emerald-700', red: 'bg-red-100 text-red-700', pink: 'bg-pink-100 text-pink-700', blue: 'bg-blue-100 text-blue-700' }; return <div className="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm"><span className={`grid h-9 w-9 place-items-center rounded-xl ${colors[tone]}`}><Icon size={18} /></span><p className="mt-3 text-2xl font-bold tabular-nums text-zinc-950">{typeof value === 'number' ? value.toLocaleString('vi-VN') : value}</p><p className="text-xs font-semibold text-zinc-500">{label}</p></div>; }
function Avatar({ name = '', large = false }) { const initials = name.split(' ').filter(Boolean).slice(-2).map((part) => part[0]).join('').toUpperCase() || 'U'; return <span className={`grid shrink-0 place-items-center rounded-full bg-pink-100 font-bold text-pink-700 ${large ? 'h-12 w-12 text-base' : 'h-9 w-9 text-xs'}`}>{initials}</span>; }
function RoleChips({ roles = [] }) { if (!roles.length) return <span className="text-xs text-zinc-400">Chưa gán vai trò</span>; return <div className="flex flex-wrap gap-1">{roles.slice(0, 2).map((role) => <span key={role} className="rounded-md bg-blue-50 px-2 py-1 text-[11px] font-bold text-blue-700">{roleLabels[role] || role}</span>)}{roles.length > 2 && <span className="rounded-md bg-zinc-100 px-2 py-1 text-[11px] font-bold text-zinc-600">+{roles.length - 2}</span>}</div>; }
function AccountStatus({ active }) { return <span className={`inline-flex rounded-full border px-2.5 py-1 text-xs font-bold ${active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-red-200 bg-red-50 text-red-700'}`}>{active ? 'Hoạt động' : 'Bị khóa'}</span>; }
function Info({ label, value }) { return <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-4"><p className="text-xs font-semibold text-zinc-500">{label}</p><p className="mt-1 break-words text-sm font-bold text-zinc-900">{value}</p></div>; }
function formatDate(value) { if (!value) return '—'; const date = new Date(value); return Number.isNaN(date.getTime()) ? '—' : format(date, 'dd/MM/yyyy'); }
function formatDateTime(value) { if (!value) return 'Chưa có'; const date = new Date(value); return Number.isNaN(date.getTime()) ? 'Chưa có' : format(date, 'dd/MM/yyyy · HH:mm'); }
function TableSkeleton() { return <div className="animate-pulse space-y-3 p-5">{Array.from({ length: 8 }, (_, index) => <div key={index} className="h-12 rounded-xl bg-zinc-100" />)}</div>; }
function ErrorState({ message, onRetry }) { return <div role="alert" className="p-12 text-center"><p className="font-bold text-zinc-900">Không thể tải danh sách người dùng</p><p className="mt-1 text-sm text-zinc-500">{message}</p><button type="button" onClick={onRetry} className="mt-4 min-h-10 rounded-lg border border-zinc-200 px-4 text-sm font-bold">Thử lại</button></div>; }
function EmptyState() { return <div className="p-12 text-center"><UsersRound className="mx-auto text-zinc-400" /><p className="mt-3 font-bold text-zinc-900">Không tìm thấy người dùng phù hợp</p><p className="mt-1 text-sm text-zinc-500">Hãy thay đổi từ khóa hoặc bộ lọc.</p></div>; }

export default AdminUsers;
