import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  CalendarClock,
  Check,
  CheckCircle2,
  Clock3,
  Copy,
  Eye,
  FileCheck2,
  FileText,
  Image,
  MapPin,
  Save,
  Scissors,
  Send,
  Sparkles,
  Store,
  Trash2,
  UserRound,
  Users,
} from 'lucide-react';
import toast from 'react-hot-toast';
import {
  branchesApi,
  businessApi,
  combosApi,
  mediaApi,
  servicesApi,
  staffApi,
} from '../../api/apiClient';
import { FileUpload } from '../../components/media/FileUpload';
import { HomeMedia } from '../../components/public/HomeMedia';
import { getBranchFallbackMedia } from '../../config/homeMedia';
import { documentLabel, statusLabel } from '../../utils/displayLabels';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  InlineNotice,
  Input,
  Page,
  PageHeader,
  Select,
  Skeleton,
  Textarea,
} from '../../components/ui';
import '../../styles/branch-operations.css';

const steps = [
  ['Mô hình cơ sở', Building2],
  ['Thông tin cơ bản', Store],
  ['Địa chỉ & khu vực', MapPin],
  ['Giờ hoạt động', Clock3],
  ['Dịch vụ', Scissors],
  ['Combo', Sparkles],
  ['Nhân viên & quản lý', Users],
  ['Chính sách đặt lịch', CalendarClock],
  ['Hình ảnh công khai', Image],
  ['Tài liệu', FileText],
  ['Thông tin vận hành', FileCheck2],
  ['Kiểm tra điều kiện', CheckCircle2],
  ['Gửi duyệt', Send],
];

const WIZARD_VERSION = 2;
const LEGACY_REMOVED_STEP = 9;

const dayLabels = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];
const today = () => new Date().toISOString().slice(0, 10);

const defaultHours = dayLabels.map((_, dayOfWeek) => ({
  dayOfWeek,
  openTime: '09:00',
  closeTime: '18:00',
  isClosed: dayOfWeek === 0,
}));

const defaults = {
  sameLegalEntity: true,
  serviceMode: 'AT_LOCATION',
  name: '',
  publicName: '',
  phone: '',
  email: '',
  description: '',
  managerName: '',
  scheduledOpeningDate: '',
  districtId: '',
  ward: '',
  addressLine: '',
  floor: '',
  directions: '',
  serviceAreas: '',
  serviceRadiusKm: 10,
  travelFee: 0,
  excludedServiceAreas: '',
  timezone: 'Asia/Ho_Chi_Minh',
  bookingStartDate: '',
  openingHours: defaultHours,
  bookingConfirmationMode: 'MANUAL_CONFIRMATION',
  staffAssignmentMode: 'AUTO_ASSIGN_IF_ANY_STAFF',
  pendingHoldMinutes: 30,
  leadTimeMinutes: 120,
  bookingHorizonDays: 90,
  cancellationHours: 24,
  rescheduleHours: 12,
  noShowHandling: 'Ghi nhận khách không đến và yêu cầu quầy liên hệ khách.',
  earlyCheckInMinutes: 0,
  gracePeriodMinutes: 10,
  allowWalkIn: true,
  allowCounterBooking: true,
  defaultBufferMinutes: 0,
  overbookingEnabled: false,
  maxOverbookedSlots: 0,
};

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

function valueOrEmpty(value) {
  return value === null || value === undefined ? '' : value;
}

function splitAreas(value) {
  return String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);
}

