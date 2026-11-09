import { useEffect, useRef, useState } from 'react';
import { bookingsApi, mediaApi, resultPhotosApi } from '../../api/apiClient';
import { useAsyncResource } from '../../hooks/useAsyncResource';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, ErrorState, Field, Input, Select, Skeleton, Textarea } from '../ui';

const images = 'image/jpeg,image/png,image/webp';
const photoState = (photo) => photo.revoked ? 'Đã thu hồi' : photo.visibility === 'PUBLIC'
  ? 'Đã bật công khai' : photo.capturedAt ? 'Đã ghi đồng ý' : 'Chờ ghi đồng ý';

function ResultPhotoForm({ serviceId, scope, busy, run, onClose, onCreated }) {
  const [mode, setMode] = useState('BOOKING');
  const [resultFile, setResultFile] = useState(null);
  const [proofFile, setProofFile] = useState(null);
  const [mediaId, setMediaId] = useState('');
  const [proofId, setProofId] = useState('');
  const [businessWide, setBusinessWide] = useState(false);
  const [reuse, setReuse] = useState(false);
  const [reference, setReference] = useState('');
  const [bookingId, setBookingId] = useState('');
  const [booking, setBooking] = useState(null);
  const [itemId, setItemId] = useState('');
  const items = booking?.bookingServices?.filter((item) => String(item.serviceId) === String(serviceId)) || [];

  const verifyBooking = () => run(async () => {
    const row = await bookingsApi.getById(bookingId);
    const matches = row.bookingServices?.filter((item) => String(item.serviceId) === String(serviceId)) || [];
    if (String(row.branchId) !== String(scope.branchId) || !row.customerId || !matches.length) {
      throw new Error('Booking cần có khách hàng và đúng lượt dịch vụ tại chi nhánh đang chọn.');
    }
    return { row, item: matches[0] };
  }, ({ row, item }) => { setBooking(row); setItemId(item.id); });

  const submit = (event) => {
    event.preventDefault();
    void run(async (isCurrent) => {
      if (mode === 'BOOKING' && (!booking || !itemId)) throw new Error('Kiểm tra booking và chọn lượt dịch vụ đã mua.');
      if (mode === 'EXTERNAL' && (!reference.trim() || (!proofFile && !proofId))) throw new Error('Nhập định danh và tải bằng chứng riêng tư.');
      if (!mediaId && (!resultFile || reuse)) throw new Error('Tải ảnh kết quả hoặc nhập ID tệp private đã có.');
      let storedMedia = mediaId;
      let storedProof = proofId;
      if (!storedMedia) {
        storedMedia = (await resultPhotosApi.upload(resultFile, 'RESULT', scope, businessWide)).id;
        if (!isCurrent()) return null;
        setMediaId(storedMedia);
      }
      if (!storedProof && proofFile) {
        storedProof = (await resultPhotosApi.upload(proofFile, 'CONSENT_PROOF', scope)).id;
        if (!isCurrent()) return null;
        setProofId(storedProof);
      }
      const provenance = mode === 'BOOKING'
        ? { bookingId: booking.id, bookingServiceId: itemId }
        : { consentSubjectReference: reference.normalize('NFC').trim() };
      return resultPhotosApi.create({ serviceId, mediaId: storedMedia, ...provenance, ...(storedProof ? { proofMediaId: storedProof } : {}) }, scope);
    }, onCreated);
  };

  return <Card as="form" className="mt-4 space-y-4 p-4" onSubmit={submit}>
    <h3 className="font-bold">Tạo hồ sơ ảnh riêng tư</h3>
    <fieldset disabled={busy} className="space-y-4">
      <Field label="Nguồn đồng ý">
        <Select value={mode} onChange={(event) => setMode(event.target.value)}>
          <option value="BOOKING">Từ lịch hẹn</option><option value="EXTERNAL">Người được xác định ngoài lịch hẹn</option>
        </Select>
      </Field>
      {mode === 'BOOKING' ? <div className="space-y-2">
        <Field label="ID booking" required><Input type="number" min="1" value={bookingId} onChange={(event) => { setBookingId(event.target.value); setBooking(null); setItemId(''); }} /></Field>
        <Button type="button" variant="secondary" disabled={!bookingId || busy} onClick={verifyBooking}>Kiểm tra lịch hẹn</Button>
        {booking && <Field label={'Lượt đã mua · ' + booking.bookingCode}><Select value={itemId} onChange={(event) => setItemId(event.target.value)}>
          {items.map((item) => <option key={item.id} value={item.id}>{item.service?.name || 'Dịch vụ'} · #{item.id}</option>)}
        </Select></Field>}
      </div> : <Field label="Định danh người đồng ý" required>
        <Textarea required rows={2} maxLength={1000} value={reference} onChange={(event) => setReference(event.target.value)} />
      </Field>}
      <label className="flex min-h-11 items-center gap-2 text-sm">
        <input type="checkbox" checked={reuse} onChange={(event) => { setReuse(event.target.checked); setMediaId(''); setResultFile(null); }} /> Dùng lại tệp ảnh riêng tư theo ID
      </label>
      {reuse ? <Field label="ID tệp ảnh" required><Input type="number" min="1" required value={mediaId} onChange={(event) => setMediaId(event.target.value)} /></Field>
        : <div>
          <Field label="Tệp ảnh kết quả" hint="JPG, PNG hoặc WebP, tối đa 8 MB."><Input type="file" accept={images} onChange={(event) => { setResultFile(event.target.files?.[0]); setMediaId(''); }} /></Field>
          <label className="mt-1 flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" checked={businessWide} disabled={Boolean(mediaId)} onChange={(event) => setBusinessWide(event.target.checked)} /> Cho phép liên kết tệp tại nhiều chi nhánh của doanh nghiệp
          </label>
        </div>}
      <Field label="Tệp bằng chứng đồng ý" required={mode === 'EXTERNAL'} hint="PDF tối đa 10 MB hoặc ảnh tối đa 8 MB.">
        <Input type="file" accept={images + ',application/pdf'} onChange={(event) => { setProofFile(event.target.files?.[0]); setProofId(''); }} />
      </Field>
    </fieldset>
    <div className="flex flex-wrap gap-2">
      <Button type="button" variant="secondary" disabled={busy} onClick={onClose}>Hủy tạo</Button>
      <Button type="submit" loading={busy}>Lưu hồ sơ riêng tư</Button>
    </div>
  </Card>;
}

export function ResultPhotoPanel({ serviceId, businessId, branchId, onBusyChange }) {
  const scope = { businessId, branchId };
  const actor = useAuthStore((state) => state.user?.id);
  const [page, setPage] = useState(1);
  const { data, error: loadError, loading, reload } = useAsyncResource(
    actor ? [actor, businessId, branchId, serviceId, page].join(':') : null,
    () => resultPhotosApi.list(serviceId, page, scope),
  );
  const [creating, setCreating] = useState(false);
  const [selectedId, setSelectedId] = useState(null);
  const [decision, setDecision] = useState(null);
  const [preview, setPreview] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [date, setDate] = useState('');
  const [source, setSource] = useState('');
  const [version, setVersion] = useState('');
  const [acknowledged, setAcknowledged] = useState(false);
  const pending = useRef(false);
  const mounted = useRef(false);
  const errorMessage = useRef(null);
  const decisionHeading = useRef(null);
  const photo = data?.data?.find((row) => row.id === selectedId);

  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);
  useEffect(() => { if (error) errorMessage.current?.focus(); }, [error]);
  useEffect(() => {
    const heading = decisionHeading.current;
    if (!decision || !heading) return;
    heading.focus({ preventScroll: true });
    heading.scrollIntoView({ block: 'center', inline: 'nearest' });
  }, [decision]);

  const run = async (work, success) => {
    if (pending.current || !actor) return;
    pending.current = true;
    setBusy(true); setError(''); setNotice('');
    onBusyChange(true);
    const current = () => mounted.current && useAuthStore.getState().user?.id === actor;
    try {
      const value = await work(current);
      if (current()) success(value);
    } catch (problem) {
      if (current()) setError(problem.message || 'Chưa thể cập nhật hồ sơ ảnh.');
    } finally {
      pending.current = false;
      if (mounted.current) { setBusy(false); onBusyChange(false); }
    }
  };

  const updated = (message) => { setDecision(null); setNotice(message); reload(); };
  const select = (id) => {
    setSelectedId(id); setDecision(null); setPreview(null); setError(''); setNotice('');
    setDate(''); setSource(''); setVersion(''); setAcknowledged(false);
  };
  const readUses = (action) => run(() => resultPhotosApi.uses(photo.mediaId, scope), ({ photos }) => {
    setDecision({ action, photos, photoVersion: photo.version });
  });
  const confirm = () => run(() => decision.action === 'PUBLISH'
    ? resultPhotosApi.publish({ mediaId: photo.mediaId, photos: decision.photos.map((row) => ({ id: row.id, expectedVersion: row.version })) }, scope)
    : resultPhotosApi.withdraw(photo.id, decision.photoVersion, scope),
  () => updated(decision.action === 'PUBLISH' ? 'Đã công khai các lượt dùng.' : 'Đã thu hồi quyền công khai của tệp.'));

  return <section className="mt-4 space-y-3" aria-label="Hồ sơ ảnh kết quả" aria-busy={busy}>
    <div className="flex flex-wrap items-center gap-2">
      <h3 className="mr-auto font-bold">Hồ sơ ảnh kết quả</h3>
      <Button type="button" disabled={busy || creating || loading || Boolean(loadError)} onClick={() => { select(null); setCreating(true); }}>Tạo hồ sơ</Button>
    </div>
    {notice && <p role="status" className="text-sm">{notice}</p>}
    {error && <p ref={errorMessage} tabIndex={-1} role="alert" className="text-sm text-[var(--bb-danger)]">{error}</p>}
    {creating ? <ResultPhotoForm serviceId={serviceId} scope={scope} busy={busy} run={run} onClose={() => setCreating(false)}
      onCreated={(row) => { setCreating(false); setSelectedId(row.id); setPage(1); updated('Đã lưu hồ sơ riêng tư.'); }} />
      : loading ? <Skeleton rows={3} /> : loadError ? <ErrorState message={loadError.message} onRetry={reload} />
        : <div className="space-y-2">
          {!data?.data?.length && <p className="text-sm text-[var(--bb-muted)]">Chưa có hồ sơ cho dịch vụ này.</p>}
          {data?.data?.map((row) => <div key={row.id} className="flex flex-wrap items-center gap-2 rounded-lg border border-[var(--bb-border)] p-2">
            <span className="mr-auto">Hồ sơ #{row.id}</span><Badge>{photoState(row)}</Badge>
            <Button type="button" size="sm" variant="secondary" disabled={busy} onClick={() => select(row.id)} aria-label={'Mở hồ sơ ' + row.id}>Mở hồ sơ</Button>
          </div>)}
          {data?.pagination?.totalPages > 1 && <div className="flex items-center gap-2">
            <Button type="button" size="sm" variant="secondary" disabled={busy || page <= 1} onClick={() => { select(null); setPage(page - 1); }}>Trang trước</Button>
            <span>{page}/{data.pagination.totalPages}</span>
            <Button type="button" size="sm" variant="secondary" disabled={busy || page >= data.pagination.totalPages} onClick={() => { select(null); setPage(page + 1); }}>Trang sau</Button>
          </div>}
        </div>}
    {photo && !creating && <Card className="space-y-3 p-4">
      <h4 className="font-bold">Chi tiết hồ sơ #{photo.id}</h4>
      <p className="break-words text-sm">{photo.bookingId ? 'Booking #' + photo.bookingId : photo.consentSubjectReference} · ID tệp ảnh {photo.mediaId}</p>
      <Button type="button" variant="secondary" disabled={busy} onClick={() => run(() => mediaApi.download(photo.mediaId), (blob) => setPreview(URL.createObjectURL(blob)))}>Xem ảnh riêng tư</Button>
      {preview && <img src={preview} alt="Ảnh kết quả riêng tư" className="max-h-48 max-w-full rounded-lg object-contain" />}
      {photo.proofMediaId && <Button type="button" variant="secondary" disabled={busy} onClick={() => run(() => mediaApi.download(photo.proofMediaId), (blob) => {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = blob.type === 'application/pdf' ? 'bang-chung-dong-y.pdf' : 'bang-chung-dong-y';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      })}>Tải bằng chứng riêng tư</Button>}
      {photo.capturedAt && <p className="break-words text-sm">Đồng ý {new Date(photo.capturedAt).toLocaleString('vi-VN')} · {photo.consentVersion} · {photo.consentSource}</p>}
      {!photo.revoked && photo.visibility !== 'PUBLIC' && <form className="space-y-3" onSubmit={(event) => {
        event.preventDefault();
        if (!acknowledged) return;
        void run(() => resultPhotosApi.capture(photo.id, { expectedVersion: photo.version,
          capturedAt: new Date(date).toISOString(), source: source.normalize('NFC').trim(), version: version.normalize('NFC').trim() }, scope),
        () => { setDate(''); setSource(''); setVersion(''); setAcknowledged(false); updated('Đã ghi nhận đồng ý.'); });
      }}>
        <fieldset className="space-y-2" disabled={busy}>
          <legend className="font-semibold">Ghi nhận đồng ý</legend>
          <Field label="Thời điểm đồng ý" hint="Giờ trên thiết bị của bạn." required><Input type="datetime-local" value={date} required onChange={(event) => setDate(event.target.value)} /></Field>
          <Field label="Nguồn đồng ý" required><Input value={source} maxLength={1000} required onChange={(event) => setSource(event.target.value)} /></Field>
          <Field label="Phiên bản đồng ý" required><Input value={version} maxLength={120} required onChange={(event) => setVersion(event.target.value)} /></Field>
          <label className="flex min-h-11 items-center gap-2 text-sm">
            <input type="checkbox" checked={acknowledged} onChange={(event) => setAcknowledged(event.target.checked)} /> Tôi xác nhận đã có sự đồng ý cho lượt dùng này
          </label>
          <Button type="submit" disabled={busy || !acknowledged}>Ghi nhận đồng ý</Button>
        </fieldset>
      </form>}
      <div className="flex flex-wrap gap-2">
        {photo.capturedAt && photo.visibility !== 'PUBLIC' && !photo.revoked && <Button type="button" disabled={busy} onClick={() => readUses('PUBLISH')}>Công khai ảnh</Button>}
        {!photo.revoked && <Button type="button" variant="secondary" disabled={busy} onClick={() => readUses('WITHDRAW')}>Thu hồi đồng ý</Button>}
        <Button type="button" variant="ghost" disabled={busy} onClick={() => { setDecision(null); reload(); }}>Tải lại hồ sơ</Button>
      </div>
      {decision && <section className="space-y-2 rounded-lg border border-[var(--bb-border)] p-3" aria-label="Xác nhận thao tác ảnh">
        <h5 ref={decisionHeading} tabIndex={-1} className="font-semibold">{decision.action === 'PUBLISH' ? 'Xác nhận công khai tệp' : 'Xác nhận thu hồi đồng ý'}</h5>
        <p className="text-sm">{decision.action === 'PUBLISH' ? 'Mọi lượt dùng chung tệp cần có đồng ý còn hiệu lực.' : 'Mọi lượt dùng bên dưới sẽ mất quyền đọc công khai.'}</p>
        <ul className="space-y-1 break-words text-sm">{decision.photos.map((row) => <li key={row.id}>
          #{row.id} · {row.serviceName || 'Dịch vụ #' + row.serviceId} · {row.revoked ? 'Đã thu hồi' : row.hasConsent ? 'Có đồng ý' : 'Thiếu đồng ý'}
        </li>)}</ul>
        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="secondary" disabled={busy} onClick={() => setDecision(null)}>Hủy xác nhận</Button>
          <Button type="button" loading={busy} disabled={decision.action === 'PUBLISH' && decision.photos.some((row) => row.revoked || !row.hasConsent)} onClick={confirm}>
            {decision.action === 'PUBLISH' ? 'Xác nhận công khai' : 'Xác nhận thu hồi'}
          </Button>
        </div>
      </section>}
    </Card>}
  </section>;
}
