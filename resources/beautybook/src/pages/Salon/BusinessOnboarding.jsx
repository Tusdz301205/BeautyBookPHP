import { useEffect, useRef, useState } from 'react';
import {
  ArrowLeft,
  ArrowRight,
  BriefcaseBusiness,
  Check,
  CheckCircle2,
  FileText,
  Save,
  Store,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { businessApi } from '../../api/apiClient';
import { FileUpload } from '../../components/media/FileUpload';
import { documentLabel, statusLabel } from '../../utils/displayLabels';
import { documentChecklist } from '../../utils/registrationIntent';
import { useAuthStore } from '../../store/authStore';
import {
  BUSINESS_FIELD_LIMITS,
  businessErrorField,
  isInternalBusinessDraftName,
  normalizeBusinessPhone,
  toBusinessOnboardingError,
  validateBusinessIdentity,
  validateBusinessType,
  validateDocuments,
  validCompletedSteps,
} from '../../utils/businessOnboardingValidation';
import {
  Badge,
  Button,
  Card,
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

const steps = [
  ['Loại hình', BriefcaseBusiness],
  ['Thông tin pháp nhân', Store],
  ['Giấy tờ xác minh', FileText],
  ['Kiểm tra & gửi', CheckCircle2],
];

const identityDefaults = {
  name: '',
  slug: '',
  companyName: '',
  taxCode: '',
  identityCardNumber: '',
  contactEmail: '',
  contactPhone: '',
  addressLine: '',
  legalRepresentative: '',
  description: '',
};

const draftDefaults = {
  businessType: '',
  completedSteps: [],
};

const emptyDocument = () => ({
  documentType: 'BUSINESS_LICENSE',
  documentName: '',
  documentNumber: '',
  expiresAt: '',
  documentUrl: '',
  mediaId: '',
  media: null,
  note: '',
});

const statusTone = {
  DRAFT: 'neutral',
  PENDING_REVIEW: 'warning',
  NEED_MORE_INFO: 'warning',
  APPROVED: 'success',
  ACTIVE: 'success',
  REJECTED: 'danger',
};

const localKey = 'beautybook-business-onboarding-recovery-v2';
const defaultOnboardingConfig = { businessTypes: [], requiredDocuments: [], requirePhoneVerification: false, phoneVerified: true };

function slugify(value) {
  return value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/đ/g, 'd')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 70);
}

function readSafeRecovery(recoveryKey) {
  try {
    // Old recovery was shared by every account on this browser. Do not import it.
    window.localStorage.removeItem(localKey);
    const raw = window.localStorage.getItem(recoveryKey);
    if (!raw) return null;
    const recovery = JSON.parse(raw);
    if (!recovery || typeof recovery !== 'object') return null;
    const identity = Object.fromEntries(['name', 'slug', 'description'].map((key) => [
      key, typeof recovery.identity?.[key] === 'string' ? recovery.identity[key] : '',
    ]));
    const safe = {
      identity,
      draft: { ...draftDefaults, businessType: typeof recovery.draft?.businessType === 'string' ? recovery.draft.businessType : '' },
      step: 1,
    };
    window.localStorage.setItem(recoveryKey, JSON.stringify(safe));
    return safe;
  } catch {
    try { window.localStorage.removeItem(recoveryKey); } catch { /* Storage may be unavailable. */ }
    return null;
  }
}

function writeSafeRecovery(recoveryKey, identity, draft, step) {
  const safeIdentity = Object.fromEntries(['name', 'slug', 'description'].map((key) => [key, identity[key]]));
  try {
    window.localStorage.setItem(recoveryKey, JSON.stringify({ identity: safeIdentity, draft, step }));
  } catch {
    // Server persistence remains authoritative if browser storage is unavailable.
  }
}

function clearRecovery(recoveryKey) {
  try { window.localStorage.removeItem(recoveryKey); } catch { /* Server persistence does not depend on storage. */ }
}

