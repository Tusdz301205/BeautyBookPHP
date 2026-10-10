import { useEffect, useMemo, useState } from 'react';
import { Building2, Clock3, Eye, ImagePlus, LayoutGrid, List, Pencil, Plus, Search, Sparkles, Trash2, Users } from 'lucide-react';
import toast from 'react-hot-toast';
import { branchesApi, mediaApi, servicesApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, Dialog, Drawer, EmptyState, ErrorState, Field, Input, MetricCard, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';
import { FileUpload } from '../../components/media/FileUpload';
import { HomeMedia } from '../../components/public/HomeMedia';
import { getServiceFallbackMedia } from '../../config/homeMedia';
import { uploadedMediaUrl } from '../../utils/mediaUrl';
import { normalizeNameSearch } from '../../utils/nameSearch';

const emptyForm = { name: '', description: '', price: '', durationMinutes: '', canonicalServiceId: '', keywords: '', status: 'ACTIVE', branchId: '' };
const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')} ₫`;
const availabilityLabel = { ACTIVE: 'Đang áp dụng', PAUSED: 'Tạm ngưng', NOT_APPLIED: 'Chưa áp dụng' };
const availabilityTone = { ACTIVE: 'success', PAUSED: 'warning', NOT_APPLIED: 'neutral' };

function ServiceFormDialog({ service, canonicals, branches, selectedBranch, open, onClose, onSaved }) {
  const editing = Boolean(service?.id);
  const [form, setForm] = useState(emptyForm);
  const [errors, setErrors] = useState({});
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setForm({
      name: service?.name || '', description: service?.description || '',
      price: service?.price ?? '', durationMinutes: service?.durationMinutes ?? '',
      canonicalServiceId: service?.canonicalServiceId || service?.canonicalService?.id || '',
      keywords: (service?.keywords || []).join(', '),
      status: service?.status || 'ACTIVE',
      branchId: selectedBranch !== 'ALL' ? selectedBranch : branches[0]?.id || '',
    });
    setErrors({});
  }, [open, service, branches, selectedBranch]);

  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const submit = async (event) => {
    event.preventDefault();
    const nextErrors = {};
    if (!form.name.trim()) nextErrors.name = 'Nhập tên dịch vụ.';
    if (!(Number(form.price) > 0)) nextErrors.price = 'Giá phải lớn hơn 0.';
    if (!(Number(form.durationMinutes) > 0)) nextErrors.durationMinutes = 'Thời lượng phải lớn hơn 0.';
    if (!editing && !form.branchId) nextErrors.branchId = 'Chọn chi nhánh áp dụng đầu tiên.';
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length) return;
    setSaving(true);
    try {
      const payload = {
        name: form.name.trim(), description: form.description.trim(),
        price: Number(form.price), durationMinutes: Number(form.durationMinutes),
        canonicalServiceId: form.canonicalServiceId || undefined,
        keywords: form.keywords.split(',').map((value) => value.trim()).filter(Boolean),
        ...(editing ? { status: form.status } : { branchId: form.branchId }),
      };
      if (editing) await servicesApi.updateCatalog(service.id, payload);
      else await servicesApi.createCatalog(payload);
      toast.success(editing ? 'Đã cập nhật dịch vụ' : 'Đã tạo và áp dụng dịch vụ');
      onSaved();
    } catch (error) {
      toast.error(error.message || 'Không thể lưu dịch vụ');
    } finally { setSaving(false); }
  };

  return <Dialog open={open} onClose={onClose} size="lg" title={editing ? 'Chỉnh sửa dịch vụ' : 'Thêm dịch vụ'} description="Nhập thông tin khách hàng cần thấy. Phạm vi áp dụng vẫn được quản lý riêng theo từng chi nhánh." footer={<><Button variant="secondary" onClick={onClose}>Hủy</Button><Button type="submit" form="business-service-form" loading={saving}>{editing ? 'Lưu thay đổi' : 'Thêm dịch vụ'}</Button></>}>
    <form id="business-service-form" onSubmit={submit} className="grid min-w-0 gap-4 sm:grid-cols-2">
      {!editing && <Field className="sm:col-span-2" label="Chi nhánh áp dụng đầu tiên" required error={errors.branchId}><Select value={form.branchId} onChange={set('branchId')}><option value="">Chọn chi nhánh</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</Select></Field>}
      <Field className="sm:col-span-2" label="Tên dịch vụ" required error={errors.name}><Input autoFocus value={form.name} onChange={set('name')} placeholder="Ví dụ: Chăm sóc da chuyên sâu" /></Field>
      <Field className="sm:col-span-2" label="Mô tả"><Textarea value={form.description} onChange={set('description')} placeholder="Mô tả ngắn để khách hiểu dịch vụ" /></Field>
      <Field label="Giá cơ sở (VNĐ)" required error={errors.price}><Input type="number" min="1" value={form.price} onChange={set('price')} /></Field>
      <Field label="Thời lượng cơ sở (phút)" required error={errors.durationMinutes}><Input type="number" min="1" value={form.durationMinutes} onChange={set('durationMinutes')} /></Field>
      <Field className="min-w-0 sm:col-span-2" label="Loại dịch vụ trên BeautyBook" hint="Chọn loại gần nhất để khách hàng dễ tìm thấy dịch vụ. Có thể để chưa phân loại."><Select searchable searchPlaceholder="Tìm loại dịch vụ" value={form.canonicalServiceId} onChange={set('canonicalServiceId')}><option value="">Chưa phân loại</option>{canonicals.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>
      <Field className="min-w-0 sm:col-span-2" label="Từ khóa bổ sung" hint="Phân cách bằng dấu phẩy"><Input value={form.keywords} onChange={set('keywords')} placeholder="da nhạy cảm, cấp ẩm, phục hồi" /></Field>
      {editing && <Field label="Trạng thái cấp doanh nghiệp"><Select value={form.status} onChange={set('status')}><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Tạm ngưng cấp doanh nghiệp</option></Select></Field>}
    </form>
  </Dialog>;
}

