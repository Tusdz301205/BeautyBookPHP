import { useEffect, useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Mail, Send, ShieldX } from 'lucide-react';
import toast from 'react-hot-toast';
import { staffApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, Page, PageHeader, Select, Skeleton } from '../../components/ui';

const tabs = [
  ['profile', 'Tổng quan'], ['account', 'Tài khoản'], ['assignments', 'Vai trò & chi nhánh'],
  ['services', 'Dịch vụ'], ['documents', 'Tài liệu'], ['audit', 'Nhật ký'],
];
const date = (value) => value ? new Date(value).toLocaleDateString('vi-VN') : '—';
const dateTime = (value) => value ? new Date(value).toLocaleString('vi-VN') : '—';

function Rows({ rows, render, empty = 'Chưa có dữ liệu.' }) {
  if (!rows?.length) return <EmptyState title={empty} />;
  return <div className="divide-y divide-[var(--bb-border)] rounded-xl border border-[var(--bb-border)]">{rows.map(render)}</div>;
}

function AccountPanel({ staff, onChanged }) {
  const can = useAuthStore((state) => state.can);
  const canManage = !!staff.branch?.businessId && can('user:role_assign:tenant', { tenantId: staff.branch.businessId, branchId: staff.branch.id });
  const invitation = staff.invitations?.[0];
  const [dialog, setDialog] = useState('');
  const [email, setEmail] = useState(invitation?.email || '');
  const [roleCode, setRoleCode] = useState(invitation?.roleCode || 'STAFF');
  const [busy, setBusy] = useState(false);
  const status = staff.user ? 'ACTIVE' : invitation?.status === 'PENDING' ? 'INVITED' : staff.status === 'LOCKED' ? 'LOCKED' : 'PROFILE_ONLY';
  const run = async (action) => {
    if (!canManage) return;
    if (action === 'invite' && !['STAFF', 'RECEPTIONIST'].includes(roleCode)) {
      toast.error('Chọn vai trò nhân viên hoặc lễ tân hợp lệ.'); return;
    }
    setBusy(true);
    try {
      if (action === 'invite') await staffApi.invite({ staffProfileId: staff.id, email: email.trim(), roleCode, businessId: staff.branch?.businessId, branchId: staff.branch?.id });
      if (action === 'resend') await staffApi.resendInvitation(invitation.id);
      if (action === 'email') await staffApi.changeInvitationEmail(invitation.id, email.trim());
      if (action === 'revoke') await staffApi.revokeInvitation(invitation.id);
      toast.success(action === 'revoke' ? 'Đã thu hồi lời mời' : action === 'resend' ? 'Đã gửi lại lời mời' : 'Đã cập nhật lời mời');
      setDialog('');
      await onChanged();
    } catch (error) { toast.error(error.message || 'Không thể xử lý lời mời'); }
    finally { setBusy(false); }
  };
  return <Card className="p-5">
    <div className="flex flex-wrap items-center gap-2"><Badge tone={status === 'ACTIVE' ? 'success' : status === 'INVITED' ? 'info' : status === 'LOCKED' ? 'danger' : 'neutral'}>{status}</Badge><Badge>{staff.status}</Badge></div>
    <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-2"><div><dt className="text-[var(--bb-muted)]">Email công việc</dt><dd className="mt-1 font-semibold">{staff.user?.email || invitation?.email || 'Chưa cấp tài khoản'}</dd></div><div><dt className="text-[var(--bb-muted)]">Đăng nhập gần nhất</dt><dd className="mt-1 font-semibold">{dateTime(staff.user?.lastLoginAt)}</dd></div></dl>
    {invitation && <div className="mt-5 rounded-xl border border-[var(--bb-border)] p-4 text-sm"><p><strong>Lời mời gần nhất:</strong> {invitation.status}</p><p className="mt-1 text-[var(--bb-muted)]">Gửi {dateTime(invitation.createdAt)} · hết hạn {dateTime(invitation.expiresAt)}{invitation.acceptedAt ? ` · nhận ${dateTime(invitation.acceptedAt)}` : ''}</p></div>}
    <div className="mt-5 flex flex-wrap gap-2">
      {canManage && !staff.user && !invitation?.status?.includes('PENDING') && <Button onClick={() => { setEmail(''); setDialog('invite'); }}><Mail size={16} />Cấp tài khoản</Button>}
      {canManage && invitation?.status === 'PENDING' && <><Button variant="secondary" onClick={() => run('resend')}><Send size={16} />Gửi lại</Button><Button variant="secondary" onClick={() => { setEmail(invitation.email); setDialog('email'); }}>Đổi email</Button><Button variant="danger" onClick={() => setDialog('revoke')}><ShieldX size={16} />Thu hồi</Button></>}
    </div>
    <Dialog open={canManage && (dialog === 'invite' || dialog === 'email')} onClose={() => setDialog('')} title={dialog === 'invite' ? 'Cấp tài khoản nhân viên' : 'Đổi email nhận lời mời'} description="Lời mời luôn liên kết với hồ sơ nhân sự này; hệ thống không tạo hồ sơ thứ hai." footer={<><Button variant="secondary" onClick={() => setDialog('')}>Hủy</Button><Button loading={busy} disabled={!email.trim()} onClick={() => run(dialog)}>Lưu và gửi</Button></>}><div className="grid gap-4"><Field label="Email" required><Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} /></Field>{dialog === 'invite' && <Field label="Vai trò"><Select value={roleCode} onChange={(event) => setRoleCode(event.target.value)}><option value="STAFF">Nhân viên dịch vụ</option><option value="RECEPTIONIST">Lễ tân</option></Select></Field>}</div></Dialog>
    <Dialog open={canManage && dialog === 'revoke'} onClose={() => setDialog('')} title="Thu hồi lời mời?" description="Link hiện tại sẽ hết hiệu lực ngay và không thể dùng lại." footer={<><Button variant="secondary" onClick={() => setDialog('')}>Đóng</Button><Button variant="danger" loading={busy} onClick={() => run('revoke')}>Thu hồi</Button></>} />
  </Card>;
}

