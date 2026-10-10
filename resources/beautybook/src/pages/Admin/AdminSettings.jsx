import { useEffect, useMemo, useState } from 'react';
import { Bell, CalendarDays, RotateCcw, Save, ShieldCheck, Store } from 'lucide-react';
import toast from 'react-hot-toast';
import { adminApi } from '../../api/apiClient';
import { Button, Card, Dialog, ErrorState, Field, InlineNotice, Input, Page, PageHeader, Select, Skeleton } from '../../components/ui';

const numberRules = {
  maxAdvanceBookingDays: [1, 365], minBookingLeadTimeHours: [0, 168], freeCancellationHours: [0, 168],
  maxRescheduleCountPerBooking: [0, 10], pendingHoldMinutes: [5, 1440], appointmentReminderBeforeHours: [0, 168],
  reviewReminderAfterHours: [0, 720], maxBranchesPerBusiness: [1, 1000], reviewMinLength: [0, 500],
  autoHideReviewReportThreshold: [1, 100], violationSuspendThreshold: [1, 100],
};

const sections = [
  { title: 'Chính sách đặt lịch', description: 'Các giới hạn này được hệ thống áp dụng khi tạo, hủy và yêu cầu đổi lịch.', icon: CalendarDays, fields: [
    ['maxAdvanceBookingDays', 'Đặt trước tối đa', 'number', 'ngày'], ['minBookingLeadTimeHours', 'Báo trước tối thiểu', 'number', 'giờ'],
    ['freeCancellationHours', 'Mốc khách tự hủy', 'number', 'giờ'], ['allowRescheduleRequests', 'Cho phép yêu cầu đổi lịch', 'boolean'],
    ['maxRescheduleCountPerBooking', 'Số lần đổi tối đa', 'number', 'lần'], ['pendingHoldMinutes', 'Giữ chỗ khi chờ xác nhận', 'number', 'phút'],
  ] },
  { title: 'Thông báo', description: 'Thông báo nội bộ hoạt động; kênh ngoài cần nhà cung cấp tương ứng.', icon: Bell, fields: [
    ['appointmentReminderBeforeHours', 'Nhắc trước lịch hẹn', 'number', 'giờ'], ['reviewReminderAfterHours', 'Nhắc đánh giá sau dịch vụ', 'number', 'giờ'],
    ['emailEnabled', 'Email', 'boolean', '', 'Chưa cấu hình nhà cung cấp SMTP; bật không đồng nghĩa email production đã sẵn sàng.'],
    ['smsEnabled', 'SMS', 'boolean', '', 'Cần cấu hình nhà cung cấp SMS để gửi thật.'],
    ['pushEnabled', 'Push', 'boolean', '', 'Cần app mobile và push provider để gửi push notification.'],
  ] },
  { title: 'Onboarding & quy tắc nền tảng', description: 'Điều khiển xác minh, luồng duyệt và giới hạn chi nhánh.', icon: Store, fields: [
    ['autoApproveNewSalons', 'Tự động duyệt hồ sơ hợp lệ', 'boolean'], ['requirePhoneVerification', 'Bắt buộc xác minh điện thoại', 'boolean', '', 'Nếu bật, hồ sơ bị chặn khi số điện thoại chưa verified; hệ thống chưa fake OTP.'],
    ['requireIdVerification', 'Bắt buộc giấy phép và CCCD', 'boolean'], ['maxBranchesPerBusiness', 'Số chi nhánh tối đa', 'number', 'chi nhánh'],
  ] },
  { title: 'Đánh giá & an toàn nền tảng', description: 'Áp dụng khi gửi đánh giá, báo cáo nội dung và xử lý vi phạm.', icon: ShieldCheck, fields: [
    ['reviewMinLength', 'Độ dài đánh giá tối thiểu', 'number', 'ký tự'], ['allowAnonymousReview', 'Cho phép đánh giá ẩn danh', 'boolean'],
    ['autoHideReviewReportThreshold', 'Ngưỡng báo cáo để tự động ẩn', 'number', 'báo cáo'], ['violationSuspendThreshold', 'Ngưỡng vi phạm cảnh báo', 'number', 'lần'],
  ] },
];

function SettingControl({ field, configured, defaults, update, error }) {
  const [key, label, type, unit, note] = field; const fallback = defaults[key];
  if (key === 'freeCancellationHours') return <Field label="Mốc khách tự hủy" hint="Quy tắc cố định: còn ít nhất 4 giờ. Dưới 4 giờ cần cơ sở xử lý yêu cầu hủy; không thu phí."><Input value="4 giờ" readOnly /></Field>;
  const hint = `${configured[key] === undefined ? `Chưa cấu hình · Đang dùng mặc định: ${String(fallback)}${unit ? ` ${unit}` : ''}.` : `Đang áp dụng: ${String(configured[key])}${unit ? ` ${unit}` : ''}.`} ${note || ''}`;
  if (type === 'boolean') return <Field label={label} hint={hint} error={error}><Select value={configured[key] === undefined ? '' : String(configured[key])} onChange={(event) => update(key, event.target.value === '' ? undefined : event.target.value === 'true')}><option value="">Chưa cấu hình</option><option value="true">Bật</option><option value="false">Tắt</option></Select></Field>;
  const [min, max] = numberRules[key];
  return <Field label={label} hint={hint} error={error}><Input type="number" min={min} max={max} step="1" value={configured[key] ?? ''} onChange={(event) => update(key, event.target.value === '' ? undefined : Number(event.target.value))} /></Field>;
}

