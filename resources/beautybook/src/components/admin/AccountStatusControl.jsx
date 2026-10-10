import { useEffect, useRef, useState } from 'react';
import toast from 'react-hot-toast';
import { usersApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Button, Card, Dialog, Field, Textarea } from '../ui';
export default function AccountStatusControl({ user, onChanged }) {
  const can = useAuthStore((state) => state.can);
  const currentUser = useAuthStore((state) => state.user);
  const [active, setActive] = useState(user.isActive);
  const [open, setOpen] = useState(false);
  const [impact, setImpact] = useState(null);
  const [reason, setReason] = useState('');
  const [acknowledged, setAcknowledged] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const inFlight = useRef(false);
  const generation = useRef(0);
  useEffect(() => { generation.current += 1; setActive(user.isActive); setOpen(false); return () => { generation.current += 1; }; }, [user.id, user.isActive]);
  if (!can('user:suspend:platform') || user.id === currentUser?.id) return null;
  const prepare = async () => {
    const request = ++generation.current;
    setOpen(true); setImpact(null); setReason(''); setAcknowledged(false); setError(''); setBusy(true);
    try { const result = await usersApi.suspensionImpact(user.id); if (request === generation.current) { setImpact(result); setActive(result.isActive); } }
    catch (requestError) { if (request === generation.current) setError(requestError.message); }
    finally { if (request === generation.current) setBusy(false); }
  };
  const submit = async () => {
    if (inFlight.current || !impact || !reason.trim()) return;
    inFlight.current = true; setBusy(true); setError('');
    const request = generation.current;
    try {
      const result = await usersApi.suspend(user.id, { isActive: !active, expectedIsActive: impact.isActive, reason: reason.trim(), acknowledgeImpact: acknowledged });
      if (request !== generation.current) return;
      setActive(result.isActive); setOpen(false); void onChanged?.(); toast.success(result.isActive ? 'Đã mở lại tài khoản' : 'Đã ngừng hoạt động tài khoản');
    } catch (requestError) { if (request === generation.current) setError(requestError.message || 'Không thể cập nhật. Hãy tải lại ảnh hưởng trước khi thử lại.'); }
    finally { inFlight.current = false; if (request === generation.current) setBusy(false); }
  };
  return <Card className="p-5"><h2 className="font-bold">Quản trị trạng thái tài khoản</h2><p className="mt-2 text-sm">{active ? 'Tài khoản đang hoạt động.' : 'Tài khoản đang bị khóa.'} Khóa sẽ thu hồi phiên đăng nhập; lịch hẹn và dữ liệu vẫn được giữ.</p><Button className="mt-3" variant={active ? 'danger' : 'secondary'} onClick={prepare}>{active ? 'Ngừng hoạt động tài khoản' : 'Mở lại tài khoản'}</Button>
    <Dialog open={open} onClose={() => { if (!busy) { generation.current += 1; setOpen(false); } }} title={active ? 'Ngừng hoạt động tài khoản?' : 'Mở lại tài khoản?'} footer={<><Button variant="secondary" disabled={busy} onClick={prepare}>Tải lại ảnh hưởng</Button><Button variant={active ? 'danger' : 'primary'} loading={busy} disabled={!impact || !reason.trim() || (active && impact.requiresAcknowledgement && !acknowledged)} onClick={submit}>Xác nhận</Button></>}>
      {busy && !impact && <p role="status">Đang kiểm tra ảnh hưởng…</p>}{error && <p role="alert" className="mb-3 text-[var(--bb-danger)]">{error}</p>}
      {impact && <><ul className="mb-4 list-disc pl-5 text-sm"><li>Lịch khách hàng đang hoạt động/sắp tới: {impact.customerBookings}</li><li>Dịch vụ đang phân công: {impact.assignedServices}</li><li>Phân công chi nhánh: {impact.activeAssignments}</li><li>Doanh nghiệp thuộc trách nhiệm: {impact.ownedBusinesses}</li></ul><p className="mb-4 text-sm">Giữ lịch và phân công hiện có. Người quản lý cần phối hợp chuyển người phụ trách hoặc xử lý từng lịch theo chính sách. Mở lại tài khoản không khôi phục phiên cũ.</p>{active && impact.requiresAcknowledgement && <label className="mb-4 flex min-h-11 items-start gap-3 text-sm"><input type="checkbox" checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} />Tôi xác nhận phương án giữ lịch hiện có và phối hợp xử lý các ảnh hưởng trên.</label>}</>}
      <Field label="Lý do" required><Textarea maxLength={1000} value={reason} onChange={(event) => setReason(event.target.value)} /></Field>
    </Dialog>
  </Card>;
}
