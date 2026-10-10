import { useEffect, useMemo, useState } from 'react';
import {
  ArrowDown,
  ArrowUp,
  Clock3,
  Copy,
  Gift,
  GripVertical,
  Pencil,
  Plus,
  Search,
  Trash2,
  UserRound,
  UsersRound,
  X,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { branchesApi, combosApi, servicesApi } from '../../api/apiClient';
import {
  Badge,
  Button,
  Card,
  Dialog,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Page,
  PageHeader,
  Skeleton,
  Textarea,
} from '../../components/ui';
import { FileUpload } from '../../components/media/FileUpload';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const blank = {
  branchId: '',
  name: '',
  description: '',
  comboPrice: '',
  validFrom: '',
  validTo: '',
  maxUsage: '',
  status: 'ACTIVE',
  staffAssignmentMode: 'SINGLE_PROVIDER',
  services: [],
};

function ChoiceGroup({ label, value, options, onChange, disabled = false }) {
  return (
    <fieldset>
      <legend className="mb-2 text-sm font-bold">{label}</legend>
      <div className="flex flex-wrap gap-2">
        {options.map((option) => (
          <button
            key={option.value}
            type="button"
            disabled={disabled}
            aria-pressed={value === option.value}
            onClick={() => onChange(option.value)}
            className={`min-h-11 rounded-xl border px-4 text-left text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-55 ${
              value === option.value
                ? 'border-[var(--bb-brand)] bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]'
                : 'border-[var(--bb-border)] bg-[var(--bb-surface)] hover:bg-[var(--bb-surface-subtle)]'
            }`}
          >
            {option.label}
          </button>
        ))}
      </div>
    </fieldset>
  );
}

export default function SalonCombos() {
  const [branches, setBranches] = useState([]);
  const [branchId, setBranchId] = useState('');
  const [services, setServices] = useState([]);
  const [rows, setRows] = useState([]);
  const [form, setForm] = useState(null);
  const [search, setSearch] = useState('');
  const [serviceSearch, setServiceSearch] = useState('');
  const [status, setStatus] = useState('ALL');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [removeTarget, setRemoveTarget] = useState(null);
  const [dragIndex, setDragIndex] = useState(null);

  const selectedBranch = branches.find((item) => item.id === branchId);
  const servicesById = useMemo(() => new Map(services.map((item) => [item.id, item])), [services]);

  const loadReference = async () => {
    const result = await branchesApi.getAccessible();
    const list = result.data ?? result ?? [];
    setBranches(list);
    setBranchId((current) =>
      current || list.find((branch) => branch.status === 'ACTIVE')?.id || list[0]?.id || '');
  };

  const load = async () => {
    if (!branchId) {
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const [comboRows, serviceRows] = await Promise.all([
        combosApi.getAll({ branchId }),
        servicesApi.getManage(branchId),
      ]);
      setRows(comboRows.data ?? comboRows ?? []);
      setServices(serviceRows.data ?? serviceRows ?? []);
    } catch (requestError) {
      setError(requestError.message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadReference().catch((requestError) => {
      setError(requestError.message);
      setLoading(false);
    });
  }, []);
  useEffect(() => { load(); }, [branchId]);

  const normalizeItems = (combo) => combo.comboServices
    .slice()
    .sort((left, right) => (left.sortOrder || 0) - (right.sortOrder || 0))
    .map((item, index) => ({
      serviceId: item.serviceId,
      quantity: item.quantity,
      sortOrder: index,
      transitionMinutes: item.transitionMinutes || 0,
    }));

  const edit = (combo = null, duplicate = false) => {
    setServiceSearch('');
    setForm(combo ? {
      ...combo,
      id: duplicate ? undefined : combo.id,
      name: duplicate ? `${combo.name} — Bản sao` : combo.name,
      comboPrice: Number(combo.comboPrice),
      validFrom: combo.validFrom?.slice(0, 10) || '',
      validTo: combo.validTo?.slice(0, 10) || '',
      maxUsage: combo.maxUsage ?? '',
      services: normalizeItems(combo),
      images: duplicate ? [] : combo.images,
    } : { ...blank, branchId });
  };

  const set = (key) => (event) => setForm((value) => ({ ...value, [key]: event.target.value }));
  const addService = (serviceId) => setForm((current) => {
    if (current.services.some((item) => item.serviceId === serviceId)) return current;
    return {
      ...current,
      services: [
        ...current.services,
        { serviceId, quantity: 1, transitionMinutes: 0, sortOrder: current.services.length },
      ],
    };
  });
  const removeService = (serviceId) => setForm((current) => ({
    ...current,
    services: current.services
      .filter((item) => item.serviceId !== serviceId)
      .map((item, index) => ({ ...item, sortOrder: index })),
  }));
  const updateItem = (serviceId, key, value) => setForm((current) => ({
    ...current,
    services: current.services.map((item) =>
      item.serviceId === serviceId ? { ...item, [key]: Number(value) } : item),
  }));
  const moveItem = (from, to) => setForm((current) => {
    if (to < 0 || to >= current.services.length || from === to) return current;
    const next = [...current.services];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);
    return { ...current, services: next.map((row, index) => ({ ...row, sortOrder: index })) };
  });

  const summary = useMemo(() => {
    const items = form?.services || [];
    const originalPrice = items.reduce((sum, item) => {
      const service = servicesById.get(item.serviceId);
      return sum + Number(service?.price || 0) * Number(item.quantity || 1);
    }, 0);
    const durationMinutes = items.reduce((sum, item) => {
      const service = servicesById.get(item.serviceId);
      return sum + Number(service?.durationMinutes || 0) * Number(item.quantity || 1) + Number(item.transitionMinutes || 0);
    }, 0);
    const comboPrice = Number(form?.comboPrice || 0);
    const savingAmount = Math.max(0, originalPrice - comboPrice);
    return {
      originalPrice,
      durationMinutes,
      comboPrice,
      savingAmount,
      discountPercentage: originalPrice ? Math.round((savingAmount / originalPrice) * 100) : 0,
    };
  }, [form?.services, form?.comboPrice, servicesById]);

  const save = async (event) => {
    event.preventDefault();
    if (form.services.length < 2) {
      toast.error('Combo phải có ít nhất hai dịch vụ');
      return;
    }
    setBusy('save');
    try {
      const payload = {
        branchId: form.branchId,
        name: form.name.trim(),
        description: form.description.trim() || undefined,
        comboPrice: Number(form.comboPrice),
        pricingMode: 'FIXED_PRICE',
        staffAssignmentMode: form.staffAssignmentMode,
        validFrom: form.validFrom ? new Date(`${form.validFrom}T00:00:00+07:00`).toISOString() : undefined,
        validTo: form.validTo ? new Date(`${form.validTo}T23:59:59+07:00`).toISOString() : undefined,
        maxUsage: form.maxUsage ? Number(form.maxUsage) : undefined,
        status: form.status,
        services: form.services.map((item, index) => ({ ...item, sortOrder: index })),
      };
      if (form.id) await combosApi.update(form.id, payload);
      else await combosApi.create(payload);
      toast.success(form.id ? 'Đã tạo phiên bản combo mới' : 'Đã tạo combo');
      setForm(null);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const remove = async () => {
    setBusy('remove');
    try {
      await combosApi.remove(removeTarget.id);
      toast.success('Đã lưu trữ combo');
      setRemoveTarget(null);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const filtered = useMemo(() => rows.filter((item) =>
    (!search.trim() || item.name.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi'))) &&
    (status === 'ALL' || item.status === status)), [rows, search, status]);
  const availableServices = useMemo(() => services.filter((service) => {
    const categoryName = typeof service.category === 'string'
      ? service.category
      : service.category?.name || '';
    const active = service.status === 'ACTIVE' || service.active === true;
    return active &&
      !form?.services.some((item) => item.serviceId === service.id) &&
      (!serviceSearch.trim() || `${service.name} ${categoryName}`
        .toLocaleLowerCase('vi')
        .includes(serviceSearch.trim().toLocaleLowerCase('vi')));
  }), [services, form?.services, serviceSearch]);

  return (
    <Page>
      <PageHeader
        eyebrow="Danh mục kinh doanh"
        title="Combo dịch vụ"
        description="Kết hợp nhiều dịch vụ trong một lịch hẹn, với thứ tự, thời lượng và giá rõ ràng."
        actions={<Button onClick={() => edit()} disabled={!branchId || selectedBranch?.status !== 'ACTIVE'}><Plus size={16} />Tạo combo</Button>}
      />
      <Card className="space-y-4 p-4">
        <ChoiceGroup
          label="Chi nhánh đang quản lý"
          value={branchId}
          onChange={setBranchId}
          options={branches.map((branch) => ({ value: branch.id, label: branch.name }))}
        />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Tìm combo">
            <div className="relative">
              <Search size={16} className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" />
              <Input className="pl-9" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tên combo" />
            </div>
          </Field>
          <ChoiceGroup
            label="Trạng thái"
            value={status}
            onChange={setStatus}
            options={[
              { value: 'ALL', label: 'Tất cả' },
              { value: 'ACTIVE', label: 'Đang bán' },
              { value: 'PAUSED', label: 'Tạm dừng' },
              { value: 'INACTIVE', label: 'Ngừng bán' },
            ]}
          />
        </div>
      </Card>

      {loading ? <Card className="p-5"><Skeleton rows={6} /></Card>
        : error ? <Card><ErrorState message={error} onRetry={load} /></Card>
          : !filtered.length ? (
            <Card><EmptyState icon={Gift} title="Chưa có combo phù hợp" description="Tạo combo đầu tiên từ ít nhất hai dịch vụ đang hoạt động." action={<Button onClick={() => edit()}>Tạo combo</Button>} /></Card>
          ) : (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {filtered.map((combo) => (
                <Card key={combo.id} className="flex flex-col overflow-hidden">
                  <div className="aspect-[16/7] bg-[var(--bb-surface-subtle)]">
                    {combo.images?.[0]?.media?.url
                      ? <img src={combo.images[0].media.url} alt="" width="640" height="280" className="h-full w-full object-cover" />
                      : <span className="grid h-full place-items-center text-[var(--bb-brand-strong)]"><Gift size={32} /></span>}
                  </div>
                  <div className="flex flex-1 flex-col p-5">
                    <div className="flex items-start justify-between gap-3">
                      <h2 className="font-bold">{combo.name}</h2>
                      <Badge tone={combo.status === 'ACTIVE' ? 'success' : combo.status === 'PAUSED' ? 'warning' : 'neutral'}>{combo.status}</Badge>
                    </div>
                    <p className="mt-2 line-clamp-2 text-sm leading-6 text-[var(--bb-muted)]">{combo.description || 'Chưa có mô tả.'}</p>
                    <ol className="mt-4 space-y-2">
                      {combo.comboServices.slice(0, 4).map((item, index) => (
                        <li key={item.serviceId} className="flex items-center gap-2 text-sm">
                          <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-[var(--bb-brand-soft)] text-xs font-bold text-[var(--bb-brand-strong)]">{index + 1}</span>
                          <span className="truncate">{item.service.name}</span>
                        </li>
                      ))}
                    </ol>
                    <div className="mt-4 flex flex-wrap gap-2">
                      <Badge>{combo.comboServices.length} dịch vụ</Badge>
                      <Badge><Clock3 size={12} className="mr-1" />{combo.durationMinutes} phút</Badge>
                      <Badge tone="brand">v{combo.version} · {money(combo.comboPrice)}</Badge>
                    </div>
                    <p className="mt-3 text-xs text-[var(--bb-muted)]">Tiết kiệm {money(combo.savingAmount)} · {combo.staffAssignmentMode === 'PER_SERVICE_PROVIDER' ? 'Nhiều chuyên viên' : 'Một chuyên viên'}</p>
                    <div className="mt-auto flex flex-wrap gap-2 border-t border-[var(--bb-border)] pt-4">
                      <Button size="sm" variant="secondary" onClick={() => edit(combo)}><Pencil size={14} />Sửa</Button>
                      <Button size="sm" variant="ghost" onClick={() => edit(combo, true)}><Copy size={14} />Nhân bản</Button>
                      <Button size="sm" variant="ghost" className="text-[var(--bb-danger)]" onClick={() => setRemoveTarget(combo)}><Trash2 size={14} />Lưu trữ</Button>
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          )}

      <Dialog
        open={Boolean(form)}
        onClose={() => setForm(null)}
        title={form?.id ? 'Tạo phiên bản combo mới' : 'Combo Builder'}
        description="Chọn dịch vụ bằng tìm kiếm, sắp xếp đúng trình tự thực hiện và kiểm tra tổng trước khi lưu."
        size="lg"
        footer={<><Button variant="secondary" onClick={() => setForm(null)}>Hủy</Button><Button type="submit" form="combo-form" loading={busy === 'save'} disabled={(form?.services.length || 0) < 2}>Lưu combo</Button></>}
      >
        {form && (
          <form id="combo-form" onSubmit={save} className="space-y-6">
            <section className="grid gap-4 sm:grid-cols-2">
              <Field label="Tên combo" required><Input autoFocus required minLength="2" value={form.name} onChange={set('name')} /></Field>
              <Field label="Giá combo" required><Input type="number" min="0" required value={form.comboPrice} onChange={set('comboPrice')} /></Field>
              <Field className="sm:col-span-2" label="Mô tả"><Textarea value={form.description || ''} onChange={set('description')} /></Field>
              <div className="sm:col-span-2">
                <ChoiceGroup
                  label="Chi nhánh áp dụng"
                  value={form.branchId}
                  disabled
                  onChange={() => {}}
                  options={branches
                    .filter((branch) => branch.id === form.branchId)
                    .map((branch) => ({ value: branch.id, label: branch.name }))}
                />
              </div>
              <div className="sm:col-span-2">
                <ChoiceGroup
                  label="Cách phân công"
                  value={form.staffAssignmentMode}
                  onChange={(value) => setForm((current) => ({ ...current, staffAssignmentMode: value }))}
                  options={[
                    { value: 'SINGLE_PROVIDER', label: 'Một chuyên viên làm toàn bộ' },
                    { value: 'PER_SERVICE_PROVIDER', label: 'Chuyên viên theo từng dịch vụ' },
                  ]}
                />
              </div>
            </section>

            <section aria-labelledby="combo-service-picker">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                  <h3 id="combo-service-picker" className="font-bold">Thêm dịch vụ</h3>
                  <p className="mt-1 text-sm text-[var(--bb-muted)]">Chỉ hiển thị dịch vụ hoạt động tại chi nhánh đã chọn.</p>
                </div>
                <div className="relative w-full sm:max-w-sm">
                  <Search size={16} className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" />
                  <Input className="pl-9" value={serviceSearch} onChange={(event) => setServiceSearch(event.target.value)} placeholder="Tìm tên hoặc nhóm dịch vụ" />
                </div>
              </div>
              <div className="mt-3 grid max-h-64 gap-2 overflow-y-auto rounded-xl bg-[var(--bb-surface-subtle)] p-3 sm:grid-cols-2">
                {availableServices.length ? availableServices.map((service) => (
                  <article key={service.id} className="flex items-center gap-3 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface)] p-3">
                    <div className="min-w-0 flex-1">
                      <strong className="block truncate text-sm">{service.name}</strong>
                      <span className="text-xs text-[var(--bb-muted)]">{money(service.price)} · {service.durationMinutes} phút</span>
                    </div>
                    <Button type="button" size="sm" variant="secondary" onClick={() => addService(service.id)}><Plus size={14} />Thêm</Button>
                  </article>
                )) : <p className="p-3 text-sm text-[var(--bb-muted)]">Không còn dịch vụ phù hợp để thêm.</p>}
              </div>
            </section>

            <section aria-labelledby="combo-selected-services">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <h3 id="combo-selected-services" className="font-bold">Trình tự thực hiện</h3>
                  <p className="mt-1 text-sm text-[var(--bb-muted)]">Kéo thả hoặc dùng nút lên/xuống. Combo cần ít nhất hai dịch vụ.</p>
                </div>
                <Badge tone={(form.services.length || 0) >= 2 ? 'success' : 'warning'}>{form.services.length}/2 tối thiểu</Badge>
              </div>
              <ol className="mt-3 space-y-2">
                {form.services.map((item, index) => {
                  const service = servicesById.get(item.serviceId);
                  return (
                    <li
                      key={item.serviceId}
                      draggable
                      onDragStart={() => setDragIndex(index)}
                      onDragOver={(event) => event.preventDefault()}
                      onDrop={() => { if (dragIndex !== null) moveItem(dragIndex, index); setDragIndex(null); }}
                      className="grid gap-3 rounded-xl border border-[var(--bb-border)] bg-[var(--bb-surface)] p-3 sm:grid-cols-[auto_minmax(0,1fr)_110px_130px_auto] sm:items-end"
                    >
                      <div className="flex items-center gap-2 self-center">
                        <GripVertical size={18} className="cursor-grab text-[var(--bb-muted)]" aria-hidden="true" />
                        <span className="grid h-7 w-7 place-items-center rounded-full bg-[var(--bb-brand-soft)] text-xs font-bold text-[var(--bb-brand-strong)]">{index + 1}</span>
                      </div>
                      <div className="min-w-0 self-center">
                        <strong className="block truncate">{service?.name || 'Dịch vụ không còn khả dụng'}</strong>
                        <span className="text-xs text-[var(--bb-muted)]">{money(service?.price)} · {service?.durationMinutes || 0} phút</span>
                      </div>
                      <Field label="Số lượng"><Input type="number" min="1" max="10" value={item.quantity} onChange={(event) => updateItem(item.serviceId, 'quantity', event.target.value)} /></Field>
                      <Field label="Chuyển tiếp"><Input type="number" min="0" max="120" value={item.transitionMinutes} onChange={(event) => updateItem(item.serviceId, 'transitionMinutes', event.target.value)} /></Field>
                      <div className="flex items-center gap-1 self-center">
                        <Button type="button" size="sm" variant="ghost" disabled={index === 0} aria-label={`Đưa ${service?.name} lên`} onClick={() => moveItem(index, index - 1)}><ArrowUp size={15} /></Button>
                        <Button type="button" size="sm" variant="ghost" disabled={index === form.services.length - 1} aria-label={`Đưa ${service?.name} xuống`} onClick={() => moveItem(index, index + 1)}><ArrowDown size={15} /></Button>
                        <Button type="button" size="sm" variant="ghost" aria-label={`Gỡ ${service?.name}`} onClick={() => removeService(item.serviceId)}><X size={15} /></Button>
                      </div>
                    </li>
                  );
                })}
              </ol>
            </section>

            <Card className="grid gap-3 bg-[var(--bb-surface-subtle)] p-4 sm:grid-cols-2 lg:grid-cols-5">
              <div><span className="text-xs text-[var(--bb-muted)]">Giá gốc</span><strong className="block">{money(summary.originalPrice)}</strong></div>
              <div><span className="text-xs text-[var(--bb-muted)]">Giá combo</span><strong className="block">{money(summary.comboPrice)}</strong></div>
              <div><span className="text-xs text-[var(--bb-muted)]">Tiết kiệm</span><strong className="block">{money(summary.savingAmount)} · {summary.discountPercentage}%</strong></div>
              <div><span className="text-xs text-[var(--bb-muted)]">Tổng thời lượng</span><strong className="block">{summary.durationMinutes} phút</strong></div>
              <div><span className="text-xs text-[var(--bb-muted)]">Phân công</span><strong className="flex items-center gap-1">{form.staffAssignmentMode === 'PER_SERVICE_PROVIDER' ? <><UsersRound size={15} />Theo dịch vụ</> : <><UserRound size={15} />Một người</>}</strong></div>
            </Card>

            <section className="grid gap-4 sm:grid-cols-2">
              <Field label="Từ ngày"><Input type="date" value={form.validFrom || ''} onChange={set('validFrom')} /></Field>
              <Field label="Đến ngày"><Input type="date" value={form.validTo || ''} onChange={set('validTo')} /></Field>
              <Field label="Giới hạn lượt dùng"><Input type="number" min="1" value={form.maxUsage || ''} onChange={set('maxUsage')} placeholder="Không giới hạn" /></Field>
              <ChoiceGroup
                label="Trạng thái bán"
                value={form.status}
                onChange={(value) => setForm((current) => ({ ...current, status: value }))}
                options={[
                  { value: 'ACTIVE', label: 'Đang bán' },
                  { value: 'PAUSED', label: 'Tạm dừng' },
                  { value: 'INACTIVE', label: 'Ngừng bán' },
                ]}
              />
            </section>

            {form.id && (
              <FileUpload
                entityType="COMBO_IMAGE"
                entityId={form.id}
                businessId={selectedBranch?.businessId}
                branchId={form.branchId}
                value={form.images?.[0]?.media}
                label="Ảnh combo"
                onUploaded={(media) => setForm((value) => ({ ...value, images: [{ media }] }))}
                onRemoved={() => setForm((value) => ({ ...value, images: [] }))}
              />
            )}
          </form>
        )}
      </Dialog>

      <Dialog
        open={Boolean(removeTarget)}
        onClose={() => setRemoveTarget(null)}
        title="Lưu trữ combo?"
        description={removeTarget?.name}
        footer={<><Button variant="secondary" onClick={() => setRemoveTarget(null)}>Giữ lại</Button><Button variant="danger" loading={busy === 'remove'} onClick={remove}>Lưu trữ</Button></>}
      >
        <p className="text-sm text-[var(--bb-muted)]">Combo sẽ ngừng nhận lịch mới. Các lịch đã đặt vẫn giữ tên, giá, thời lượng và thứ tự dịch vụ tại thời điểm đặt.</p>
      </Dialog>
    </Page>
  );
}