export function AdminSettings() {
  const [configured, setConfigured] = useState({}); const [original, setOriginal] = useState({}); const [defaults, setDefaults] = useState({});
  const [loading, setLoading] = useState(true); const [saving, setSaving] = useState(false); const [error, setError] = useState('');
  const [resetOpen, setResetOpen] = useState(false);
  const dirty = JSON.stringify(configured) !== JSON.stringify(original);
  const errors = useMemo(() => Object.fromEntries(Object.entries(configured).flatMap(([key, value]) => {
    const rule = numberRules[key]; if (!rule || key === 'freeCancellationHours') return []; const [min, max] = rule;
    return !Number.isInteger(value) || value < min || value > max ? [[key, `Nhập số nguyên từ ${min} đến ${max}`]] : [];
  })), [configured]);
  const load = async () => { setLoading(true); setError(''); try { const remote = await adminApi.getSettings(); setConfigured(remote?.configured || {}); setOriginal(remote?.configured || {}); setDefaults(remote?.defaults || {}); } catch (e) { setError(e.message || 'Không thể tải cài đặt nền tảng.'); } finally { setLoading(false); } };
  useEffect(() => { load(); }, []);
  const update = (key, value) => setConfigured((current) => { const next = { ...current }; if (value === undefined) delete next[key]; else next[key] = value; return next; });
  const save = async () => { if (Object.keys(errors).length) return toast.error('Vui lòng sửa các giá trị không hợp lệ'); setSaving(true); try { const response = await adminApi.updateSettings({ ...configured, freeCancellationHours: 4 }); setConfigured(response.configured || {}); setOriginal(response.configured || {}); setDefaults(response.defaults || defaults); toast.success('Đã lưu và áp dụng chính sách nền tảng'); } catch (e) { toast.error(e.message || 'Không thể lưu cài đặt'); } finally { setSaving(false); } };
  const reset = async () => { setSaving(true); try { const response = await adminApi.resetSettings(); setConfigured(response.configured || {}); setOriginal(response.configured || {}); setDefaults(response.defaults || defaults); setResetOpen(false); toast.success('Đã khôi phục mặc định'); } catch (e) { toast.error(e.message); } finally { setSaving(false); } };
  return <Page className="max-w-6xl">
    <PageHeader eyebrow="QUẢN TRỊ NỀN TẢNG" title="Cài đặt hệ thống" description="Mỗi chính sách bên dưới được kiểm tra ở cả giao diện và máy chủ trước khi áp dụng cho nghiệp vụ." actions={<div className="flex flex-wrap gap-2"><Button variant="secondary" onClick={() => setResetOpen(true)} disabled={saving}><RotateCcw size={16} />Khôi phục mặc định</Button><Button onClick={save} loading={saving} disabled={!dirty || Object.keys(errors).length > 0}><Save size={16} />Lưu thay đổi</Button></div>} />
    <InlineNotice tone="info">Thông báo nội bộ đang hoạt động. Email, SMS và push production vẫn cần cấu hình provider tương ứng.</InlineNotice>
    {dirty && <InlineNotice tone="warning">Bạn có thay đổi chưa lưu.</InlineNotice>}
    {loading ? <Card className="p-5"><Skeleton rows={10} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : <div className="grid gap-4 lg:grid-cols-2">{sections.map(({ title, description, icon: Icon, fields }) => <Card key={title} className="p-5"><div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-pink-50 text-pink-700"><Icon size={18} /></span><div><h2 className="font-bold">{title}</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{description}</p></div></div><div className="mt-5 grid gap-4 sm:grid-cols-2">{fields.map((field) => <SettingControl key={field[0]} field={field} configured={configured} defaults={defaults} update={update} error={errors[field[0]]} />)}</div></Card>)}</div>}
    <Dialog open={resetOpen} onClose={() => setResetOpen(false)} title="Khôi phục cài đặt mặc định?" description="Thay đổi này tác động đến toàn bộ nền tảng và sẽ được ghi vào nhật ký kiểm toán." footer={<><Button variant="secondary" onClick={() => setResetOpen(false)}>Hủy</Button><Button variant="danger" loading={saving} onClick={reset}>Khôi phục mặc định</Button></>}><InlineNotice tone="warning">Tất cả cấu hình tùy chỉnh hiện tại sẽ bị xóa. Các phân hệ nghiệp vụ sẽ dùng lại giá trị mặc định ngay sau khi xác nhận.</InlineNotice></Dialog>
  </Page>;
}

export default AdminSettings;
