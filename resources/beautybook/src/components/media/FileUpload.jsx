import React, { useEffect, useId, useRef, useState } from 'react';
import { FileCheck2, FileUp, Image, LoaderCircle, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { mediaApi } from '../../api/apiClient';
import { validateUploadedFile } from '../../utils/mediaValidation';
import { uploadedMediaUrl } from '../../utils/mediaUrl';
import { Button, cx } from '../ui';

export function FileUpload({
  entityType,
  entityId,
  businessId,
  branchId,
  value,
  onUploaded,
  onRemoved,
  onBusyChange,
  document = false,
  multiple = false,
  label = 'Tải tệp lên',
  append = false,
  disabled = false,
  className = '',
}) {
  const inputId = useId();
  const [busy, setBusy] = useState(false);
  const [preview, setPreview] = useState(uploadedMediaUrl(value?.url));
  const localPreview = useRef('');
  const inFlight = useRef(false);
  const scope = useRef(0);
  const clearLocalPreview = () => {
    if (localPreview.current) URL.revokeObjectURL(localPreview.current);
    localPreview.current = '';
  };
  useEffect(() => { if (!inFlight.current) setPreview(uploadedMediaUrl(value?.url)); }, [value?.url]);
  useEffect(() => {
    scope.current += 1;
    setPreview(uploadedMediaUrl(value?.url));
    return () => { scope.current += 1; clearLocalPreview(); };
  }, [entityType, entityId, businessId, branchId]);

  const upload = async (files) => {
    if (inFlight.current || disabled) return;
    const selected = [...(files || [])];
    if (!selected.length) return;
    const invalid = selected.map((file) => validateUploadedFile(file, { document })).find(Boolean);
    if (invalid) { toast.error(invalid); return; }
    if (!entityId) { toast.error('Hãy lưu hồ sơ trước khi tải tệp.'); return; }
    inFlight.current = true;
    const requestScope = scope.current;
    setBusy(true);
    onBusyChange?.(true);
    try {
      for (const file of selected) {
        if (!document) {
          clearLocalPreview();
          localPreview.current = URL.createObjectURL(file);
          setPreview(localPreview.current);
        }
        const media = await mediaApi.upload(file, { entityType, entityId, businessId, branchId });
        // Private documents have a persisted media ID but intentionally no public URL.
        if (!media?.id || (!document && !media?.url)) throw new Error('Tệp chưa được xác nhận lưu thành công.');
        if (scope.current !== requestScope) return;
        setPreview(document ? '' : uploadedMediaUrl(media.url));
        clearLocalPreview();
        onUploaded?.(media);
      }
      toast.success(document ? `Đã tải ${selected.length} tài liệu an toàn` : 'Đã tải ảnh lên');
    } catch (error) {
      if (scope.current === requestScope) { setPreview(uploadedMediaUrl(value?.url)); toast.error(error.message); }
    } finally { clearLocalPreview(); inFlight.current = false; setBusy(false); onBusyChange?.(false); }
  };

  const remove = async () => {
    if (inFlight.current || disabled) return;
    if (!value?.id) { setPreview(''); onRemoved?.(); return; }
    inFlight.current = true;
    const requestScope = scope.current;
    setBusy(true);
    onBusyChange?.(true);
    try { await mediaApi.remove(value.id); if (requestScope !== scope.current) return; setPreview(''); onRemoved?.(); toast.success('Đã gỡ tệp'); }
    catch (error) { toast.error(error.message); }
    finally { inFlight.current = false; setBusy(false); onBusyChange?.(false); }
  };

  return <div className={cx('rounded-[var(--bb-radius-card)] border border-dashed border-[var(--bb-border)] bg-[var(--bb-surface)] p-4', className)}>
    <input
      id={inputId}
      className="bb-sr-only"
      type="file"
      multiple={multiple}
      accept={document ? 'application/pdf,.pdf' : 'image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif'}
      disabled={disabled || busy}
      onChange={(event) => { upload(event.target.files); event.target.value = ''; }}
    />
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
      {preview && !document
        ? <img src={preview} alt={busy ? 'Ảnh đang tải lên' : label} width="80" height="80" className="h-20 w-20 rounded-xl object-cover" />
        : <span className="grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]">{document ? <FileCheck2 size={23} /> : <Image size={23} />}</span>}
      <div className="min-w-0 flex-1">
        <p className="text-sm font-bold">{value?.originalName || label}</p>
        <p className="mt-1 text-xs leading-5 text-[var(--bb-muted)]">{document ? `PDF tối đa 10 MB mỗi tệp. Tài liệu được lưu riêng tư.${multiple ? ' Có thể chọn nhiều tệp.' : ''}` : 'JPG, PNG, WebP hoặc AVIF; tối đa 10 MB.'}</p>
      </div>
      <div className="flex flex-wrap gap-2">
        <label htmlFor={inputId} aria-disabled={disabled || busy} className={cx('inline-flex min-h-11 items-center justify-center gap-2 whitespace-nowrap rounded-[var(--bb-radius-control)] border border-[var(--bb-border)] bg-white px-3 text-sm font-semibold', (disabled || busy) && 'cursor-not-allowed opacity-50')}>
          {busy ? <LoaderCircle size={16} className="animate-spin motion-reduce:animate-none" /> : <FileUp size={16} />}
          {append ? 'Thêm ảnh' : value ? 'Thay tệp' : 'Chọn tệp'}
        </label>
        {value && <Button type="button" variant="ghost" size="sm" disabled={disabled} loading={busy} onClick={remove} className="text-[var(--bb-danger)]"><Trash2 size={15} />Gỡ</Button>}
      </div>
    </div>
  </div>;
}