function ServiceMediaDialog({ service, onClose, onChanged }) {
  const offerings = (service?.branchAvailability || []).filter((item) => item.branchServiceId);
  const [branchId, setBranchId] = useState('');
  const branchService = offerings.find((item) => item.branchId === branchId) || offerings[0];
  const [images, setImages] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [removing, setRemoving] = useState('');
  const [refresh, setRefresh] = useState(0);
  useEffect(() => { setBranchId(''); }, [service?.id]);
  useEffect(() => {
    let active = true;
    setImages([]); setError('');
    if (!branchService?.branchServiceId) return () => { active = false; };
    setLoading(true);
    servicesApi.getManageOffering(branchService.branchServiceId)
      .then((detail) => { if (active) setImages(detail?.images || []); })
      .catch((requestError) => { if (active) setError(requestError.message || 'Không thể tải ảnh dịch vụ'); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [branchService?.branchServiceId, refresh]);
  const changed = () => { setRefresh((value) => value + 1); void onChanged?.(); };
  const remove = async (mediaId) => {
    if (removing) return;
    setRemoving(mediaId);
    try { await mediaApi.remove(mediaId); changed(); toast.success('Đã gỡ ảnh'); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setRemoving(''); }
  };
  return <Dialog open={Boolean(service)} onClose={onClose} title="Ảnh dịch vụ" description={service?.name || ''}>
    {offerings.length > 0 && <Field label="Chi nhánh áp dụng ảnh"><Select value={branchService?.branchId || ''} onChange={(event) => setBranchId(event.target.value)}>{offerings.map((item) => <option key={item.branchId} value={item.branchId}>{item.branchName}</option>)}</Select></Field>}
    {branchService ? loading ? <Skeleton rows={3} /> : error ? <ErrorState message={error} onRetry={() => setRefresh((value) => value + 1)} /> : <div className="space-y-4">
      <p className="text-sm text-[var(--bb-muted)]">Ảnh thuộc dịch vụ tại chi nhánh đã chọn. Thêm ảnh sẽ giữ lại các ảnh hiện có; ảnh đầu tiên được dùng làm đại diện.</p>
      <div className="grid grid-cols-2 gap-3">{images.map((image, index) => <figure key={image.id} className="min-w-0 rounded-xl border border-[var(--bb-border)] p-2"><img src={uploadedMediaUrl(image.media?.url)} alt={'Ảnh dịch vụ ' + (service?.name || '')} width="240" height="180" className="aspect-[4/3] w-full rounded-lg object-cover" /><figcaption className="mt-2 break-words text-xs">{index === 0 ? 'Ảnh đại diện' : 'Ảnh trong bộ sưu tập'}</figcaption><Button variant="ghost" size="sm" disabled={Boolean(removing)} onClick={() => remove(image.media.id)}>Gỡ ảnh</Button></figure>)}</div>
      <FileUpload key={branchService.branchServiceId} entityType="SERVICE_IMAGE" entityId={branchService.branchServiceId} businessId={service.businessId} branchId={branchService.branchId} append label="Thêm ảnh dịch vụ" onUploaded={changed} disabled={Boolean(removing)} />
    </div> : <p className="text-sm leading-6 text-[var(--bb-muted)]">Hãy áp dụng dịch vụ cho ít nhất một chi nhánh trước khi tải ảnh.</p>}
  </Dialog>;
}

function Coverage({ service, selectedBranch }) {
  if (selectedBranch !== 'ALL') {
    const item = service.branchAvailability?.[0];
    return <div className="mt-4 rounded-xl bg-[var(--bb-surface-subtle)] p-3 text-sm"><p className="font-semibold">{item?.branchName || 'Chi nhánh'}</p><Badge className="mt-2" tone={availabilityTone[item?.status]}>{availabilityLabel[item?.status] || 'Chưa áp dụng'}</Badge></div>;
  }
  const coverage = service.branchCoverage || {};
  const percent = coverage.total ? Math.round(((coverage.active || 0) / coverage.total) * 100) : 0;
  const activeNames = (service.branchAvailability || []).filter((item) => item.status === 'ACTIVE').map((item) => item.branchName);
  return <div className="mt-4 space-y-2 rounded-xl bg-[var(--bb-surface-subtle)] p-3 text-sm">
    <div className="flex items-center justify-between gap-3"><p className="font-semibold">Áp dụng: {coverage.active || 0}/{coverage.total || 0} chi nhánh</p><span className="text-xs font-bold text-[var(--bb-muted)]">{percent}%</span></div>
    <div className="h-1.5 overflow-hidden rounded-full bg-zinc-200" aria-label={`Phủ ${percent}% chi nhánh`}><div className="h-full rounded-full bg-[var(--bb-brand)] transition-[width]" style={{ width: `${percent}%` }} /></div>
    <div className="flex flex-wrap gap-2"><Badge tone="success">Hoạt động {coverage.active || 0}</Badge><Badge tone="warning">Tạm ngưng {coverage.paused || 0}</Badge><Badge>Chưa áp dụng {coverage.notApplied || 0}</Badge></div>
    {activeNames.length > 0 && <p className="line-clamp-2 text-xs text-[var(--bb-muted)]">{activeNames.slice(0, 2).join(', ')}{activeNames.length > 2 ? `, +${activeNames.length - 2}` : ''}</p>}
  </div>;
}

function ServiceActions({ service, selectedBranch, canCatalogEdit, canBranchEdit, onEdit, onDetail, onMedia, onAvailability, busy, showAvailability = true }) {
  const branchState = selectedBranch !== 'ALL' ? service.branchAvailability?.[0] : null;
  return <div className="flex flex-wrap gap-2">
    {canCatalogEdit && <Button size="sm" variant="secondary" onClick={() => onEdit(service)}><Pencil size={14} />Sửa</Button>}
    {showAvailability && canBranchEdit && branchState?.status === 'NOT_APPLIED' && <Button size="sm" loading={busy === `${service.id}-apply`} onClick={() => onAvailability(service, branchState, 'apply')}>Áp dụng</Button>}
    {showAvailability && canBranchEdit && branchState?.status === 'ACTIVE' && <Button size="sm" variant="secondary" loading={busy === `${service.id}-pause`} onClick={() => onAvailability(service, branchState, 'pause')}>Tạm ngưng tại chi nhánh</Button>}
    {showAvailability && canBranchEdit && branchState?.status === 'PAUSED' && <Button size="sm" loading={busy === `${service.id}-reactivate`} onClick={() => onAvailability(service, branchState, 'reactivate')}>Kích hoạt lại</Button>}
    {canCatalogEdit && <Button size="sm" variant="ghost" onClick={() => onMedia(service)}><ImagePlus size={14} />Ảnh</Button>}
    <Button size="sm" variant="ghost" onClick={() => onDetail(service)}><Eye size={14} />Chi tiết</Button>
  </div>;
}

function ServiceCard({ service, selectedBranch, actions }) {
  return <Card className={`flex min-h-72 flex-col border transition duration-200 hover:-translate-y-0.5 hover:shadow-md ${service.status === 'ACTIVE' ? 'border-zinc-200' : 'border-amber-200'} p-5`}>
    <HomeMedia src={uploadedMediaUrl(service.primaryImageUrl)} alt={`Ảnh dịch vụ ${service.name}`} fallbackSrc={getServiceFallbackMedia(service)} fallbackAlt={`Hình minh họa dịch vụ ${service.name}`} ratio="16 / 10" label="Ảnh dịch vụ" className="mb-4 w-full" />
    <div className="flex items-start justify-between gap-3"><span className="grid h-10 w-10 place-items-center rounded-lg bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><Sparkles size={18} /></span><Badge tone={service.status === 'ACTIVE' ? 'success' : 'neutral'}>{service.status === 'ACTIVE' ? 'Đang hoạt động' : 'Tạm ngưng cấp doanh nghiệp'}</Badge></div>
    <button type="button" className="mt-4 text-left" onClick={() => actions.onDetail(service)}><h2 className="font-bold text-[var(--bb-ink)]">{service.name}</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">{service.canonicalService?.name || 'Chưa phân loại'}</p></button>
    {service.description && <p className="mt-3 line-clamp-2 text-sm leading-6 text-[var(--bb-ink-soft)]">{service.description}</p>}
    <div className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-sm"><strong>{money(service.price)}</strong><span className="inline-flex items-center gap-1 text-[var(--bb-muted)]"><Clock3 size={15} />{service.durationMinutes} phút</span></div>
    <Coverage service={service} selectedBranch={selectedBranch} />
    <div className="mt-auto border-t border-[var(--bb-border)] pt-4"><ServiceActions service={service} selectedBranch={selectedBranch} showAvailability={false} {...actions} /></div>
  </Card>;
}

function ServicesTable({ services, selectedBranch, actions }) {
  return <Card className="overflow-x-auto"><table className="min-w-[980px] w-full text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase tracking-wide text-[var(--bb-muted)]"><tr><th className="px-4 py-3">Dịch vụ</th><th className="px-4 py-3">Giá</th><th className="px-4 py-3">Thời lượng</th>{selectedBranch === 'ALL' ? <><th className="px-4 py-3">Hoạt động</th><th className="px-4 py-3">Tạm ngưng</th><th className="px-4 py-3">Chưa áp dụng</th></> : <th className="px-4 py-3">Trạng thái tại chi nhánh</th>}<th className="px-4 py-3">Hành động</th></tr></thead><tbody className="divide-y divide-[var(--bb-border)]">{services.map((service) => { const branchState = service.branchAvailability?.[0]; return <tr key={service.id} className="align-top"><td className="px-4 py-4"><div className="flex min-w-[250px] items-center gap-3"><HomeMedia src={uploadedMediaUrl(service.primaryImageUrl)} alt={`Ảnh dịch vụ ${service.name}`} fallbackSrc={getServiceFallbackMedia(service)} fallbackAlt={`Hình minh họa dịch vụ ${service.name}`} ratio="1 / 1" label="Ảnh dịch vụ" className="w-16 shrink-0 overflow-hidden rounded-lg" /><div><button className="font-bold hover:text-[var(--bb-brand-strong)]" onClick={() => actions.onDetail(service)}>{service.name}</button><p className="mt-1 text-xs text-[var(--bb-muted)]">{service.canonicalService?.name || 'Chưa phân loại'}</p></div></div></td><td className="px-4 py-4 font-semibold tabular-nums">{money(service.price)}</td><td className="px-4 py-4">{service.durationMinutes} phút</td>{selectedBranch === 'ALL' ? <><td className="px-4 py-4">{service.branchCoverage?.active || 0}</td><td className="px-4 py-4">{service.branchCoverage?.paused || 0}</td><td className="px-4 py-4">{service.branchCoverage?.notApplied || 0}</td></> : <td className="px-4 py-4"><Badge tone={availabilityTone[branchState?.status]}>{availabilityLabel[branchState?.status]}</Badge></td>}<td className="px-4 py-4"><ServiceActions service={service} selectedBranch={selectedBranch} {...actions} /></td></tr>; })}</tbody></table></Card>;
}

const emptyVariant = { code: '', name: '', priceType: 'FIXED', price: '', maxPrice: '', durationMinutes: '', maxDurationMinutes: '', bufferBeforeMinutes: 0, bufferAfterMinutes: 0 };

function VariantManager({ offeringId, branchId }) {
  const [variants, setVariants] = useState([]);
  const [offerings, setOfferings] = useState([]);
  const [form, setForm] = useState(emptyVariant);
  const [dependency, setDependency] = useState({ requiredServiceId: '', dependencyType: 'ADD_ON' });
  const [rule, setRule] = useState({ name: '', adjustmentType: 'FIXED_AMOUNT', adjustmentValue: '', dayOfWeek: '', startTime: '', endTime: '' });
  const [busy, setBusy] = useState('');
  const load = () => Promise.all([servicesApi.getVariants(offeringId), servicesApi.getAll(branchId)]).then(([variantRows, serviceRows]) => { setVariants(variantRows || []); setOfferings((serviceRows?.data ?? serviceRows ?? []).filter((item) => item.id !== offeringId)); });
  useEffect(() => { void load().catch((error) => toast.error(error.message)); }, [offeringId, branchId]);
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }));
  const create = async (event) => { event.preventDefault(); setBusy('variant'); try { await servicesApi.createVariant(offeringId, { ...form, code: form.code.trim(), name: form.name.trim(), price: form.priceType === 'QUOTE' ? undefined : Number(form.price), maxPrice: form.maxPrice === '' ? undefined : Number(form.maxPrice), durationMinutes: Number(form.durationMinutes), maxDurationMinutes: form.maxDurationMinutes === '' ? undefined : Number(form.maxDurationMinutes), bufferBeforeMinutes: Number(form.bufferBeforeMinutes), bufferAfterMinutes: Number(form.bufferAfterMinutes) }); setForm(emptyVariant); await load(); toast.success('Đã thêm biến thể'); } catch (error) { toast.error(error.message); } finally { setBusy(''); } };
  const addDependency = async () => { setBusy('dependency'); try { await servicesApi.addDependency(offeringId, dependency); toast.success('Đã lưu quan hệ dịch vụ'); setDependency({ ...dependency, requiredServiceId: '' }); } catch (error) { toast.error(error.message); } finally { setBusy(''); } };
  const addRule = async () => { setBusy('rule'); try { await servicesApi.createPriceRule(offeringId, { name: rule.name, adjustmentType: rule.adjustmentType, adjustmentValue: Number(rule.adjustmentValue), conditions: { ...(rule.dayOfWeek !== '' ? { daysOfWeek: [Number(rule.dayOfWeek)] } : {}), ...(rule.startTime ? { startTime: rule.startTime } : {}), ...(rule.endTime ? { endTime: rule.endTime } : {}) } }); toast.success('Đã lưu quy tắc phụ thu'); setRule({ name: '', adjustmentType: 'FIXED_AMOUNT', adjustmentValue: '', dayOfWeek: '', startTime: '', endTime: '' }); } catch (error) { toast.error(error.message); } finally { setBusy(''); } };
  return <div className="mt-4 space-y-4 border-t border-[var(--bb-border)] pt-4"><div><h4 className="text-sm font-bold">Biến thể giá và thời lượng</h4>{variants.length ? <div className="mt-2 space-y-2">{variants.map((variant) => <div key={variant.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-[var(--bb-surface-subtle)] p-3 text-sm"><div><strong>{variant.name}</strong><p className="text-xs text-[var(--bb-muted)]">{variant.priceDisplay} · {variant.durationMinutes || 0}{variant.maxDurationMinutes ? `–${variant.maxDurationMinutes}` : ''} phút · buffer {variant.bufferBeforeMinutes}/{variant.bufferAfterMinutes}</p></div><Button size="sm" variant="secondary" onClick={async () => { await servicesApi.updateVariant(variant.id, { status: variant.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE' }); await load(); }}>{variant.status === 'ACTIVE' ? 'Tạm ngưng' : 'Kích hoạt'}</Button></div>)}</div> : <p className="mt-2 text-xs text-[var(--bb-muted)]">Chưa có biến thể; hệ thống dùng giá và thời lượng cơ sở.</p>}</div><form className="grid gap-3 sm:grid-cols-3" onSubmit={create}><Field label="Mã" required><Input value={form.code} onChange={set('code')} required /></Field><Field label="Tên biến thể" required><Input value={form.name} onChange={set('name')} required /></Field><Field label="Kiểu giá"><Select value={form.priceType} onChange={set('priceType')}><option value="FIXED">Giá cố định</option><option value="FROM">Giá từ</option><option value="RANGE">Khoảng giá</option><option value="QUOTE">Cần báo giá</option></Select></Field><Field label="Giá"><Input type="number" min="0" disabled={form.priceType === 'QUOTE'} value={form.price} onChange={set('price')} /></Field><Field label="Giá tối đa"><Input type="number" min="0" disabled={form.priceType !== 'RANGE'} value={form.maxPrice} onChange={set('maxPrice')} /></Field><Field label="Thời lượng"><Input type="number" min="1" value={form.durationMinutes} onChange={set('durationMinutes')} required /></Field><Field label="Thời lượng tối đa"><Input type="number" min="1" value={form.maxDurationMinutes} onChange={set('maxDurationMinutes')} /></Field><Field label="Buffer trước"><Input type="number" min="0" value={form.bufferBeforeMinutes} onChange={set('bufferBeforeMinutes')} /></Field><Field label="Buffer sau"><Input type="number" min="0" value={form.bufferAfterMinutes} onChange={set('bufferAfterMinutes')} /></Field><Button type="submit" loading={busy === 'variant'}>Thêm biến thể</Button></form><div className="grid gap-4 border-t border-[var(--bb-border)] pt-4 lg:grid-cols-2"><div><h4 className="text-sm font-bold">Add-on / dịch vụ bắt buộc</h4><div className="mt-2 grid gap-2 sm:grid-cols-2"><Select value={dependency.requiredServiceId} onChange={(event) => setDependency((current) => ({ ...current, requiredServiceId: event.target.value }))}><option value="">Chọn dịch vụ liên quan</option>{offerings.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select><Select value={dependency.dependencyType} onChange={(event) => setDependency((current) => ({ ...current, dependencyType: event.target.value }))}><option value="ADD_ON">Add-on</option><option value="REQUIRED">Bắt buộc đi kèm</option><option value="INCOMPATIBLE">Không dùng cùng</option></Select><Button disabled={!dependency.requiredServiceId} loading={busy === 'dependency'} onClick={addDependency}>Lưu quan hệ</Button></div></div><div><h4 className="text-sm font-bold">Phụ thu theo ngày/khung giờ</h4><div className="mt-2 grid gap-2 sm:grid-cols-2"><Input placeholder="Tên quy tắc" value={rule.name} onChange={(event) => setRule((current) => ({ ...current, name: event.target.value }))} /><Input type="number" placeholder="Mức phụ thu" value={rule.adjustmentValue} onChange={(event) => setRule((current) => ({ ...current, adjustmentValue: event.target.value }))} /><Select value={rule.dayOfWeek} onChange={(event) => setRule((current) => ({ ...current, dayOfWeek: event.target.value }))}><option value="">Mọi ngày</option>{['CN','T2','T3','T4','T5','T6','T7'].map((label, index) => <option key={label} value={index}>{label}</option>)}</Select><div className="flex gap-2"><Input type="time" value={rule.startTime} onChange={(event) => setRule((current) => ({ ...current, startTime: event.target.value }))} /><Input type="time" value={rule.endTime} onChange={(event) => setRule((current) => ({ ...current, endTime: event.target.value }))} /></div><Button disabled={!rule.name || !rule.adjustmentValue} loading={busy === 'rule'} onClick={addRule}>Lưu phụ thu</Button></div></div></div></div>;
}

function ServiceDrawer({ service, canBranchEdit, canCatalogEdit, busy, onClose, onEdit, onAvailability, onArchive }) {
  if (!service) return null;
  return <Drawer open onClose={onClose} title={service.name} description={`${service.canonicalService?.name || 'Chưa phân loại'} · ${money(service.price)} · ${service.durationMinutes} phút`} footer={canCatalogEdit && <><Button onClick={() => onEdit(service)}><Pencil size={15} />Sửa dịch vụ</Button>{onArchive && <Button variant="danger" onClick={() => onArchive(service)}><Trash2 size={15} />Lưu trữ</Button>}</>}><div className="space-y-6"><section><h3 className="font-bold">Thông tin chung</h3><p className="mt-2 text-sm leading-6 text-[var(--bb-muted)]">{service.description || 'Chưa có mô tả.'}</p><div className="mt-3 flex flex-wrap gap-2"><Badge tone={service.status === 'ACTIVE' ? 'success' : 'neutral'}>{service.status === 'ACTIVE' ? 'Hoạt động toàn doanh nghiệp' : 'Tạm ngưng toàn doanh nghiệp'}</Badge><Badge>Cập nhật {service.updatedAt ? new Date(service.updatedAt).toLocaleDateString('vi-VN') : '—'}</Badge></div></section><section><h3 className="font-bold">Chi nhánh áp dụng</h3><div className="mt-3 space-y-3">{(service.branchAvailability || []).map((item) => <Card key={item.branchId} className="p-4"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="font-bold">{item.branchName}</p><div className="mt-2 flex flex-wrap gap-2"><Badge tone={availabilityTone[item.status]}>{availabilityLabel[item.status]}</Badge>{item.status !== 'NOT_APPLIED' && <><Badge>{money(item.price)}</Badge><Badge>{item.durationMinutes} phút</Badge><Badge><Users size={12} className="mr-1 inline" />{item.staffCount} nhân viên</Badge><Badge>{item.futureBookingCount} lịch tương lai</Badge></>}</div></div>{canBranchEdit && <div>{item.status === 'NOT_APPLIED' ? <Button size="sm" loading={busy === `${service.id}-apply`} onClick={() => onAvailability(service, item, 'apply')}>Áp dụng cho chi nhánh</Button> : item.status === 'ACTIVE' ? <Button size="sm" variant="secondary" loading={busy === `${service.id}-pause`} onClick={() => onAvailability(service, item, 'pause')}>Tạm ngưng</Button> : <Button size="sm" loading={busy === `${service.id}-reactivate`} onClick={() => onAvailability(service, item, 'reactivate')}>Kích hoạt lại</Button>}</div>}</div>{canCatalogEdit && item.branchServiceId && item.status !== 'NOT_APPLIED' && <VariantManager offeringId={item.branchServiceId} branchId={item.branchId} />}</Card>)}</div></section></div></Drawer>;
}

export function SalonServices() {
  const can = useAuthStore((state) => state.can);
  const [services, setServices] = useState([]); const [branches, setBranches] = useState([]); const [canonicals, setCanonicals] = useState([]);
  const [loading, setLoading] = useState(true); const [error, setError] = useState(''); const [editor, setEditor] = useState(null); const [detail, setDetail] = useState(null); const [mediaTarget, setMediaTarget] = useState(null); const [archiveTarget, setArchiveTarget] = useState(null); const [busy, setBusy] = useState('');
  const [search, setSearch] = useState(''); const [serviceType, setServiceType] = useState('ALL'); const [status, setStatus] = useState('ALL'); const [branch, setBranch] = useState('ALL'); const [view, setView] = useState('grid');
  const canCreate = can('business_service:create:tenant');
  const canCatalogEdit = can('business_service:update:tenant');
  const canBranchEdit = can('branch_service_offering:status:tenant');
  const canArchive = can('business_service:archive:tenant');

  const loadReference = async () => { const [canonicalData, branchData] = await Promise.all([servicesApi.getCanonical(), branchesApi.getAccessible()]); setCanonicals(Array.isArray(canonicalData) ? canonicalData : canonicalData?.data || []); setBranches(Array.isArray(branchData) ? branchData : branchData?.data || []); };
  const loadServices = async (selected = branch) => { setLoading(true); setError(''); try { const data = await servicesApi.getWorkspace(selected); const rows = Array.isArray(data) ? data : data?.data || []; setServices(rows); setDetail((current) => current ? rows.find((item) => item.id === current.id) || null : null); } catch (loadError) { setError(loadError.message || 'Không thể tải không gian quản lý dịch vụ.'); } finally { setLoading(false); } };
  useEffect(() => { loadReference().catch((e) => setError(e.message)); }, []);
  useEffect(() => { loadServices(branch); }, [branch]);

  const filtered = useMemo(() => services.filter((service) => { const query = normalizeNameSearch(search); const branchState = service.branchAvailability?.[0]?.status; return (!query || normalizeNameSearch(service.name).includes(query)) && (serviceType === 'ALL' || (serviceType === 'UNCLASSIFIED' ? !service.canonicalServiceId : service.canonicalServiceId === serviceType)) && (status === 'ALL' || (branch === 'ALL' ? service.status === status : branchState === status)); }), [services, search, serviceType, status, branch]);
  const availability = async (service, item, action) => { setBusy(`${service.id}-${action}`); try { await servicesApi.setBranchAvailability(service.id, item.branchId, action); toast.success(action === 'pause' ? 'Đã tạm ngưng tại chi nhánh' : action === 'apply' ? 'Đã áp dụng cho chi nhánh' : 'Đã kích hoạt lại tại chi nhánh'); await loadServices(); } catch (e) { toast.error(e.message || 'Không thể cập nhật chi nhánh'); } finally { setBusy(''); } };
  const archive = async () => { if (!archiveTarget) return; setBusy(`archive-${archiveTarget.id}`); try { await servicesApi.archiveCatalog(archiveTarget.id); toast.success('Đã lưu trữ dịch vụ; lịch sử đặt lịch được giữ nguyên'); setArchiveTarget(null); setDetail(null); await loadServices(); } catch (e) { toast.error(e.message || 'Không thể lưu trữ dịch vụ'); } finally { setBusy(''); } };
  const actions = { canCatalogEdit, canBranchEdit, onEdit: setEditor, onDetail: setDetail, onMedia: setMediaTarget, onAvailability: availability, busy };
  const active = branch === 'ALL' ? services.filter((item) => item.status === 'ACTIVE').length : services.filter((item) => item.branchAvailability?.[0]?.status === 'ACTIVE').length;
  const paused = branch === 'ALL' ? services.reduce((sum, item) => sum + (item.branchCoverage?.paused || 0), 0) : services.filter((item) => item.branchAvailability?.[0]?.status === 'PAUSED').length;
  const notApplied = branch === 'ALL' ? services.reduce((sum, item) => sum + (item.branchCoverage?.notApplied || 0), 0) : services.filter((item) => item.branchAvailability?.[0]?.status === 'NOT_APPLIED').length;

  return <Page>
    <PageHeader eyebrow="Vận hành dịch vụ" title="Dịch vụ" description="Quản lý tên, giá, thời lượng, khả năng tìm thấy và phạm vi áp dụng tại các chi nhánh." actions={canCreate && <Button onClick={() => setEditor({})}><Plus size={17} />Thêm dịch vụ</Button>} />
    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"><MetricCard icon={Sparkles} label="Tổng dịch vụ" value={services.length} /><MetricCard icon={Building2} label="Đang hoạt động" value={active} tone="success" /><MetricCard icon={Clock3} label="Tạm ngưng" value={paused} tone="warning" /><MetricCard icon={Building2} label="Chưa áp dụng" value={notApplied} tone="neutral" /></div>
    <Card className="p-4"><div className="grid gap-3 lg:grid-cols-[minmax(220px,1fr)_220px_220px_180px_auto]"><Field label="Tìm dịch vụ"><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" size={16} /><Input className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Tên dịch vụ" /></div></Field><Field label="Chi nhánh"><Select value={branch} onChange={(e) => { setBranch(e.target.value); setStatus('ALL'); }}><option value="ALL">Tất cả chi nhánh</option>{branches.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field><Field label="Loại dịch vụ trên BeautyBook"><Select searchable searchPlaceholder="Tìm loại dịch vụ" value={serviceType} onChange={(e) => setServiceType(e.target.value)}><option value="ALL">Tất cả loại dịch vụ</option><option value="UNCLASSIFIED">Chưa phân loại</option>{canonicals.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field><Field label="Trạng thái"><Select value={status} onChange={(e) => setStatus(e.target.value)}><option value="ALL">Tất cả</option><option value="ACTIVE">Hoạt động</option>{branch === 'ALL' ? <option value="INACTIVE">Tạm ngưng cấp doanh nghiệp</option> : <><option value="PAUSED">Tạm ngưng</option><option value="NOT_APPLIED">Chưa áp dụng</option></>}</Select></Field><div className="flex items-end"><div className="flex rounded-xl border border-[var(--bb-border)] p-1"><Button size="icon" variant={view === 'grid' ? 'primary' : 'ghost'} aria-label="Xem dạng thẻ" onClick={() => setView('grid')}><LayoutGrid size={17} /></Button><Button size="icon" variant={view === 'table' ? 'primary' : 'ghost'} aria-label="Xem dạng bảng" onClick={() => setView('table')}><List size={17} /></Button></div></div></div></Card>
    {loading ? <Card className="p-5"><Skeleton rows={7} /></Card> : error ? <Card><ErrorState message={error} onRetry={() => loadServices()} /></Card> : filtered.length === 0 ? <Card><EmptyState icon={Sparkles} title="Không có dịch vụ phù hợp" description={services.length ? 'Thử đổi bộ lọc hoặc từ khóa.' : 'Chưa có dịch vụ trong phạm vi quản lý.'} action={canCreate && !services.length ? <Button onClick={() => setEditor({})}>Thêm dịch vụ đầu tiên</Button> : null} /></Card> : view === 'grid' ? <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{filtered.map((service) => <ServiceCard key={service.id} service={service} selectedBranch={branch} actions={actions} />)}</div> : <ServicesTable services={filtered} selectedBranch={branch} actions={actions} />}
    <ServiceFormDialog service={editor?.id ? editor : null} canonicals={canonicals} branches={branches} selectedBranch={branch} open={editor !== null} onClose={() => setEditor(null)} onSaved={async () => { setEditor(null); await loadServices(); }} />
    <ServiceMediaDialog service={mediaTarget} onClose={() => setMediaTarget(null)} onChanged={() => loadServices()} />
    <ServiceDrawer service={detail} canBranchEdit={canBranchEdit} canCatalogEdit={canCatalogEdit} busy={busy} onClose={() => setDetail(null)} onEdit={setEditor} onAvailability={availability} onArchive={canArchive ? setArchiveTarget : undefined} />
    <Dialog open={Boolean(archiveTarget)} onClose={() => setArchiveTarget(null)} title="Lưu trữ dịch vụ?" description={archiveTarget?.name} footer={<><Button variant="secondary" onClick={() => setArchiveTarget(null)}>Giữ lại</Button><Button variant="danger" loading={busy === `archive-${archiveTarget?.id}`} onClick={archive}>Lưu trữ</Button></>}><p className="text-sm leading-6 text-[var(--bb-muted)]">Dịch vụ sẽ ngừng nhận lịch mới tại mọi chi nhánh. Toàn bộ lịch hẹn và dữ liệu lịch sử vẫn được giữ nguyên.</p></Dialog>
  </Page>;
}

export default SalonServices;