function ChoiceGrid({ value, catalog, onChange, disabled = false }) {
  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-invalid={!value || undefined}>
      {catalog.map(({ code: optionValue, label }) => (
        <button
          key={optionValue}
          type="button"
          id={optionValue === catalog[0]?.code ? 'onboarding-businessType' : undefined}
          disabled={disabled}
          aria-pressed={value === optionValue}
          onClick={() => onChange(optionValue)}
          className={`min-h-14 rounded-xl border p-4 text-left text-sm font-bold transition ${
            value === optionValue
              ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]'
              : 'border-[var(--bb-border)] hover:bg-[var(--bb-surface-subtle)]'
          }`}
        >
          {label}
        </button>
      ))}
    </div>
  );
}

export default function BusinessOnboarding() {
  const account = useAuthStore((state) => state.user);
  const recoveryKey = `${localKey}:${account?.id || 'anonymous'}`;
  const [business, setBusiness] = useState(null);
  const [config, setConfig] = useState(defaultOnboardingConfig);
  const [identity, setIdentity] = useState(identityDefaults);
  const [draft, setDraft] = useState(draftDefaults);
  const [documents, setDocuments] = useState([emptyDocument()]);
  const [step, setStep] = useState(1);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [validationErrors, setValidationErrors] = useState({});
  const [dirty, setDirty] = useState(false);
  const [uploadsInProgress, setUploadsInProgress] = useState(0);
  const [awaitingStatus, setAwaitingStatus] = useState(false);
  const hydrated = useRef(false);
  const submitLock = useRef(false);
  const nextLock = useRef(false);
  const saveInFlight = useRef(null);
  const autoSaveTimer = useRef(null);
  const formRevision = useRef(0);
  const editable = !awaitingStatus && (!business || ['DRAFT', 'NEED_MORE_INFO'].includes(business.status));

  const hydrate = (value) => {
    setBusiness(value);
    if (!value) {
      setIdentity(identityDefaults);
      setDraft(draftDefaults);
      setDocuments([emptyDocument()]);
      setStep(1);
      setDirty(false);
      return;
    }
    const recoveredName = isInternalBusinessDraftName(value.name) ? '' : value.name || '';
    setIdentity(Object.fromEntries(Object.keys(identityDefaults).map((key) => {
      if (key === 'name') return [key, recoveredName];
      if (key === 'slug') return [key, recoveredName ? (value.slug || '') : ''];
      const fallback = key === 'contactEmail' ? account?.email
        : key === 'contactPhone' ? account?.phone
          : key === 'legalRepresentative' ? account?.fullName : '';
      return [key, value[key] ?? value.owner?.[key] ?? fallback ?? ''];
    })));
    setDraft({
      ...draftDefaults,
      businessType: value.onboardingData?.businessType || '',
      completedSteps: (value.onboardingData?.completedSteps || []).filter((item) => item <= 4),
    });
    setDocuments(value.legalDocuments?.length ? value.legalDocuments : [emptyDocument()]);
    setStep(Math.min(4, Math.max(1, Number(value.onboardingStep || 1))));
    setDirty(false);
  };

  const load = async ({ showLoading = true } = {}) => {
    if (showLoading) setLoading(true);
    setError('');
    const recovery = readSafeRecovery(recoveryKey);
    try {
      const [result, onboardingConfig] = await Promise.all([
        businessApi.getMyOnboarding(),
        businessApi.getOnboardingConfig(),
      ]);
      setConfig(onboardingConfig);
      hydrate(result);
      setAwaitingStatus(false);
      if (!result) {
        if (recovery) {
          setIdentity({ ...identityDefaults, ...(recovery.identity || {}) });
          setDraft({ ...draftDefaults, ...(recovery.draft || {}) });
          setStep(recovery.step);
        }
      } else {
        clearRecovery(recoveryKey);
      }
      hydrated.current = true;
      return result;
    } catch (requestError) {
      setError(toBusinessOnboardingError(requestError));
      return null;
    } finally {
      if (showLoading) setLoading(false);
    }
  };

  useEffect(() => { void load(); }, []);
  useEffect(() => {
    const warn = (event) => {
      if (!dirty) return;
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  const legalPayload = () => documents
    .filter((item) => item.documentName?.trim() && item.mediaId)
    .map((item) => ({
      documentType: item.documentType,
      documentName: item.documentName.trim(),
      documentNumber: item.documentNumber?.trim() || undefined,
      expiresAt: item.expiresAt || undefined,
      documentUrl: `/api/v1/media/${item.mediaId}/content`,
      mediaId: item.mediaId,
      note: item.note?.trim() || undefined,
    }));

  const payload = (nextStep, nextDraft) => ({
    ...Object.fromEntries(Object.entries(identity).map(([key, value]) => {
      if (typeof value !== 'string') return [key, value];
      const trimmed = value.trim();
      if (!trimmed) return [key, null];
      if (key === 'slug') return [key, trimmed.toLowerCase()];
      if (key === 'contactEmail') return [key, trimmed.toLowerCase()];
      if (key === 'contactPhone') return [key, normalizeBusinessPhone(trimmed)];
      return [key, trimmed];
    })),
    onboardingStep: nextStep,
    onboardingData: nextDraft,
    legalDocuments: legalPayload(),
  });

  const persist = async ({ nextStep = step, nextDraft = draft, silent = false, preserveBusy = false } = {}) => {
    if (saveInFlight.current) {
      await saveInFlight.current;
      return persist({ nextStep, nextDraft, silent, preserveBusy });
    }
    nextDraft = {
      ...nextDraft,
      completedSteps: validCompletedSteps(nextDraft.completedSteps || [], {
        1: validateBusinessType(nextDraft.businessType, config.businessTypes) ? { businessType: true } : {},
        2: errorsForStep(2),
        3: errorsForStep(3),
      }),
    };
    writeSafeRecovery(recoveryKey, identity, nextDraft, nextStep);
    const saveErrors = validateDocuments(documents, []);
    if (!business && (!identity.name.trim() || !identity.slug.trim())) saveErrors.name = 'Nhập tên thương hiệu và đường dẫn trước khi lưu hồ sơ.';
    if (uploadsInProgress > 0) saveErrors.upload = 'Đợi tải tệp hoàn tất trước khi lưu hồ sơ.';
    if (Object.keys(saveErrors).length) {
      if (!silent) { setValidationErrors(saveErrors); focusFirstError(saveErrors); }
      return null;
    }
    const savedRevision = formRevision.current;
    const save = (async () => {
      if (!preserveBusy) setBusy(silent ? 'autosave' : 'save');
      try {
        const currentPayload = payload(nextStep, nextDraft);
        const result = business
          ? await businessApi.updateDraft(business.id, currentPayload)
          : await businessApi.createDraft(currentPayload);
        if (!result?.id) throw new Error('Hệ thống chưa xác nhận đã lưu hồ sơ.');
        setBusiness((current) => ({
          ...current,
          ...result,
          onboardingData: nextDraft,
          onboardingStep: nextStep,
          legalDocuments: legalPayload(),
          owner: {
            ...current?.owner,
            companyName: identity.companyName,
            taxCode: identity.taxCode,
            identityCardNumber: identity.identityCardNumber,
          },
        }));
        if (savedRevision === formRevision.current) {
          setDirty(false);
          clearRecovery(recoveryKey);
        }
        if (!silent) toast.success('Đã lưu hồ sơ doanh nghiệp');
        return result;
      } catch (requestError) {
        const fieldError = businessErrorField(requestError);
        if (fieldError) {
          setValidationErrors((current) => ({ ...current, [fieldError.field]: fieldError.message }));
          focusFirstError({ [fieldError.field]: fieldError.message });
        }
        if (!silent) toast.error(toBusinessOnboardingError(requestError));
        return null;
      } finally {
        if (!preserveBusy) setBusy('');
      }
    })();
    saveInFlight.current = save;
    try {
      return await save;
    } finally {
      if (saveInFlight.current === save) saveInFlight.current = null;
    }
  };

  useEffect(() => {
    if (!hydrated.current || !business || !dirty || !editable || submitLock.current) return undefined;
    autoSaveTimer.current = window.setTimeout(() => {
      if (!submitLock.current) void persist({ silent: true });
    }, 900);
    return () => window.clearTimeout(autoSaveTimer.current);
  }, [identity, draft, documents, step, dirty, editable, business?.id]);

  const errorsForStep = (target) => {
    if (target === 1) {
      const message = validateBusinessType(draft.businessType, config.businessTypes);
      return message ? { businessType: message } : {};
    }
    if (target === 2) {
      const errors = validateBusinessIdentity(identity);
      if (config.requirePhoneVerification && !config.phoneVerified) {
        errors.phoneVerification = 'Số điện thoại tài khoản chưa được xác minh. Hãy xác minh trước khi gửi hồ sơ.';
      }
      return errors;
    }
    if (target === 3) return validateDocuments(documents, config.requiredDocuments);
    return {};
  };

  const collectErrors = (targets) => Object.assign({}, ...targets.map(errorsForStep));

  const focusFirstError = (errors) => {
    const firstField = Object.keys(errors)[0];
    if (!firstField) return;
      const targetStep = firstField === 'businessType' ? 1
        : ['upload', 'documentDetails', 'documentType', 'BUSINESS_LICENSE', 'OWNER_ID_CARD'].includes(firstField) ? 3 : 2;
    setStep(targetStep);
    window.setTimeout(() => {
      const fieldId = firstField === 'phoneVerification' ? 'contactPhone' : firstField;
      const element = document.getElementById(`onboarding-${fieldId}`)
        || document.querySelector(`[data-onboarding-field="${firstField}"]`);
      element?.focus?.();
    }, 0);
  };

  const next = async () => {
    if (nextLock.current || submitLock.current || (busy && busy !== 'autosave')) return;
    const errors = errorsForStep(step);
    setValidationErrors(errors);
    if (Object.keys(errors).length) {
      focusFirstError(errors);
      return;
    }
    const nextDraft = {
      ...draft,
      completedSteps: [...new Set([...(draft.completedSteps || []), step])].sort(),
    };
    formRevision.current += 1;
    setDraft(nextDraft);
    const nextStep = Math.min(4, step + 1);
    if (step === 1 && (!identity.name.trim() || isInternalBusinessDraftName(identity.name))) {
      setStep(nextStep);
      return;
    }
    nextLock.current = true;
    try {
      const saved = await persist({ nextStep, nextDraft, silent: true });
      if (saved?.id) setStep(nextStep);
      else toast.error('Chưa lưu được thay đổi. Nội dung vẫn còn trên biểu mẫu; hãy thử lưu lại.');
    } finally { nextLock.current = false; }
  };

  const submit = async () => {
    if (submitLock.current || nextLock.current || (busy && busy !== 'autosave')) return;
    submitLock.current = true;
    window.clearTimeout(autoSaveTimer.current);
    const errors = collectErrors([1, 2, 3]);
    if (uploadsInProgress > 0) errors.upload = 'Đợi tải tệp hoàn tất trước khi gửi hồ sơ.';
    if (Object.keys(errors).length) {
      setValidationErrors(errors);
      focusFirstError(errors);
      submitLock.current = false;
      return;
    }
    setBusy('submit');
    let submittedToServer = false;
    try {
      const nextDraft = {
        ...draft,
        completedSteps: [1, 2, 3],
      };
      formRevision.current += 1;
      if (saveInFlight.current) await saveInFlight.current;
      const saved = await persist({ nextStep: 4, nextDraft, silent: true, preserveBusy: true });
      if (!saved?.id) throw new Error('Chưa lưu được nội dung mới nhất. Kiểm tra kết nối rồi thử lại; hồ sơ chưa được gửi.');
      await businessApi.submit(saved.id);
      submittedToServer = true;
      setAwaitingStatus(true);
      const latest = await businessApi.getMyOnboarding();
      if (!latest) throw new Error('Không tìm thấy hồ sơ sau khi gửi. Tải lại để kiểm tra trạng thái.');
      hydrate(latest);
      setAwaitingStatus(false);
      toast.success(latest.status === 'NEED_MORE_INFO' ? 'Đã gửi lại hồ sơ' : 'Đã gửi hồ sơ xét duyệt');
    } catch (requestError) {
      if (!submittedToServer) {
        const fieldError = businessErrorField(requestError);
        if (fieldError) {
          const fieldErrors = { [fieldError.field]: fieldError.message };
          setValidationErrors(fieldErrors);
          focusFirstError(fieldErrors);
        }
      }
      toast.error(submittedToServer
        ? 'Hồ sơ đã gửi đến máy chủ nhưng chưa tải được trạng thái mới. Hãy tải lại trang để kiểm tra.'
        : toBusinessOnboardingError(requestError));
    } finally {
      setBusy('');
      submitLock.current = false;
    }
  };

  const updateIdentity = (key) => (event) => {
    const value = event.target.value;
    formRevision.current += 1;
    setIdentity((current) => ({
      ...current,
      [key]: value,
      ...(key === 'name' && (!current.slug || current.slug === slugify(current.name))
        ? { slug: slugify(value) }
        : {}),
    }));
    setValidationErrors((current) => {
      const next = { ...current };
      delete next[key];
      return next;
    });
    setDirty(true);
  };

  const updateDocument = (index, patch) => {
    formRevision.current += 1;
    setDocuments((current) => current.map((item, itemIndex) =>
      itemIndex === index ? { ...item, ...patch } : item,
    ));
    setValidationErrors((current) => {
      const next = { ...current };
      delete next.upload;
      delete next.documentDetails;
      delete next.documentType;
      delete next.BUSINESS_LICENSE;
      delete next.OWNER_ID_CARD;
      return next;
    });
    setDirty(true);
  };

  const completedSteps = validCompletedSteps(draft.completedSteps || [], {
    1: errorsForStep(1), 2: errorsForStep(2), 3: errorsForStep(3),
  });
  const completion = Math.round((completedSteps.length / 4) * 100);

  if (loading) return <Page><Card className="p-5"><Skeleton rows={8} /></Card></Page>;
  if (error) return <Page><Card><ErrorState message={error} onRetry={load} /></Card></Page>;

  return (
    <Page className="max-w-5xl">
      <PageHeader
        eyebrow="Xác minh doanh nghiệp"
        title="Đăng ký BeautyBook Business"
        description="Luồng này chỉ xác minh doanh nghiệp và pháp nhân lần đầu. Chi nhánh, dịch vụ và nhân sự sẽ được thiết lập riêng sau khi doanh nghiệp được duyệt."
        actions={editable ? (
          <Button variant="secondary" loading={busy === 'save'} disabled={Boolean(busy) || uploadsInProgress > 0} onClick={() => persist()}>
            <Save size={16} />Lưu & tiếp tục sau
          </Button>
        ) : null}
      />

      <Card className="p-5" aria-label="Giấy tờ cần nộp">
        <h2 className="font-bold">Giấy tờ cần chuẩn bị trước khi gửi hồ sơ</h2>
        <p className="mt-1 text-sm text-[var(--bb-muted)]">PDF, tối đa 10 MB mỗi tệp. Chọn đúng loại giấy tờ và nhập tên tài liệu. Giấy tờ chỉ được xem bởi người có quyền; mỗi lần thay tệp được lưu thành phiên bản mới.</p>
        {config.requiredDocuments.length ? (
          <ul className="mt-3 space-y-2">
            {documentChecklist(documents, config.requiredDocuments).map(({ type, complete }) => (
              <li key={type} className="flex flex-wrap items-center justify-between gap-2">
                <span>{documentLabel(type)}</span>
                <Badge tone={complete ? 'success' : 'warning'}>{complete ? 'Đã có tệp' : 'Chưa có tệp'}</Badge>
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-sm">Chính sách hiện tại không yêu cầu loại giấy tờ bắt buộc.</p>
        )}
        <p className="mt-3 text-xs text-[var(--bb-muted)]">Tải đủ tệp chưa đồng nghĩa với giấy tờ đã được xác minh.</p>
      </Card>

      {business && (
        <InlineNotice tone={business.status === 'REJECTED' ? 'danger' : business.status === 'NEED_MORE_INFO' ? 'warning' : 'info'}>
          <span className="flex flex-wrap items-center gap-2">
            Trạng thái doanh nghiệp
            <Badge tone={statusTone[business.status]}>{statusLabel(business.status)}</Badge>
            {business.reviewNote && <strong>Phản hồi: {business.reviewNote}</strong>}
          </span>
        </InlineNotice>
      )}

      {awaitingStatus ? (
        <Card className="p-6">
          <h2 className="text-xl font-bold">Hồ sơ đã gửi, đang chờ cập nhật trạng thái</h2>
          <p className="mt-2 text-sm text-[var(--bb-muted)]">Máy chủ đã nhận hồ sơ. Tải lại trạng thái trước khi thực hiện thao tác tiếp theo.</p>
          <Button className="mt-4" onClick={() => load()}>Tải lại trạng thái</Button>
        </Card>
      ) : !editable ? (
        <Card className="p-6">
          <h2 className="text-xl font-bold">{['APPROVED', 'ACTIVE'].includes(business?.status) ? 'Hồ sơ đã được phê duyệt' : business?.status === 'REJECTED' ? 'Hồ sơ đã bị từ chối' : business?.status === 'PENDING_REVIEW' ? 'Hồ sơ đang được xét duyệt' : 'Trạng thái hồ sơ doanh nghiệp'}</h2>
          <p className="mt-2 text-sm text-[var(--bb-muted)]">Theo dõi trạng thái và lịch sử xử lý hồ sơ bên dưới.</p>
          <ul className="mt-5 space-y-2 text-sm">
            {(business?.reviewEvents || []).map((event) => (
              <li key={event.id} className="rounded-xl bg-[var(--bb-surface-subtle)] p-3">
                <strong>{event.action === 'APPROVE' ? 'Phê duyệt' : event.action === 'REQUEST_INFO' ? 'Yêu cầu bổ sung' : event.action === 'REJECT' ? 'Từ chối' : 'Cập nhật hồ sơ'}</strong> · {event.fromStatus ? statusLabel(event.fromStatus) : '—'} → {statusLabel(event.toStatus)}
                <span className="ml-2 text-[var(--bb-muted)]">{new Date(event.createdAt).toLocaleString('vi-VN')}</span>
                {event.reason && <p className="mt-1 text-[var(--bb-muted)]">{event.reason}</p>}
              </li>
            ))}
          </ul>
        </Card>
      ) : (
        <>
          <Card className="overflow-hidden">
            <div className="flex items-center justify-between gap-4 border-b border-[var(--bb-border)] p-4">
              <div>
                <p className="text-sm font-bold">Bước {step}/4 · {steps[step - 1][0]}</p>
                <p className="mt-1 text-xs text-[var(--bb-muted)]">Tự động lưu khi hồ sơ đã được tạo</p>
              </div>
              <Badge tone="brand">{completion}%</Badge>
            </div>
            <nav className="overflow-x-auto p-3" aria-label="Các bước đăng ký doanh nghiệp">
              <ol className="flex min-w-max gap-2">
                {steps.map(([label, Icon], index) => {
                  const number = index + 1;
                  const done = completedSteps.includes(number);
                  const maxStep = Math.max(step, ...completedSteps.map((item) => item + 1));
                  return (
                    <li key={label}>
                      <button
                        type="button"
                        disabled={number > maxStep || busy === 'submit'}
                        aria-current={number === step ? 'step' : undefined}
                        onClick={() => setStep(number)}
                        className={`flex min-h-11 items-center gap-2 rounded-xl border px-3 text-sm font-bold disabled:opacity-40 ${
                          number === step
                            ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]'
                            : 'border-[var(--bb-border)]'
                        }`}
                      >
                        {done ? <Check size={15} /> : <Icon size={15} />}{number}. {label}
                      </button>
                    </li>
                  );
                })}
              </ol>
            </nav>
          </Card>

          {Object.keys(validationErrors).length > 0 && (
            <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4">
              <strong>Hoàn thiện các mục sau:</strong>
              <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">
                {Object.entries(validationErrors).map(([key, message]) => <li key={key}>{message}</li>)}
              </ul>
            </div>
          )}

          <Card className="p-5 sm:p-6">
            {step === 1 && (
              <div>
                <h2 className="font-bold">Doanh nghiệp hoạt động trong lĩnh vực nào?</h2>
                <p className="mt-1 text-sm text-[var(--bb-muted)]">Thông tin này dùng để phân loại hồ sơ, không tự tạo chi nhánh hoặc dịch vụ.</p>
                <div className="mt-4">
                  <ChoiceGrid
                    value={draft.businessType}
                    catalog={config.businessTypes}
                    disabled={busy === 'submit'}
                    onChange={(businessType) => {
                      formRevision.current += 1;
                      setDraft((current) => ({ ...current, businessType }));
                      setValidationErrors((current) => { const next = { ...current }; delete next.businessType; return next; });
                      setDirty(true);
                    }}
                  />
                  {validationErrors.businessType && <p role="alert" className="mt-2 text-sm text-[var(--bb-danger)]">{validationErrors.businessType}</p>}
                </div>
              </div>
            )}

            {step === 2 && (
              <div className="grid gap-4 sm:grid-cols-2">
                {[
                  ['name', 'Tên thương hiệu'],
                  ['slug', 'Đường dẫn BeautyBook'],
                  ['companyName', 'Tên pháp nhân'],
                  ['taxCode', 'Mã số thuế'],
                  ['identityCardNumber', 'Số CCCD người đại diện'],
                  ['contactEmail', 'Email liên hệ'],
                  ['contactPhone', 'Điện thoại'],
                  ['addressLine', 'Địa chỉ đăng ký'],
                  ['legalRepresentative', 'Người đại diện'],
                ].map(([key, label]) => (
                  <Field key={key} label={label} required={key !== 'identityCardNumber'} error={validationErrors[key] || (key === 'contactPhone' ? validationErrors.phoneVerification : undefined)}>
                    <Input
                      id={`onboarding-${key}`}
                      type={key === 'contactEmail' ? 'email' : 'text'}
                      inputMode={key === 'contactPhone' ? 'tel' : undefined}
                      maxLength={BUSINESS_FIELD_LIMITS[key]}
                      autoComplete={key === 'contactEmail' ? 'email' : key === 'contactPhone' ? 'tel' : undefined}
                      value={identity[key]}
                      disabled={busy === 'submit'}
                      onChange={updateIdentity(key)}
                    />
                  </Field>
                ))}
                <Field label="Giới thiệu doanh nghiệp" className="sm:col-span-2" error={validationErrors.description}>
                  <Textarea id="onboarding-description" maxLength={BUSINESS_FIELD_LIMITS.description} value={identity.description} disabled={busy === 'submit'} onChange={updateIdentity('description')} />
                </Field>
              </div>
            )}

            {step === 3 && (
              <div>
                <h2 className="font-bold">Giấy tờ xác minh pháp nhân</h2>
                <p className="mt-1 text-sm text-[var(--bb-muted)]">Tệp private, đúng tenant và mỗi lần thay thế sẽ tạo một version mới.</p>
                <div className="mt-4 space-y-4">
                  {documents.map((document, index) => (
                    <article key={document.id || index} className="grid gap-3 rounded-xl border border-[var(--bb-border)] p-4 sm:grid-cols-2">
                      <Field label="Loại tài liệu" required error={validationErrors.documentType}>
                        <Select
                          data-onboarding-field="documentType"
                          value={document.documentType}
                          disabled={busy === 'submit'}
                          onChange={(event) => updateDocument(index, { documentType: event.target.value })}
                        >
                          <option value="BUSINESS_LICENSE">Giấy phép kinh doanh</option>
                          <option value="OWNER_ID_CARD">CCCD người đại diện</option>
                          <option value="TAX_DOCUMENT">Tài liệu thuế</option>
                          <option value="OTHER">Tài liệu khác</option>
                        </Select>
                      </Field>
                      <Field label="Tên tài liệu" required error={validationErrors.documentDetails}>
                        <Input data-onboarding-field="documentDetails" maxLength={255} value={document.documentName || ''} disabled={busy === 'submit'} onChange={(event) => updateDocument(index, { documentName: event.target.value })} />
                      </Field>
                      <Field label="Số tài liệu">
                        <Input maxLength={100} value={document.documentNumber || ''} disabled={busy === 'submit'} onChange={(event) => updateDocument(index, { documentNumber: event.target.value })} />
                      </Field>
                      <Field label="Ngày hết hạn">
                        <Input type="date" value={document.expiresAt || ''} disabled={busy === 'submit'} onChange={(event) => updateDocument(index, { expiresAt: event.target.value })} />
                      </Field>
                      <div id={`onboarding-${document.documentType}`} data-onboarding-field={document.documentType} tabIndex={-1} className="sm:col-span-2">
                        <div data-onboarding-field="upload" tabIndex={-1}>
                          <FileUpload
                            document
                            disabled={busy === 'submit'}
                            entityType="LEGAL_DOCUMENT"
                            entityId={business?.id}
                            businessId={business?.id}
                            value={document.media || (document.mediaId ? { id: document.mediaId, originalName: document.documentName } : null)}
                            onUploaded={(media) => updateDocument(index, {
                              media,
                              mediaId: media.id,
                              documentName: document.documentName || media.originalName,
                            })}
                            onBusyChange={(uploading) => setUploadsInProgress((count) => Math.max(0, count + (uploading ? 1 : -1)))}
                            onRemoved={() => updateDocument(index, { media: null, mediaId: '' })}
                          />
                        </div>
                        {(validationErrors.upload || validationErrors[document.documentType]) && (
                          <p role="alert" className="mt-2 text-sm text-[var(--bb-danger)]">
                            {validationErrors.upload || validationErrors[document.documentType]}
                          </p>
                        )}
                      </div>
                      {document.status && <Badge tone={document.status === 'APPROVED' ? 'success' : 'neutral'}>{statusLabel(document.status)} · phiên bản {document.currentVersion || 1}</Badge>}
                    </article>
                  ))}
                  <Button type="button" variant="secondary" onClick={() => {
                    formRevision.current += 1;
                    setDocuments((current) => [...current, emptyDocument()]);
                    setDirty(true);
                  }}>
                    Thêm tài liệu
                  </Button>
                </div>
              </div>
            )}

            {step === 4 && (
              <div className="space-y-5">
                <InlineNotice tone="info">Gửi duyệt chỉ xác minh doanh nghiệp. Hệ thống chưa tạo chi nhánh và chưa công khai nhận đặt lịch.</InlineNotice>
                <dl className="grid gap-3 rounded-xl bg-[var(--bb-surface-subtle)] p-4 sm:grid-cols-2">
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Loại hình dịch vụ</dt><dd className="mt-1 font-bold">{config.businessTypes.find((item) => item.code === draft.businessType)?.label || 'Chưa chọn'}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Thương hiệu</dt><dd className="mt-1 font-bold">{identity.name}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Pháp nhân</dt><dd className="mt-1 font-bold">{identity.companyName}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Mã số thuế</dt><dd className="mt-1 font-bold">{identity.taxCode}</dd></div>
                  <div><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">Tài liệu hợp lệ</dt><dd className="mt-1 font-bold">{legalPayload().length}</dd></div>
                  {[
                    ['slug', 'Đường dẫn BeautyBook'], ['contactEmail', 'Email liên hệ'], ['contactPhone', 'Điện thoại liên hệ'],
                    ['legalRepresentative', 'Người đại diện'], ['addressLine', 'Địa chỉ đăng ký'], ['description', 'Giới thiệu'],
                  ].map(([key, label]) => <div key={key} className="min-w-0"><dt className="text-xs font-bold uppercase text-[var(--bb-muted)]">{label}</dt><dd className="mt-1 break-words font-bold">{identity[key] || 'Chưa cập nhật'}</dd></div>)}
                </dl>
                <Button onClick={submit} loading={busy === 'submit'}>
                  Gửi hồ sơ doanh nghiệp <ArrowRight size={16} />
                </Button>
              </div>
            )}
          </Card>

          <div className="flex justify-between gap-3">
            <Button variant="secondary" disabled={step === 1} onClick={() => setStep((current) => current - 1)}>
              <ArrowLeft size={16} />Quay lại
            </Button>
            {step < 4 && <Button onClick={next}>Lưu và tiếp tục <ArrowRight size={16} /></Button>}
          </div>
        </>
      )}
    </Page>
  );
}