export default function StaffDetail() {
  const { staffId } = useParams();
  const [params, setParams] = useSearchParams();
  const active = tabs.some(([key]) => key === params.get('tab')) ? params.get('tab') : 'profile';
  const [staff, setStaff] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const load = async () => { setLoading(true); setError(''); try { setStaff(await staffApi.getById(staffId)); } catch (requestError) { setError(requestError.message || 'Không thể tải hồ sơ nhân sự.'); } finally { setLoading(false); } };
  useEffect(() => { load(); }, [staffId]);

  const content = useMemo(() => {
    if (!staff) return null;
    if (active === 'profile') return <div className="grid gap-3 sm:grid-cols-2"><Card className="p-4"><p className="text-xs font-bold uppercase text-[var(--bb-muted)]">Họ tên</p><p className="mt-2 font-bold">{staff.fullName}</p></Card><Card className="p-4"><p className="text-xs font-bold uppercase text-[var(--bb-muted)]">Vị trí</p><p className="mt-2 font-bold">{staff.position || '—'}</p></Card><Card className="p-4"><p className="text-xs font-bold uppercase text-[var(--bb-muted)]">Nhận lịch</p><p className="mt-2 font-bold">{staff.isBookable ? 'Có' : 'Không'}</p></Card><Card className="p-4"><p className="text-xs font-bold uppercase text-[var(--bb-muted)]">Hiển thị công khai</p><p className="mt-2 font-bold">{staff.publicVisible ? 'Có' : 'Không'}</p></Card><Card className="p-4 sm:col-span-2"><p className="text-xs font-bold uppercase text-[var(--bb-muted)]">Giới thiệu</p><p className="mt-2 text-sm leading-6">{staff.bio || 'Chưa cập nhật.'}</p></Card></div>;
    if (active === 'account') return <AccountPanel staff={staff} onChanged={load} />;
    if (active === 'assignments') return <Rows rows={staff.branchAssignments} empty="Chưa có phân công chi nhánh." render={(item) => <div key={item.id} className="grid gap-2 p-4 text-sm sm:grid-cols-4"><strong>{item.branch?.name}</strong><span>{item.jobTitle || '—'}</span><span>{date(item.startDate)} → {date(item.endDate)}</span><Badge tone={item.status === 'ACTIVE' ? 'success' : 'neutral'}>{item.status}</Badge></div>} />;
    if (active === 'services') return <Rows rows={staff.staffServices} empty="Chưa được gán dịch vụ." render={(item) => <div key={item.id} className="flex items-center justify-between p-4 text-sm"><strong>{item.service?.name}</strong><span>{Number(item.service?.price || 0).toLocaleString('vi-VN')} ₫ · {item.service?.durationMinutes || 0} phút</span></div>} />;
    if (active === 'documents') return <EmptyState title="Chưa có tài liệu nhân sự" description="Hệ thống hiện chưa hỗ trợ lưu tài liệu riêng cho từng nhân viên." />;
    return <Rows rows={staff.auditTrail} empty="Chưa có sự kiện audit cho hồ sơ này." render={(item) => <div key={item.id} className="grid gap-2 p-4 text-sm sm:grid-cols-[150px_1fr_180px]"><Badge>{item.action}</Badge><span>{item.reason || item.entityType}</span><time>{dateTime(item.createdAt)}</time></div>} />;
  }, [active, staff]);

  if (loading) return <Page><Skeleton rows={9} /></Page>;
  if (error) return <Page><ErrorState message={error} onRetry={load} /></Page>;
  return <Page><PageHeader eyebrow="Chi tiết nhân sự" title={staff?.fullName || 'Nhân sự'} description={`${staff?.position || 'Nhân viên'} · ${staff?.branch?.name || 'Chưa có chi nhánh'}`} actions={<Link to="/salon/staff"><Button variant="ghost"><ArrowLeft size={16} />Danh sách</Button></Link>} /><div className="flex gap-1 overflow-x-auto border-b border-[var(--bb-border)]" role="tablist">{tabs.map(([key, label]) => <button key={key} role="tab" aria-selected={active === key} onClick={() => setParams({ tab: key })} className={`shrink-0 border-b-2 px-4 py-3 text-sm font-semibold ${active === key ? 'border-[var(--bb-brand)] text-[var(--bb-brand-strong)]' : 'border-transparent text-[var(--bb-muted)]'}`}>{label}</button>)}</div>{content}</Page>;
}
