import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import {
  ArrowRight,
  Building2,
  CheckCircle2,
  CircleAlert,
  MapPin,
  Plus,
  Store,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { branchesApi, businessApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { FileUpload } from '../../components/media/FileUpload';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  InlineNotice,
  Input,
  MetricCard,
  Page,
  PageHeader,
  Select,
  Skeleton,
  Textarea,
} from '../../components/ui';

const reviewTone = {
  DRAFT: 'neutral',
  SUBMITTED: 'warning',
  PENDING_REVIEW: 'warning',
  NEED_MORE_INFO: 'warning',
  APPROVED: 'success',
  REJECTED: 'danger',
};
const operationalTone = {
  INACTIVE: 'neutral',
  READY_TO_PUBLISH: 'info',
  ACTIVE: 'success',
  PAUSED: 'warning',
  SUSPENDED: 'danger',
  CLOSED: 'danger',
  ARCHIVED: 'neutral',
};
const reviewLabel = {
  DRAFT: 'Bản nháp',
  SUBMITTED: 'Đã gửi',
  PENDING_REVIEW: 'Đang chờ duyệt',
  NEED_MORE_INFO: 'Cần bổ sung',
  APPROVED: 'Đã duyệt',
  REJECTED: 'Bị từ chối',
};
const operationalLabel = {
  INACTIVE: 'Chưa nhận lịch',
  READY_TO_PUBLISH: 'Sẵn sàng đăng',
  ACTIVE: 'Đang nhận lịch',
  PAUSED: 'Tạm dừng',
  SUSPENDED: 'Đình chỉ',
  CLOSED: 'Đã đóng',
  ARCHIVED: 'Đã lưu trữ',
};

const emptyForm = {
  name: '',
  publicName: '',
  description: '',
  addressLine: '',
  phone: '',
  email: '',
  bookingConfirmationMode: 'MANUAL_CONFIRMATION',
  staffAssignmentMode: 'AUTO_ASSIGN_IF_ANY_STAFF',
  pendingHoldMinutes: 30,
};

function BranchCard({ branch, canPublish, onSelect, onPublish, publishing }) {
  const readiness = branch.readiness || {};
  return (
    <Card className="flex h-full min-w-0 flex-col p-5">
      <div className="flex items-start gap-3">
        <span className="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><Store size={20} /></span>
        <div className="min-w-0 flex-1">
          <h3 className="truncate font-bold">{branch.name}</h3>
          <p className="mt-1 truncate text-xs text-[var(--bb-muted)]">{branch.addressLine || 'Chưa hoàn tất địa chỉ'}</p>
        </div>
      </div>
      <div className="mt-4 flex flex-wrap gap-2">
        <Badge tone={reviewTone[branch.reviewStatus]}>Duyệt: {reviewLabel[branch.reviewStatus] || branch.reviewStatus}</Badge>
        <Badge tone={operationalTone[branch.operationalStatus]}>Nhận lịch: {operationalLabel[branch.operationalStatus] || branch.operationalStatus}</Badge>
      </div>
      <div className="mt-4">
        <div className="flex justify-between text-xs font-bold"><span>Mức sẵn sàng</span><span>{readiness.progress ?? 0}%</span></div>
        <div className="mt-2 h-2 overflow-hidden rounded-full bg-[var(--bb-surface-subtle)]"><div className="h-full bg-[var(--bb-brand)]" style={{ width: `${readiness.progress ?? 0}%` }} /></div>
      </div>
      {branch.reviewNote && <p className="mt-3 rounded-lg bg-amber-50 p-3 text-xs font-semibold text-amber-900">{branch.reviewNote}</p>}
      {!readiness.ready && readiness.reasons?.[0] && <p className="mt-3 text-xs text-[var(--bb-muted)]">{readiness.reasons[0]}{readiness.reasons.length > 1 ? ` và ${readiness.reasons.length - 1} mục khác` : ''}</p>}
      <div className="mt-auto flex flex-wrap gap-2 pt-5">
        {['DRAFT', 'NEED_MORE_INFO'].includes(branch.reviewStatus) && <Link to={`/salon/branches/${branch.id}/setup`}><Button size="sm">Tiếp tục thiết lập</Button></Link>}
        <Button size="sm" variant="secondary" onClick={() => onSelect(branch.id)}>Xem hồ sơ</Button>
        {canPublish && branch.reviewStatus === 'APPROVED' && readiness.ready && branch.operationalStatus !== 'ACTIVE' && (
          <Button size="sm" loading={publishing} onClick={() => onPublish(branch.id)}>Bật nhận đặt lịch</Button>
        )}
      </div>
    </Card>
  );
}

export function SalonProfile() {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const [business, setBusiness] = useState(null);
  const [branches, setBranches] = useState([]);
  const [selectedId, setSelectedId] = useState('');
  const [detail, setDetail] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [publishing, setPublishing] = useState('');
  const [branchImage, setBranchImage] = useState(null);
  const [businessLogo, setBusinessLogo] = useState(null);
  const edit = can('branch:update:tenant');
  const create = can('branch:create:tenant');
  const owner = [...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)].includes('BUSINESS_OWNER');

  const fillDetail = (branch) => {
    setDetail(branch);
    setSelectedId(branch.id);
    setBranchImage(branch.images?.[0]?.media || null);
    setBusinessLogo(branch.business?.logoMedia || (branch.business?.logoMediaId ? { id: branch.business.logoMediaId } : null));
    setForm({
      name: branch.name || '',
      publicName: branch.publicName || '',
      description: branch.description || '',
      addressLine: branch.addressLine || '',
      phone: branch.phone || '',
      email: branch.email || '',
      bookingConfirmationMode: branch.bookingConfirmationMode || 'MANUAL_CONFIRMATION',
      staffAssignmentMode: branch.staffAssignmentMode || 'AUTO_ASSIGN_IF_ANY_STAFF',
      pendingHoldMinutes: branch.pendingHoldMinutes ?? 30,
    });
  };

  const load = async (preferredId) => {
    setLoading(true);
    setError('');
    try {
      const [businessValue, accessible] = await Promise.all([
        owner ? businessApi.getMyOnboarding() : Promise.resolve(null),
        branchesApi.getAccessible(),
      ]);
      setBusiness(businessValue);
      const details = await Promise.all(accessible.map((item) => branchesApi.getAccessibleById(item.id)));
      setBranches(details);
      const selected = details.find((item) => item.id === (preferredId || selectedId)) || details[0];
      if (selected) fillDetail(selected);
      else {
        setDetail(null);
        setSelectedId('');
      }
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải hồ sơ doanh nghiệp');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);

  const metrics = useMemo(() => ({
    total: branches.length,
    active: branches.filter((item) => item.operationalStatus === 'ACTIVE').length,
    pending: branches.filter((item) => ['SUBMITTED', 'PENDING_REVIEW'].includes(item.reviewStatus)).length,
    needsInfo: branches.filter((item) => item.reviewStatus === 'NEED_MORE_INFO').length,
    inactive: branches.filter((item) => item.operationalStatus === 'INACTIVE').length,
  }), [branches]);

  const update = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const save = async (event) => {
    event.preventDefault();
    const hold = Number(form.pendingHoldMinutes);
    if (!Number.isInteger(hold) || hold < 5 || hold > 1440) {
      toast.error('Thời gian giữ lịch phải từ 5 đến 1440 phút');
      return;
    }
    setSaving(true);
    try {
      await branchesApi.update(selectedId, {
        ...form,
        publicName: form.publicName || undefined,
        description: form.description || undefined,
        phone: form.phone || undefined,
        email: form.email || undefined,
        pendingHoldMinutes: hold,
      });
      toast.success('Đã cập nhật hồ sơ chi nhánh');
      await load(selectedId);
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setSaving(false);
    }
  };

  const publish = async (id) => {
    setPublishing(id);
    try {
      await branchesApi.publish(id);
      toast.success('Chi nhánh đã bắt đầu nhận đặt lịch');
      await load(id);
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setPublishing('');
    }
  };

  return (
    <Page className="max-w-[1500px]">
      <PageHeader
        eyebrow={owner ? 'Trung tâm doanh nghiệp & chi nhánh' : 'Hồ sơ chi nhánh'}
        title={owner ? 'Hồ sơ doanh nghiệp & chi nhánh' : 'Hồ sơ chi nhánh'}
        description={owner ? 'Theo dõi xác minh doanh nghiệp, xét duyệt chi nhánh và khả năng nhận đặt lịch như ba trạng thái độc lập.' : 'Quản lý thông tin trong phạm vi chi nhánh được phân công.'}
        actions={create ? <Link to="/salon/branches/new"><Button><Plus size={16} />Thêm chi nhánh</Button></Link> : null}
      />

      {loading ? <Card className="p-5"><Skeleton rows={10} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : (
        <div className="space-y-6">
          {owner && business && (
            <Card className="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_auto]">
              <div className="flex items-start gap-4">
                <span className="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><Building2 size={22} /></span>
                <div>
                  <div className="flex flex-wrap items-center gap-2"><h2 className="text-xl font-bold">{business.name}</h2><Badge tone="success">Doanh nghiệp: {business.status}</Badge></div>
                  <p className="mt-2 text-sm text-[var(--bb-muted)]">{business.contactEmail} · {business.contactPhone}</p>
                  <p className="mt-1 text-sm text-[var(--bb-muted)]">{business.addressLine}</p>
                </div>
              </div>
              <div className="flex items-center"><Badge tone={business.bookingRestrictedAt ? 'danger' : 'success'}>{business.bookingRestrictedAt ? 'Đang bị hạn chế nhận lịch' : 'Không bị hạn chế nhận lịch'}</Badge></div>
            </Card>
          )}

          {owner && (
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              <MetricCard icon={Store} label="Tổng chi nhánh" value={metrics.total} />
              <MetricCard icon={CheckCircle2} label="Đang nhận lịch" value={metrics.active} tone="success" />
              <MetricCard icon={CircleAlert} label="Chờ duyệt" value={metrics.pending} tone="warning" />
              <MetricCard icon={CircleAlert} label="Cần bổ sung" value={metrics.needsInfo} tone="warning" />
              <MetricCard icon={Store} label="Chưa hoạt động" value={metrics.inactive} />
            </div>
          )}

          {!branches.length ? (
            <Card><EmptyState icon={Store} title="Chưa có chi nhánh" description="Tạo chi nhánh đầu tiên bằng biểu mẫu riêng; hồ sơ doanh nghiệp không chứa cấu hình vận hành của chi nhánh." action={create ? <Link to="/salon/branches/new"><Button>Thêm chi nhánh</Button></Link> : null} /></Card>
          ) : (
            <section>
              <div className="mb-3"><h2 className="text-lg font-bold">Danh sách chi nhánh</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Mỗi chi nhánh có trạng thái xét duyệt, vận hành và điều kiện nhận lịch riêng.</p></div>
              <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {branches.map((branch) => <BranchCard key={branch.id} branch={branch} canPublish={owner && edit} onSelect={fillDetail} onPublish={publish} publishing={publishing === branch.id} />)}
              </div>
            </section>
          )}

          {detail && (
            <section id="branch-profile-editor" className="scroll-mt-24">
              <Card className="overflow-hidden">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--bb-border)] p-5">
                  <div><h2 className="font-bold">Hồ sơ chi nhánh</h2><p className="mt-1 text-xs text-[var(--bb-muted)]">Thông tin công khai và chính sách giữ giờ đặt lịch.</p></div>
                  <Select value={selectedId} onChange={(event) => {
                    const selected = branches.find((item) => item.id === event.target.value);
                    if (selected) fillDetail(selected);
                  }}>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select>
                </div>
                {!detail.readiness?.ready && <InlineNotice tone="warning" className="m-5"><strong>Chưa sẵn sàng nhận lịch.</strong><ul className="mt-2 list-disc pl-5">{detail.readiness?.reasons?.map((reason) => <li key={reason}>{reason}</li>)}</ul></InlineNotice>}
                {owner && <div className="flex flex-wrap gap-3 px-5 pt-5">
                  <Link to={`/explore/branches/${detail.id}`} target="_blank" rel="noopener noreferrer"><Button variant="secondary">Xem trang công khai</Button></Link>
                  <Link to={`/salon/branches/${detail.id}/preview`} target="_blank" rel="noopener noreferrer"><Button variant="secondary">Xem trước</Button></Link>
                </div>}
                <form onSubmit={save} className="grid gap-5 p-5 sm:grid-cols-2">
                  {owner && detail.business?.id && <div className="sm:col-span-2"><FileUpload entityType="BUSINESS_LOGO" entityId={detail.business.id} businessId={detail.business.id} value={businessLogo} label="Logo doanh nghiệp" disabled={!edit} onUploaded={setBusinessLogo} onRemoved={() => setBusinessLogo(null)} /></div>}
                  <div className="sm:col-span-2"><FileUpload entityType="BRANCH_IMAGE" entityId={detail.id} businessId={detail.business?.id} branchId={detail.id} value={branchImage} label="Ảnh đại diện chi nhánh" disabled={!edit} onUploaded={setBranchImage} onRemoved={() => setBranchImage(null)} /></div>
                  <Field label="Tên nội bộ" required><Input disabled={!edit} required value={form.name} onChange={update('name')} /></Field>
                  <Field label="Tên công khai"><Input disabled={!edit} value={form.publicName} onChange={update('publicName')} /></Field>
                  <Field label="Điện thoại"><Input disabled={!edit} value={form.phone} onChange={update('phone')} /></Field>
                  <Field label="Email"><Input disabled={!edit} type="email" value={form.email} onChange={update('email')} /></Field>
                  <Field label="Địa chỉ" className="sm:col-span-2"><Input disabled={!edit} value={form.addressLine} onChange={update('addressLine')} /></Field>
                  <Field label="Mô tả công khai" className="sm:col-span-2"><Textarea disabled={!edit} value={form.description} onChange={update('description')} /></Field>
                  <Field label="Xác nhận lịch hẹn"><Select disabled={!edit} value={form.bookingConfirmationMode} onChange={update('bookingConfirmationMode')}><option value="MANUAL_CONFIRMATION">Duyệt thủ công</option><option value="AUTO_CONFIRMATION">Tự động xác nhận</option></Select></Field>
                  <Field label="Phân công nhân viên"><Select disabled={!edit} value={form.staffAssignmentMode} onChange={update('staffAssignmentMode')}><option value="AUTO_ASSIGN_IF_ANY_STAFF">Hệ thống chọn</option><option value="CUSTOMER_SELECTS_STAFF">Khách chọn</option><option value="MANUAL_ASSIGN_BY_RECEPTIONIST">Quầy phân công</option></Select></Field>
                  <Field label="Giữ lịch chờ (phút)"><Input disabled={!edit} type="number" min="5" max="1440" value={form.pendingHoldMinutes} onChange={update('pendingHoldMinutes')} /></Field>
                  <div className="flex items-end gap-2 pb-5">
                    {edit && <Button type="submit" loading={saving}>Lưu thay đổi</Button>}
                    {['DRAFT', 'NEED_MORE_INFO'].includes(detail.reviewStatus) && <Link to={`/salon/branches/${detail.id}/setup`}><Button type="button" variant="secondary">Mở biểu mẫu thiết lập <ArrowRight size={15} /></Button></Link>}
                  </div>
                  <div className="sm:col-span-2 flex items-center gap-2 text-sm text-[var(--bb-muted)]"><MapPin size={15} />{detail.district?.province?.name} · {detail.district?.name}</div>
                </form>
              </Card>
            </section>
          )}
        </div>
      )}
    </Page>
  );
}

export default SalonProfile;