export default function BranchOnboardingWizard() {
  const { branchId: routeBranchId } = useParams();
  const navigate = useNavigate();
  const [business, setBusiness] = useState(null);
  const [branch, setBranch] = useState(null);
  const [branches, setBranches] = useState([]);
  const [districts, setDistricts] = useState([]);
  const [form, setForm] = useState(defaults);
  const [step, setStep] = useState(1);
  const [completedSteps, setCompletedSteps] = useState([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState([]);
  const [dirty, setDirty] = useState(false);
  const [sourceBranchId, setSourceBranchId] = useState('');
  const [sourceServices, setSourceServices] = useState([]);
  const [selectedServices, setSelectedServices] = useState([]);
  const [combos, setCombos] = useState([]);
  const [staff, setStaff] = useState([]);
  const [pendingDocuments, setPendingDocuments] = useState([]);
  const [replacementTarget, setReplacementTarget] = useState(null);
  const branchId = branch?.id || routeBranchId;

  const hydrateBranch = (value) => {
    setBranch(value);
    const draft = value.onboardingProgress?.draftData || {};
    setForm((current) => ({
      ...current,
      ...draft,
      sameLegalEntity: value.sameLegalEntity ?? true,
      serviceMode: value.serviceMode || current.serviceMode,
      name: value.name || '',
      publicName: value.publicName || '',
      phone: value.phone || '',
      email: value.email || '',
      description: value.description || '',
      managerName: value.managerName || '',
      scheduledOpeningDate: value.scheduledOpeningDate?.slice(0, 10) || '',
      districtId: value.districtId || '',
      ward: value.ward || '',
      addressLine: value.addressLine || '',
      floor: value.floor || '',
      directions: value.directions || '',
      serviceAreas: Array.isArray(value.serviceAreas) ? value.serviceAreas.join(', ') : valueOrEmpty(draft.serviceAreas),
      serviceRadiusKm: value.serviceRadiusKm ?? current.serviceRadiusKm,
      travelFee: Number(value.travelFee ?? current.travelFee),
      excludedServiceAreas: Array.isArray(value.excludedServiceAreas) ? value.excludedServiceAreas.join(', ') : valueOrEmpty(draft.excludedServiceAreas),
      timezone: value.timezone || current.timezone,
      bookingStartDate: value.bookingStartDate?.slice(0, 10) || '',
      openingHours: value.workingHours?.length
        ? dayLabels.map((_, dayOfWeek) => {
            const row = value.workingHours.find((item) => item.dayOfWeek === dayOfWeek);
            return row
              ? {
                  dayOfWeek,
                  openTime: new Date(row.openTime).toISOString().slice(11, 16),
                  closeTime: new Date(row.closeTime).toISOString().slice(11, 16),
                  isClosed: row.isClosed,
                }
              : { ...defaultHours[dayOfWeek], isClosed: true };
          })
        : current.openingHours,
      bookingConfirmationMode: value.bookingConfirmationMode || current.bookingConfirmationMode,
      staffAssignmentMode: value.staffAssignmentMode || current.staffAssignmentMode,
      pendingHoldMinutes: value.pendingHoldMinutes ?? current.pendingHoldMinutes,
      ...(value.bookingPolicy || {}),
    }));
    const legacyWizard = draft.wizardVersion !== WIZARD_VERSION;
    const storedStep = Number(value.onboardingProgress?.currentStep) || 1;
    const normalizedStep = legacyWizard && storedStep > LEGACY_REMOVED_STEP ? storedStep - 1 : storedStep;
    const storedCompleted = Array.isArray(value.onboardingProgress?.completedSteps)
      ? value.onboardingProgress.completedSteps
      : [];
    const normalizedCompleted = legacyWizard
      ? storedCompleted
          .filter((item) => item !== LEGACY_REMOVED_STEP)
          .map((item) => item > LEGACY_REMOVED_STEP ? item - 1 : item)
      : storedCompleted;
    setStep(Math.max(1, Math.min(steps.length, normalizedStep)));
    setCompletedSteps([...new Set(normalizedCompleted)].filter((item) => item >= 1 && item <= steps.length));
  };

  const refreshBranch = async (id = branchId) => {
    if (!id) return null;
    const value = await branchesApi.getAccessibleById(id);
    hydrateBranch(value);
    return value;
  };

  const load = async () => {
    setLoading(true);
    setError('');
    try {
      const [businessValue, branchRows, districtRows] = await Promise.all([
        businessApi.getMyOnboarding(),
        branchesApi.getAccessible(),
        branchesApi.getDistricts(),
      ]);
      setBusiness(businessValue);
      setBranches(branchRows);
      setDistricts(districtRows);
      if (routeBranchId) await refreshBranch(routeBranchId);
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải trình thiết lập chi nhánh');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { void load(); }, [routeBranchId]);
  useEffect(() => {
    const warn = (event) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  useEffect(() => {
    if (!sourceBranchId) {
      setSourceServices([]);
      setSelectedServices([]);
      return;
    }
    servicesApi.getManage(sourceBranchId)
      .then((rows) => setSourceServices(Array.isArray(rows) ? rows : rows?.items || []))
      .catch((requestError) => toast.error(requestError.message));
  }, [sourceBranchId]);

  useEffect(() => {
    if (!branchId || step !== 6) return;
    combosApi.getAll({ branchId })
      .then((rows) => setCombos(Array.isArray(rows) ? rows : []))
      .catch((requestError) => toast.error(requestError.message));
  }, [branchId, step]);

  useEffect(() => {
    if (!branchId || step !== 7) return;
    staffApi.getAll()
      .then((rows) => setStaff(Array.isArray(rows) ? rows : []))
      .catch((requestError) => toast.error(requestError.message));
  }, [branchId, step]);

  const set = (key) => (event) => {
    const value = event?.target?.type === 'checkbox'
      ? event.target.checked
      : event?.target?.value ?? event;
    setForm((current) => ({ ...current, [key]: value }));
    setDirty(true);
  };

  const validationForStep = (target) => {
    const messages = [];
    if (target === 1 && form.sameLegalEntity === null) messages.push('Xác nhận quan hệ pháp nhân.');
    if (target === 2) {
      if (!form.name.trim()) messages.push('Nhập tên chi nhánh.');
      if (!form.publicName.trim()) messages.push('Nhập tên hiển thị công khai.');
      if (!form.phone.trim() && !form.email.trim()) messages.push('Nhập ít nhất số điện thoại hoặc email.');
    }
    if (target === 3 && form.serviceMode !== 'MOBILE') {
      if (!form.districtId) messages.push('Chọn quận/huyện.');
      if (!form.addressLine.trim()) messages.push('Nhập địa chỉ chi tiết.');
    }
    if (target === 3 && form.serviceMode !== 'AT_LOCATION' && !splitAreas(form.serviceAreas).length) {
      messages.push('Nhập ít nhất một khu vực phục vụ.');
    }
    if (target === 4 && !form.openingHours.some((item) => !item.isClosed)) {
      messages.push('Chi nhánh phải có ít nhất một ngày mở cửa.');
    }
    if (target === 8 && (Number(form.pendingHoldMinutes) < 5 || Number(form.pendingHoldMinutes) > 1440)) {
      messages.push('Thời gian giữ lịch phải từ 5 đến 1440 phút.');
    }
    return messages;
  };

  const branchPatch = () => ({
    name: form.name.trim() || undefined,
    publicName: form.publicName.trim() || undefined,
    phone: form.phone.trim() || undefined,
    email: form.email.trim() || undefined,
    description: form.description.trim() || undefined,
    managerName: form.managerName.trim() || undefined,
    scheduledOpeningDate: form.scheduledOpeningDate || undefined,
    districtId: form.districtId || undefined,
    ward: form.ward.trim() || undefined,
    addressLine: form.addressLine.trim() || undefined,
    floor: form.floor.trim() || undefined,
    directions: form.directions.trim() || undefined,
    serviceMode: form.serviceMode,
    serviceAreas: splitAreas(form.serviceAreas),
    serviceRadiusKm: Number(form.serviceRadiusKm) || undefined,
    travelFee: Number(form.travelFee) || 0,
    excludedServiceAreas: splitAreas(form.excludedServiceAreas),
    timezone: form.timezone,
    bookingStartDate: form.bookingStartDate || undefined,
    bookingConfirmationMode: form.bookingConfirmationMode,
    staffAssignmentMode: form.staffAssignmentMode,
    pendingHoldMinutes: Number(form.pendingHoldMinutes),
  });

  const save = async ({ nextStep = step, markComplete = false, silent = false } = {}) => {
    if (!business?.id) {
      if (!silent) toast.error('Không tìm thấy doanh nghiệp đã xác minh');
      return null;
    }
    setSaving(true);
    try {
      let id = branchId;
      if (!id) {
        const result = await branchesApi.createDraft({
          businessId: business.id,
          sameLegalEntity: form.sameLegalEntity,
          serviceMode: form.serviceMode,
          name: form.name.trim() || undefined,
        });
        if (result.requiresNewBusiness) {
          navigate(result.redirectTo, { replace: true });
          return null;
        }
        id = result.branch.id;
        setBranch(result.branch);
        navigate(`/salon/branches/${id}/setup`, { replace: true });
      }
      const nextCompleted = markComplete
        ? [...new Set([...completedSteps, step])].sort((left, right) => left - right)
        : completedSteps;
      await branchesApi.saveOnboarding(id, {
        currentStep: nextStep,
        completedSteps: nextCompleted,
        branch: branchPatch(),
        draftData: {
          wizardVersion: WIZARD_VERSION,
          serviceAreas: form.serviceAreas,
          excludedServiceAreas: form.excludedServiceAreas,
        },
        ...(step === 4 ? { workingHours: form.openingHours } : {}),
        ...(step === 8 ? {
          bookingPolicy: {
            leadTimeMinutes: Number(form.leadTimeMinutes),
            bookingHorizonDays: Number(form.bookingHorizonDays),
            cancellationHours: Number(form.cancellationHours),
            rescheduleHours: Number(form.rescheduleHours),
            noShowHandling: form.noShowHandling,
            earlyCheckInMinutes: Number(form.earlyCheckInMinutes),
            gracePeriodMinutes: Number(form.gracePeriodMinutes),
            allowWalkIn: Boolean(form.allowWalkIn),
            allowCounterBooking: Boolean(form.allowCounterBooking),
            defaultBufferMinutes: Number(form.defaultBufferMinutes),
            overbookingEnabled: Boolean(form.overbookingEnabled),
            maxOverbookedSlots: form.overbookingEnabled ? Number(form.maxOverbookedSlots) : 0,
          },
        } : {}),
      });
      setCompletedSteps(nextCompleted);
      setDirty(false);
      await refreshBranch(id);
      if (!silent) toast.success('Đã lưu bước thiết lập');
      return id;
    } catch (requestError) {
      if (!silent) toast.error(requestError.message);
      return null;
    } finally {
      setSaving(false);
    }
  };

  const next = async () => {
    const messages = validationForStep(step);
    setErrors(messages);
    if (messages.length) return;
    if (step === 1 && form.sameLegalEntity === false) {
      navigate('/register/business');
      return;
    }
    const nextStep = Math.min(steps.length, step + 1);
    const saved = await save({ nextStep, markComplete: true, silent: true });
    if (saved) {
      setStep(nextStep);
      setErrors([]);
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  const copyServices = async () => {
    if (!branchId || !sourceBranchId || !selectedServices.length) return;
    setSaving(true);
    try {
      await branchesApi.copyServices(branchId, sourceBranchId, selectedServices);
      toast.success('Đã sao chép dịch vụ ở trạng thái chưa kích hoạt để bạn kiểm tra');
      setSelectedServices([]);
      await refreshBranch();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setSaving(false);
    }
  };

  const assignStaff = async (staffId) => {
    setSaving(true);
    try {
      await staffApi.assignBranch(staffId, {
        branchId,
        startDate: today(),
        isBookable: false,
      });
      toast.success('Đã gán nhân viên vào chi nhánh; hãy cấu hình dịch vụ và lịch trước khi bật nhận đặt lịch');
      await refreshBranch();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setSaving(false);
    }
  };

  const attachDocument = async (document, index) => {
    setSaving(true);
    try {
      await branchesApi.attachDocument(branchId, {
        mediaId: document.media.id,
        documentType: document.documentType,
        documentName: document.documentName || document.media.originalName,
        documentNumber: document.documentNumber || undefined,
        issuedAt: document.issuedAt || undefined,
        expiresAt: document.expiresAt || undefined,
        replaceDocumentId: document.replaceDocumentId || undefined,
      });
      setPendingDocuments((current) => current.filter((_, itemIndex) => itemIndex !== index));
      if (document.replaceDocumentId) setReplacementTarget(null);
      toast.success('Đã gắn tài liệu private vào hồ sơ chi nhánh');
      await refreshBranch();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setSaving(false);
    }
  };

  const previewDocument = async (document) => {
    const mediaId = document.versions?.[0]?.media?.id;
    if (!mediaId) return toast.error('Tài liệu chưa có phiên bản để xem');
    try {
      const blob = await mediaApi.download(mediaId);
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank', 'noopener,noreferrer');
      window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (requestError) {
      toast.error(requestError.message || 'Không thể mở tài liệu');
    }
  };

  const archiveDocument = async (document) => {
    if (!window.confirm(`Xóa "${document.versions?.[0]?.documentName || document.documentType}" khỏi hồ sơ nháp?`)) return;
    setSaving(true);
    try {
      await branchesApi.archiveDocument(branchId, document.id);
      await refreshBranch();
    } catch (requestError) {
      toast.error(requestError.message || 'Không thể xóa tài liệu');
    } finally {
      setSaving(false);
    }
  };

  const submit = async () => {
    setSaving(true);
    try {
      await save({ nextStep: steps.length, markComplete: true, silent: true });
      await branchesApi.submit(branchId);
      toast.success('Đã gửi hồ sơ chi nhánh xét duyệt; chi nhánh chưa được công khai');
      navigate('/salon/profile');
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setSaving(false);
    }
  };

  const progress = Math.round((completedSteps.length / steps.length) * 100);
  const otherBranches = branches.filter((item) => item.id !== branchId);
  const staffAtBranch = staff.filter((item) =>
    item.branch?.id === branchId ||
    branch?.staffAssignments?.some((assignment) => assignment.staffId === item.id),
  );
  const candidateStaff = staff.filter((item) => !staffAtBranch.some((assigned) => assigned.id === item.id));
  const readiness = branch?.readiness;
  const documentEditable = ['DRAFT', 'NEED_MORE_INFO'].includes(branch?.reviewStatus || 'DRAFT');

  if (loading) return <Page><Card className="p-5"><Skeleton rows={10} /></Card></Page>;
  if (error) return <Page><Card><ErrorState message={error} onRetry={load} /></Card></Page>;

  return (
    <Page className="branch-wizard max-w-[1500px]">
      <PageHeader
        eyebrow="Thiết lập chi nhánh"
        title={branch ? branch.name : 'Thêm chi nhánh mới'}
        description="Biểu mẫu được lưu nháp theo từng bước. Chi nhánh chỉ nhận đặt lịch sau khi hồ sơ được duyệt và đủ điều kiện vận hành."
        actions={branch ? (
          <div className="flex flex-wrap gap-2">
            <Badge tone={reviewTone[branch.reviewStatus]}>{statusLabel(branch.reviewStatus)}</Badge>
            <Badge tone={operationalTone[branch.operationalStatus]}>{branch.operationalStatus}</Badge>
            <Button variant="secondary" loading={saving} onClick={() => save()}>
              <Save size={16} />Lưu nháp
            </Button>
          </div>
        ) : null}
      />

      <div className="branch-wizard__layout">
        <aside className="branch-wizard__rail">
          <div className="mb-4">
            <div className="flex items-center justify-between text-xs font-bold">
              <span>Tiến độ hồ sơ</span><span>{progress}%</span>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-[var(--bb-surface-subtle)]">
              <div className="h-full bg-[var(--bb-brand)] transition-all" style={{ width: `${progress}%` }} />
            </div>
          </div>
          <ol className="space-y-1">
            {steps.map(([label, Icon], index) => {
              const number = index + 1;
              const done = completedSteps.includes(number);
              return (
                <li key={label}>
                  <button
                    type="button"
                    disabled={!branch && number > 1}
                    onClick={() => setStep(number)}
                    aria-current={number === step ? 'step' : undefined}
                    className={`branch-wizard__step ${number === step ? 'is-active' : ''}`}
                  >
                    <span>{done ? <Check size={16} /> : <Icon size={16} />}</span>
                    <span>{number}. {label}</span>
                  </button>
                </li>
              );
            })}
          </ol>
        </aside>

        <section className="min-w-0 space-y-4">
          {errors.length > 0 && (
            <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4">
              <strong>Hoàn thiện trước khi tiếp tục:</strong>
              <ul className="mt-2 list-disc pl-5 text-sm">{errors.map((message) => <li key={message}>{message}</li>)}</ul>
            </div>
          )}

          {branch?.reviewStatus === 'NEED_MORE_INFO' && branch.reviewNote && (
            <InlineNotice tone="warning">
              <strong>Quản trị nền tảng yêu cầu bổ sung:</strong> {branch.reviewNote}
            </InlineNotice>
          )}

          <Card className="p-5 sm:p-6">
            <header className="mb-6">
              <p className="text-xs font-bold uppercase tracking-[0.15em] text-[var(--bb-brand-strong)]">Bước {step}/{steps.length}</p>
              <h2 className="mt-2 text-2xl font-bold">{steps[step - 1][0]}</h2>
            </header>

            {step === 1 && (
              <div className="space-y-6">
                <fieldset>
                  <legend className="font-bold">Cơ sở này có cùng chủ sở hữu hoặc cùng pháp nhân với doanh nghiệp hiện tại?</legend>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {[
                      [true, 'Có, cùng hệ thống doanh nghiệp', 'Tiếp tục tạo chi nhánh và quản lý tập trung.'],
                      [false, 'Không, pháp nhân độc lập', 'Chuyển sang đăng ký Business mới; không tạo nháp chi nhánh sai mô hình.'],
                    ].map(([value, label, description]) => (
                      <button
                        key={String(value)}
                        type="button"
                        onClick={() => { setForm((current) => ({ ...current, sameLegalEntity: value })); setDirty(true); }}
                        className={`rounded-xl border p-4 text-left ${form.sameLegalEntity === value ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)]' : 'border-[var(--bb-border)]'}`}
                      >
                        <strong>{label}</strong>
                        <span className="mt-1 block text-sm text-[var(--bb-muted)]">{description}</span>
                      </button>
                    ))}
                  </div>
                </fieldset>
                <Field label="Mô hình phục vụ">
                  <Select value={form.serviceMode} onChange={set('serviceMode')}>
                    <option value="AT_LOCATION">Địa điểm cố định</option>
                    <option value="MOBILE">Dịch vụ tại nhà</option>
                    <option value="BOTH">Tại cơ sở và tại nhà</option>
                  </Select>
                </Field>
                {form.sameLegalEntity === false && (
                  <InlineNotice tone="warning">Hệ thống sẽ không tạo Branch. Bước tiếp theo dẫn tới luồng đăng ký Business mới.</InlineNotice>
                )}
              </div>
            )}

            {step === 2 && (
              <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Tên chi nhánh" required><Input value={form.name} onChange={set('name')} /></Field>
                <Field label="Tên hiển thị công khai" required><Input value={form.publicName} onChange={set('publicName')} /></Field>
                <Field label="Số điện thoại"><Input value={form.phone} onChange={set('phone')} /></Field>
                <Field label="Email"><Input type="email" value={form.email} onChange={set('email')} /></Field>
                <Field label="Người phụ trách"><Input value={form.managerName} onChange={set('managerName')} placeholder="Chủ doanh nghiệp có thể tạm quản lý" /></Field>
                <Field label="Ngày dự kiến khai trương"><Input type="date" value={form.scheduledOpeningDate} onChange={set('scheduledOpeningDate')} /></Field>
                <Field label="Mô tả" className="sm:col-span-2"><Textarea value={form.description} onChange={set('description')} /></Field>
              </div>
            )}

            {step === 3 && (
              <div className="space-y-5">
                {form.serviceMode !== 'MOBILE' && (
                  <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Quận/huyện" required>
                      <Select value={form.districtId} onChange={set('districtId')}>
                        <option value="">Chọn khu vực</option>
                        {districts.map((district) => <option key={district.id} value={district.id}>{district.province.name} · {district.name}</option>)}
                      </Select>
                    </Field>
                    <Field label="Phường/xã"><Input value={form.ward} onChange={set('ward')} /></Field>
                    <Field label="Địa chỉ chi tiết" required className="sm:col-span-2"><Input value={form.addressLine} onChange={set('addressLine')} /></Field>
                    <Field label="Tầng/phòng"><Input value={form.floor} onChange={set('floor')} /></Field>
                    <Field label="Hướng dẫn đường"><Input value={form.directions} onChange={set('directions')} /></Field>
                  </div>
                )}
                {form.serviceMode !== 'AT_LOCATION' && (
                  <div className="grid gap-4 rounded-xl border border-[var(--bb-border)] p-4 sm:grid-cols-2">
                    <Field label="Khu vực phục vụ" required hint="Ngăn cách bằng dấu phẩy"><Input value={form.serviceAreas} onChange={set('serviceAreas')} /></Field>
                    <Field label="Bán kính (km)"><Input type="number" min="1" value={form.serviceRadiusKm} onChange={set('serviceRadiusKm')} /></Field>
                    <Field label="Phí di chuyển"><Input type="number" min="0" value={form.travelFee} onChange={set('travelFee')} /></Field>
                    <Field label="Khu vực không hỗ trợ"><Input value={form.excludedServiceAreas} onChange={set('excludedServiceAreas')} /></Field>
                  </div>
                )}
              </div>
            )}

            {step === 4 && (
              <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field label="Múi giờ"><Input value={form.timezone} onChange={set('timezone')} /></Field>
                  <Field label="Bắt đầu nhận lịch"><Input type="date" value={form.bookingStartDate} onChange={set('bookingStartDate')} /></Field>
                </div>
                <InlineNotice tone="info">Giờ hoạt động của chi nhánh khác với lịch làm định kỳ của từng nhân viên.</InlineNotice>
                <div className="space-y-2">
                  {form.openingHours.map((row, index) => (
                    <div key={row.dayOfWeek} className="grid items-center gap-3 rounded-xl border border-[var(--bb-border)] p-3 sm:grid-cols-[8rem_7rem_1fr_1fr]">
                      <strong>{dayLabels[row.dayOfWeek]}</strong>
                      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!row.isClosed} onChange={(event) => {
                        setForm((current) => ({
                          ...current,
                          openingHours: current.openingHours.map((item, itemIndex) => itemIndex === index ? { ...item, isClosed: !event.target.checked } : item),
                        }));
                        setDirty(true);
                      }} />Mở cửa</label>
                      <Input type="time" disabled={row.isClosed} value={row.openTime} onChange={(event) => {
                        setForm((current) => ({ ...current, openingHours: current.openingHours.map((item, itemIndex) => itemIndex === index ? { ...item, openTime: event.target.value } : item) }));
                        setDirty(true);
                      }} />
                      <Input type="time" disabled={row.isClosed} value={row.closeTime} onChange={(event) => {
                        setForm((current) => ({ ...current, openingHours: current.openingHours.map((item, itemIndex) => itemIndex === index ? { ...item, closeTime: event.target.value } : item) }));
                        setDirty(true);
                      }} />
                    </div>
                  ))}
                </div>
              </div>
            )}

            {step === 5 && (
              <div className="space-y-5">
                <InlineNotice tone="info">Dịch vụ sao chép luôn ở trạng thái chưa kích hoạt. Chủ doanh nghiệp cần kiểm tra lại giá, thời lượng và khả năng nhận lịch.</InlineNotice>
                <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
                  <Field label="Sao chép có chọn lọc từ chi nhánh">
                    <Select value={sourceBranchId} onChange={(event) => setSourceBranchId(event.target.value)}>
                      <option value="">Chọn chi nhánh nguồn</option>
                      {otherBranches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </Select>
                  </Field>
                  <div className="flex items-end pb-5"><Button variant="secondary" disabled={!selectedServices.length} loading={saving} onClick={copyServices}><Copy size={16} />Sao chép đã chọn</Button></div>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                  {sourceServices.map((service) => (
                    <label key={service.id} className="flex items-start gap-3 rounded-xl border border-[var(--bb-border)] p-3">
                      <input type="checkbox" checked={selectedServices.includes(service.id)} onChange={() => setSelectedServices((current) => current.includes(service.id) ? current.filter((id) => id !== service.id) : [...current, service.id])} />
                      <span><strong>{service.name}</strong><span className="block text-xs text-[var(--bb-muted)]">{Number(service.price).toLocaleString('vi-VN')}₫ · {service.durationMinutes} phút</span></span>
                    </label>
                  ))}
                </div>
                <div className="flex flex-wrap gap-3">
                  <Link to={`/salon/services${branchId ? `?branchId=${branchId}` : ''}`}><Button variant="secondary">Thiết lập dịch vụ từ đầu</Button></Link>
                  <Badge>{branch?.services?.length || 0} dịch vụ trong nhánh</Badge>
                </div>
              </div>
            )}

            {step === 6 && (
              <div className="space-y-4">
                <InlineNotice tone="info">Gói dịch vụ chỉ có thể bật khi mọi dịch vụ đang hoạt động, nhận lịch và có nhân viên phù hợp tại chi nhánh.</InlineNotice>
                {!combos.length ? <EmptyState title="Chưa có combo tại chi nhánh" description="Tạo combo ở trạng thái chưa kích hoạt, sau đó quay lại checklist để kiểm tra." /> : (
                  <div className="space-y-2">{combos.map((combo) => <div key={combo.id} className="flex items-center justify-between gap-3 rounded-xl border border-[var(--bb-border)] p-4"><div><strong>{combo.name}</strong><p className="mt-1 text-xs text-[var(--bb-muted)]">{combo.comboServices?.length || 0} dịch vụ</p></div><Badge tone={combo.status === 'ACTIVE' ? 'success' : 'neutral'}>{combo.status}</Badge></div>)}</div>
                )}
                <Link to={`/salon/combos${branchId ? `?branchId=${branchId}` : ''}`}><Button variant="secondary">Mở quản lý combo</Button></Link>
              </div>
            )}

            {step === 7 && (
              <div className="space-y-5">
                <InlineNotice tone="info">Hệ thống phân công nhân viên vào nhiều chi nhánh nhưng vẫn giữ một hồ sơ nhân sự duy nhất. Nhân viên mới được phân công chưa tự động nhận lịch.</InlineNotice>
                <div>
                  <h3 className="font-bold">Nhân viên đã gán</h3>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {staffAtBranch.map((item) => <div key={item.id} className="rounded-xl border border-[var(--bb-border)] p-4"><strong>{item.fullName}</strong><p className="mt-1 text-sm text-[var(--bb-muted)]">{item.position || 'Chưa có chức danh nghề nghiệp'}</p></div>)}
                  </div>
                </div>
                <div>
                  <h3 className="font-bold">Gán nhân viên hiện có</h3>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    {candidateStaff.map((item) => <div key={item.id} className="flex items-center justify-between gap-3 rounded-xl border border-[var(--bb-border)] p-4"><div><strong>{item.fullName}</strong><p className="text-xs text-[var(--bb-muted)]">{item.branch?.name}</p></div><Button size="sm" variant="secondary" loading={saving} onClick={() => assignStaff(item.id)}>Gán</Button></div>)}
                  </div>
                </div>
                <Link to="/salon/staff"><Button variant="secondary"><UserRound size={16} />Thêm nhân viên mới</Button></Link>
              </div>
            )}

            {step === 8 && (
              <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Field label="Xác nhận lịch hẹn"><Select value={form.bookingConfirmationMode} onChange={set('bookingConfirmationMode')}><option value="MANUAL_CONFIRMATION">Duyệt thủ công</option><option value="AUTO_CONFIRMATION">Tự động xác nhận</option></Select></Field>
                <Field label="Phân công nhân viên"><Select value={form.staffAssignmentMode} onChange={set('staffAssignmentMode')}><option value="AUTO_ASSIGN_IF_ANY_STAFF">Hệ thống chọn</option><option value="CUSTOMER_SELECTS_STAFF">Khách chọn</option><option value="MANUAL_ASSIGN_BY_RECEPTIONIST">Quầy phân công</option></Select></Field>
                <Field label="Giữ lịch chờ (phút)"><Input type="number" min="5" max="1440" value={form.pendingHoldMinutes} onChange={set('pendingHoldMinutes')} /></Field>
                <Field label="Lead time (phút)"><Input type="number" min="0" value={form.leadTimeMinutes} onChange={set('leadTimeMinutes')} /></Field>
                <Field label="Cho phép đặt trước tối đa (ngày)"><Input type="number" min="1" value={form.bookingHorizonDays} onChange={set('bookingHorizonDays')} /></Field>
                <Field label="Hủy trước (giờ)"><Input type="number" min="0" value={form.cancellationHours} onChange={set('cancellationHours')} /></Field>
                <Field label="Đổi lịch trước (giờ)"><Input type="number" min="0" value={form.rescheduleHours} onChange={set('rescheduleHours')} /></Field>
                <Field label="Grace period (phút)"><Input type="number" min="0" value={form.gracePeriodMinutes} onChange={set('gracePeriodMinutes')} /></Field>
                <Field label="Buffer mặc định (phút)"><Input type="number" min="0" value={form.defaultBufferMinutes} onChange={set('defaultBufferMinutes')} /></Field>
                <label className="flex items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-semibold"><input type="checkbox" checked={form.overbookingEnabled} onChange={set('overbookingEnabled')} />Bật overbooking có kiểm soát</label>
                <Field label="Số lịch ngoại lệ tối đa cùng khung giờ"><Input type="number" min="0" max="5" disabled={!form.overbookingEnabled} value={form.maxOverbookedSlots} onChange={set('maxOverbookedSlots')} /></Field>
                <Field label="Xử lý khách không đến" className="sm:col-span-2 lg:col-span-3"><Textarea value={form.noShowHandling} onChange={set('noShowHandling')} /></Field>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.allowWalkIn} onChange={set('allowWalkIn')} />Cho phép walk-in</label>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.allowCounterBooking} onChange={set('allowCounterBooking')} />Cho phép đặt tại quầy</label>
              </div>
            )}

            {step === 9 && (
              <div className="space-y-4">
                {!branchId ? <InlineNotice tone="warning">Lưu bước 1 trước khi tải ảnh.</InlineNotice> : (
                  <FileUpload
                    entityType="BRANCH_IMAGE"
                    entityId={branchId}
                    businessId={business?.id}
                    branchId={branchId}
                    value={branch?.images?.[0]?.media || null}
                    label="Avatar / cover / gallery chi nhánh"
                    onUploaded={() => refreshBranch()}
                    onRemoved={() => refreshBranch()}
                  />
                )}
                <InlineNotice tone="info">Chỉ media công khai xuất hiện ở gallery. Tài liệu KYC/private được tải ở bước kế tiếp và không có public URL.</InlineNotice>
              </div>
            )}

            {step === 10 && (
              <div className="space-y-5">
                {documentEditable ? <FileUpload
                  document
                  multiple={!replacementTarget}
                  entityType="BRANCH_DOCUMENT"
                  entityId={branchId}
                  businessId={business?.id}
                  branchId={branchId}
                  label={replacementTarget ? `Chọn phiên bản thay thế cho ${replacementTarget.versions?.[0]?.documentName || replacementTarget.documentType}` : 'Đính kèm tài liệu'}
                  onUploaded={(media) => setPendingDocuments((current) => [...current, {
                    media,
                    documentType: replacementTarget?.documentType || 'OPERATING_LICENSE',
                    documentName: media.originalName || replacementTarget?.versions?.[0]?.documentName || '',
                    documentNumber: replacementTarget?.documentNumber || '',
                    issuedAt: replacementTarget?.issuedAt?.slice?.(0, 10) || '',
                    expiresAt: replacementTarget?.expiresAt?.slice?.(0, 10) || '',
                    replaceDocumentId: replacementTarget?.id,
                  }])}
                /> : <InlineNotice tone="info">Hồ sơ đang được xét duyệt hoặc đã duyệt nên tài liệu chỉ có thể xem. Khi Admin yêu cầu bổ sung, chức năng thay thế sẽ được mở lại.</InlineNotice>}
                {replacementTarget && <InlineNotice tone="info">Tệp tiếp theo sẽ tạo phiên bản v{replacementTarget.currentVersion + 1}; phiên bản cũ vẫn được giữ trong nhật ký kiểm toán. <Button size="sm" variant="ghost" onClick={() => setReplacementTarget(null)}>Hủy thay thế</Button></InlineNotice>}
                {pendingDocuments.map((document, index) => (
                  <article key={document.media.id} className="grid gap-3 rounded-xl border border-[var(--bb-border)] p-4 sm:grid-cols-2">
                    <Field label="Tên file"><Input value={document.documentName} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, documentName: event.target.value } : item))} /></Field>
                    <Field label="Loại tài liệu"><Select value={document.documentType} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, documentType: event.target.value } : item))}><option value="OPERATING_LICENSE">Giấy phép hoạt động</option><option value="LOCATION_DOCUMENT">Tài liệu địa điểm</option><option value="SERVICE_LICENSE">Giấy phép dịch vụ</option><option value="FIRE_SAFETY">Phòng cháy chữa cháy</option><option value="OTHER">Khác</option></Select></Field>
                    <Field label="Số tài liệu"><Input value={document.documentNumber} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, documentNumber: event.target.value } : item))} /></Field>
                    <Field label="Ngày cấp"><Input type="date" value={document.issuedAt} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, issuedAt: event.target.value } : item))} /></Field>
                    <Field label="Ngày hết hạn"><Input type="date" value={document.expiresAt} onChange={(event) => setPendingDocuments((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, expiresAt: event.target.value } : item))} /></Field>
                    <div className="sm:col-span-2"><Button size="sm" loading={saving} onClick={() => attachDocument(document, index)}><FileCheck2 size={15} />Gắn vào hồ sơ</Button></div>
                  </article>
                ))}
                <div className="space-y-2">
                  {(branch?.documents || []).map((document) => <article key={document.id} className="grid gap-3 rounded-xl bg-[var(--bb-surface-subtle)] p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><strong className="truncate">{document.versions?.[0]?.documentName || documentLabel(document.documentType)}</strong><Badge>{document.status}</Badge><Badge tone="neutral">Phiên bản {document.currentVersion}</Badge></div><p className="mt-2 text-xs text-[var(--bb-muted)]">{documentLabel(document.documentType)} · {document.expiresAt ? `Hết hạn ${new Date(document.expiresAt).toLocaleDateString('vi-VN')}` : 'Không có ngày hết hạn'} · tải lên {new Date(document.versions?.[0]?.createdAt || document.createdAt).toLocaleDateString('vi-VN')}</p></div><div className="flex flex-wrap gap-2"><Button size="sm" variant="secondary" onClick={() => previewDocument(document)}><Eye size={15} />Xem</Button>{documentEditable && <><Button size="sm" variant="secondary" onClick={() => setReplacementTarget(document)}>Thay thế</Button><Button size="sm" variant="ghost" className="text-[var(--bb-danger)]" disabled={saving} onClick={() => archiveDocument(document)}><Trash2 size={15} />Xóa</Button></>}</div></article>)}
                </div>
              </div>
            )}

            {step === 11 && (
              <div className="space-y-4">
                <InlineNotice tone="info">Lịch hẹn chỉ sử dụng thông tin liên hệ và dịch vụ đã chọn.</InlineNotice>
                <InlineNotice tone="success">Không có dữ liệu nhạy cảm mới được thu thập ở bước này. Bạn có thể tiếp tục kiểm tra điều kiện vận hành.</InlineNotice>
              </div>
            )}

            {step === 12 && (
              <div className="space-y-5">
                <div className="overflow-hidden rounded-2xl border border-[var(--bb-border)]">
                  <HomeMedia
                    src={branch?.images?.[0]?.media?.url || null}
                    fallbackSrc={getBranchFallbackMedia(branch)}
                    alt={`Không gian ${form.publicName || form.name || 'chi nhánh BeautyBook'}`}
                    fallbackAlt={`Hình minh họa cho ${form.publicName || form.name || 'chi nhánh'} trên BeautyBook`}
                    ratio="16 / 9"
                    label="Ảnh chi nhánh"
                    className="w-full rounded-none border-0"
                  />
                  <div className="grid gap-4 p-5 md:grid-cols-[1fr_auto]">
                    <div><h3 className="text-2xl font-bold">{form.publicName || form.name}</h3><p className="mt-2 text-sm text-[var(--bb-muted)]">{form.description || 'Chưa có mô tả công khai.'}</p><p className="mt-3 font-semibold">{form.addressLine || splitAreas(form.serviceAreas).join(', ')}</p></div>
                    <Badge tone={reviewTone[branch?.reviewStatus]}>{statusLabel(branch?.reviewStatus || 'DRAFT')}</Badge>
                  </div>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  {(readiness?.reasons || []).map((reason) => <div key={reason} className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">{reason}</div>)}
                  {readiness?.ready && <InlineNotice tone="success">Các điều kiện vận hành đã hoàn tất. Sau khi hồ sơ được duyệt, chủ doanh nghiệp có thể bật nhận đặt lịch.</InlineNotice>}
                </div>
              </div>
            )}

            {step === 13 && (
              <div className="space-y-5">
                <InlineNotice tone="warning">Gửi duyệt không đồng nghĩa với công khai. Quản trị nền tảng chỉ duyệt hồ sơ; chủ doanh nghiệp vẫn phải hoàn thành các điều kiện vận hành và bấm “Bật nhận đặt lịch”.</InlineNotice>
                <dl className="grid gap-3 rounded-xl bg-[var(--bb-surface-subtle)] p-4 sm:grid-cols-3">
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Doanh nghiệp</dt><dd className="mt-1 font-bold">{business?.status}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Xét duyệt chi nhánh</dt><dd className="mt-1 font-bold">{statusLabel(branch?.reviewStatus)}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Nhận đặt lịch</dt><dd className="mt-1 font-bold">{statusLabel(branch?.operationalStatus)}</dd></div>
                </dl>
                <Button loading={saving} onClick={submit} disabled={!['DRAFT', 'NEED_MORE_INFO'].includes(branch?.reviewStatus)}>
                  <Send size={16} />Gửi duyệt chi nhánh
                </Button>
              </div>
            )}
          </Card>

          <div className="flex flex-wrap items-center justify-between gap-3">
            <Button variant="secondary" disabled={step === 1} onClick={() => setStep((current) => current - 1)}><ArrowLeft size={16} />Quay lại</Button>
            {step < steps.length && <Button loading={saving} onClick={next}>Lưu và tiếp tục <ArrowRight size={16} /></Button>}
          </div>
        </section>
      </div>
    </Page>
  );
}
