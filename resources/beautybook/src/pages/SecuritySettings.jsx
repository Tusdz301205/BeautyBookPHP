import React, { useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { Eye, EyeOff, History, KeyRound, Laptop, LogOut, ShieldCheck } from 'lucide-react';
import { useNavigate } from 'react-router-dom';
import { authApi } from '../api/apiClient';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, InlineNotice, Input, Page, PageHeader, Skeleton } from '../components/ui';
import { useAuthStore } from '../store/authStore';

function PasswordInput({ visible, onToggle, ...props }) {
  return <div className="relative"><Input type={visible ? 'text' : 'password'} className="pr-12" {...props} /><button type="button" onClick={onToggle} className="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-[var(--bb-radius-control)] text-[var(--bb-muted)] hover:text-[var(--bb-ink)]" aria-label={visible ? 'Ẩn mật khẩu' : 'Hiện mật khẩu'}>{visible ? <EyeOff size={17} /> : <Eye size={17} />}</button></div>;
}

export default function SecuritySettings() {
  const navigate = useNavigate();
  const logout = useAuthStore((state) => state.logout);
  const user = useAuthStore((state) => state.user);
  const [sessions, setSessions] = useState([]);
  const [history, setHistory] = useState({ events: [], sessions: [] });
  const [visible, setVisible] = useState({ current: false, next: false, confirm: false });
  const [passwords, setPasswords] = useState({ currentPassword: '', newPassword: '', confirmPassword: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [revokeOthersOpen, setRevokeOthersOpen] = useState(false);

  const load = async () => {
    setLoading(true); setError('');
    try { const [sessionRows, securityHistory] = await Promise.all([authApi.getSessions(), authApi.getSecurityHistory()]); setSessions(sessionRows); setHistory(securityHistory); }
    catch (requestError) { setError(requestError.message); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const changePassword = async (event) => {
    event.preventDefault();
    if (passwords.newPassword !== passwords.confirmPassword) {
      toast.error('Mật khẩu xác nhận không khớp');
      return;
    }
    setBusy('password');
    try {
      const result = await authApi.changePassword(passwords.currentPassword, passwords.newPassword);
      setPasswords({ currentPassword: '', newPassword: '', confirmPassword: '' });
      toast.success('Đã đổi mật khẩu. Vui lòng đăng nhập lại.');
      if (result?.requiresLogin) {
        const workspace = user?.workspace || (user?.sessionType === 'admin' ? 'PLATFORM' : user?.sessionType === 'salon' ? 'SALON' : 'CUSTOMER');
        await logout();
        navigate('/login', { replace: true, state: { workspace } });
      }
      else await load();
    } catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };

  const revoke = async (id) => {
    setBusy(id);
    try { await authApi.revokeSession(id); toast.success('Đã thu hồi phiên đăng nhập'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };

  const revokeOthers = async () => {
    setBusy('others');
    try { const result = await authApi.revokeOtherSessions(); setRevokeOthersOpen(false); toast.success(`Đã thu hồi ${result.revokedCount || 0} phiên`); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };

  const mismatch = Boolean(passwords.confirmPassword && passwords.newPassword !== passwords.confirmPassword);
  const strengthChecks = [passwords.newPassword.length >= 8, /[A-Z]/.test(passwords.newPassword), /[a-z]/.test(passwords.newPassword), /\d/.test(passwords.newPassword)];
  const strength = strengthChecks.filter(Boolean).length;
  return <Page className="max-w-5xl">
    <PageHeader eyebrow="Tài khoản" title="Bảo mật & phiên đăng nhập" description="Đổi mật khẩu và kiểm soát các thiết bị đang có quyền truy cập tài khoản." />
    <div className="grid gap-6 lg:grid-cols-[minmax(0,420px)_minmax(0,1fr)]">
      <Card className="h-fit overflow-hidden">
        <div className="flex items-center gap-3 border-b border-[var(--bb-border)] p-5"><span className="grid h-10 w-10 place-items-center rounded-lg bg-pink-50 text-pink-700"><KeyRound size={19} /></span><div><h2 className="font-bold">Đổi mật khẩu</h2><p className="text-xs text-[var(--bb-muted)]">Thao tác sẽ thu hồi các phiên cũ theo chính sách hiện tại.</p></div></div>
        <form onSubmit={changePassword} className="space-y-4 p-5">
          <Field label="Mật khẩu hiện tại" required><PasswordInput visible={visible.current} onToggle={() => setVisible((value) => ({ ...value, current: !value.current }))} autoComplete="current-password" required value={passwords.currentPassword} onChange={(event) => setPasswords({ ...passwords, currentPassword: event.target.value })} /></Field>
          <Field label="Mật khẩu mới" required hint="Ít nhất 8 ký tự, có chữ hoa, chữ thường và số."><PasswordInput visible={visible.next} onToggle={() => setVisible((value) => ({ ...value, next: !value.next }))} autoComplete="new-password" minLength={8} required value={passwords.newPassword} onChange={(event) => setPasswords({ ...passwords, newPassword: event.target.value })} /></Field>
          <div aria-label={`Độ mạnh mật khẩu ${strength} trên 4`} className="grid grid-cols-4 gap-1">{[1,2,3,4].map((level) => <span key={level} className={`h-1.5 rounded-full ${strength >= level ? strength < 3 ? 'bg-amber-500' : 'bg-emerald-600' : 'bg-zinc-200'}`} />)}</div>
          <Field label="Xác nhận mật khẩu mới" required error={mismatch ? 'Mật khẩu xác nhận không khớp.' : ''}><PasswordInput visible={visible.confirm} onToggle={() => setVisible((value) => ({ ...value, confirm: !value.confirm }))} autoComplete="new-password" minLength={8} required value={passwords.confirmPassword} onChange={(event) => setPasswords({ ...passwords, confirmPassword: event.target.value })} /></Field>
          <Button type="submit" loading={busy === 'password'} disabled={mismatch || strength < 4} className="w-full">Cập nhật mật khẩu</Button>
        </form>
      </Card>
      <Card className="overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-[var(--bb-border)] p-5 sm:flex-row sm:items-center"><span className="grid h-10 w-10 place-items-center rounded-lg bg-blue-50 text-blue-700"><ShieldCheck size={19} /></span><div className="min-w-0 flex-1"><h2 className="font-bold">Phiên đang hoạt động</h2><p className="text-xs text-[var(--bb-muted)]">Thu hồi thiết bị bạn không còn sử dụng.</p></div><Button variant="secondary" size="sm" onClick={() => setRevokeOthersOpen(true)}><LogOut size={15} />Thu hồi phiên khác</Button></div>
        {loading ? <Skeleton className="p-5" rows={4} /> : error ? <ErrorState message={error} onRetry={load} /> : !sessions.length ? <EmptyState title="Không có phiên nào" description="Hệ thống chưa trả về phiên đăng nhập đang hoạt động." /> : <div className="divide-y divide-[var(--bb-border)]">{sessions.map((session) => <article key={session.id} className="flex flex-col gap-3 p-5 sm:flex-row sm:items-start"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--bb-surface-subtle)] text-[var(--bb-muted)]"><Laptop size={18} /></span><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-bold">{session.current ? 'Thiết bị hiện tại' : 'Thiết bị khác'}</h3>{session.current && <Badge tone="success">Đang dùng</Badge>}</div><p className="mt-1 break-words text-xs text-[var(--bb-muted)]">{session.userAgent || 'Không xác định thiết bị'} · {session.ipAddress || 'Không xác định IP'}</p><p className="mt-1 text-xs text-[var(--bb-muted)]">Hoạt động gần nhất: {session.lastActiveAt ? new Date(session.lastActiveAt).toLocaleString('vi-VN') : '—'}</p></div><Button variant="secondary" size="sm" loading={busy === session.id} disabled={session.current} onClick={() => revoke(session.id)}>Thu hồi</Button></article>)}</div>}
      </Card>
    </div>
    <Card className="overflow-hidden"><div className="flex items-center gap-3 border-b border-[var(--bb-border)] p-5"><span className="grid h-10 w-10 place-items-center rounded-lg bg-[var(--bb-surface-subtle)] text-[var(--bb-muted)]"><History size={18} /></span><div><h2 className="font-bold">Lịch sử bảo mật</h2><p className="text-xs text-[var(--bb-muted)]">50 sự kiện gần nhất liên quan đến đăng nhập, mật khẩu và hồ sơ.</p></div></div>{!history.events?.length ? <EmptyState title="Chưa có sự kiện bảo mật" description="Các lần đăng nhập và thay đổi bảo mật mới sẽ xuất hiện tại đây." /> : <div className="divide-y divide-[var(--bb-border)]">{history.events.slice(0, 12).map((event) => <article key={event.id} className="flex flex-col gap-1 p-4 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><p className="text-sm font-semibold">{event.reason || (event.entityType === 'Password' ? 'Thay đổi mật khẩu' : event.action === 'LOGIN' ? 'Đăng nhập thành công' : 'Cập nhật bảo mật')}</p><p className="text-xs text-[var(--bb-muted)]">{event.entityType} · {event.action}</p></div><time className="text-xs text-[var(--bb-muted)]">{new Date(event.createdAt).toLocaleString('vi-VN')}</time></article>)}</div>}</Card>
    <Dialog open={revokeOthersOpen} onClose={() => setRevokeOthersOpen(false)} title="Thu hồi các phiên khác?" description="Thiết bị hiện tại vẫn được giữ lại." footer={<><Button variant="secondary" onClick={() => setRevokeOthersOpen(false)}>Giữ nguyên</Button><Button variant="danger" loading={busy === 'others'} onClick={revokeOthers}>Thu hồi tất cả phiên khác</Button></>}><InlineNotice tone="warning">Các thiết bị khác sẽ phải đăng nhập lại. Hãy thực hiện nếu bạn không nhận ra thiết bị hoặc vừa đổi mật khẩu.</InlineNotice></Dialog>
  </Page>;
}
