import { useEffect, useState } from 'react';
import { CalendarDays, Eye, Gift, Pencil, Percent, Plus, Search, Tag, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { branchesApi, combosApi, promotionsApi, servicesApi, vouchersApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, MetricCard, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';

const today = () => new Date().toISOString().slice(0, 10);
const formatDate = (value) => value ? new Date(value).toLocaleDateString('vi-VN') : '—';
const discount = (item) => item.discountType === 'PERCENTAGE' ? `${item.discountValue}%` : `${Number(item.discountValue || 0).toLocaleString('vi-VN')} ₫`;
const dateInput = (value) => value ? new Date(value).toISOString().slice(0, 10) : '';

function ScopeChecklist({ label, items, selected = [], onToggle, loading }) {
  return <fieldset className="sm:col-span-2 rounded-lg border border-[var(--bb-border)] p-3">
    <legend className="px-1 text-sm font-semibold text-[var(--bb-ink)]">{label}</legend>
    {loading ? <p className="text-sm text-[var(--bb-muted)]">Đang tải phạm vi…</p> : !items.length ? <p className="text-sm text-[var(--bb-muted)]">Không có dữ liệu khả dụng.</p> : <div className="grid max-h-36 gap-2 overflow-y-auto sm:grid-cols-2">{items.map((item) => <label key={item.id} className="flex min-h-10 items-center gap-2 rounded-md px-2 text-sm hover:bg-[var(--bb-surface-subtle)]"><input type="checkbox" className="h-4 w-4 accent-[var(--bb-brand)]" checked={selected.includes(item.id)} onChange={() => onToggle(item.id)} /><span>{item.name}</span></label>)}</div>}
    <p className="mt-2 text-xs text-[var(--bb-muted)]">Không chọn mục nào nghĩa là áp dụng cho toàn bộ phạm vi doanh nghiệp.</p>
  </fieldset>;
}

function CampaignForm({ modal, onClose, onSaved, businessId, platformCampaign }) {
  const voucher = modal?.type === 'voucher';
  const editing = Boolean(modal?.item?.id);
  const item = modal?.item;
  const [form, setForm] = useState({});
  const [saving, setSaving] = useState(false);
  const [errors, setErrors] = useState({});
  const [catalog, setCatalog] = useState({ branches: [], services: [], combos: [] });
  const [catalogLoading, setCatalogLoading] = useState(false);
  useEffect(() => {
    if (!modal) return;
    setForm({ code: item?.code || '', name: item?.name || '', description: item?.description || '', discountType: item?.discountType || 'PERCENTAGE', discountValue: item?.discountValue ?? '', minOrderValue: item?.minOrderValue ?? 0, maxDiscount: item?.maxDiscount ?? '', totalQuantity: item?.totalQuantity ?? 100, maxUsagePerCustomer: item?.maxUsagePerCustomer ?? 1, startDate: dateInput(item?.startDate) || today(), endDate: dateInput(item?.endDate), status: item?.status || 'ACTIVE', audience: item?.audience || 'ALL', autoIssue: Boolean(item?.autoIssue), autoApply: item?.autoApply !== false, stackingAllowed: Boolean(item?.stackingAllowed), branchIds: (item?.branches || []).map((value) => value.id), serviceIds: (item?.services || []).map((value) => value.id), comboIds: (item?.combos || []).map((value) => value.id) });
    setErrors({});
  }, [modal, item]);
  useEffect(() => {
    if (!modal || platformCampaign) { setCatalog({ branches: [], services: [], combos: [] }); return undefined; }
    let active = true;
    setCatalogLoading(true);
    Promise.all([branchesApi.getAccessible(), servicesApi.getManage(), combosApi.getAll()])
      .then(([branches, services, combos]) => {
        if (!active) return;
        setCatalog({
          branches: Array.isArray(branches) ? branches : branches?.data || [],
          services: Array.isArray(services) ? services : services?.data || [],
          combos: Array.isArray(combos) ? combos : combos?.data || [],
        });
      })
      .catch((error) => { if (active) toast.error(error.message || 'Không thể tải phạm vi áp dụng'); })
      .finally(() => { if (active) setCatalogLoading(false); });
    return () => { active = false; };
  }, [modal, platformCampaign]);
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const toggleScope = (key, id) => setForm((current) => ({ ...current, [key]: (current[key] || []).includes(id) ? current[key].filter((value) => value !== id) : [...(current[key] || []), id] }));
  const submit = async (event) => {
    event.preventDefault();
    const next = {};
    if (!form.name?.trim()) next.name = 'Nhập tên.';
    if (!editing && !(Number(form.discountValue) > 0)) next.discountValue = 'Giá trị phải lớn hơn 0.';
    if (!editing && !form.startDate) next.startDate = 'Chọn ngày bắt đầu.';
    if (!form.endDate) next.endDate = 'Chọn ngày kết thúc.';
    if (!editing && form.endDate && form.startDate && form.endDate < form.startDate) next.endDate = 'Ngày kết thúc phải sau ngày bắt đầu.';
    if (voucher && !editing && !form.code?.trim()) next.code = 'Nhập mã voucher.';
    if (voucher && !(Number(form.totalQuantity) >= 1)) next.totalQuantity = 'Số lượng tối thiểu là 1.';
    setErrors(next);
    if (Object.keys(next).length) return;
    setSaving(true);
    try {
      if (voucher) {
        const payload = editing
          ? { name: form.name.trim(), description: form.description.trim(), totalQuantity: Number(form.totalQuantity), maxUsagePerCustomer: Number(form.maxUsagePerCustomer), endDate: form.endDate, status: form.status, audience: form.audience, autoIssue: form.autoIssue, ...(platformCampaign ? {} : { branchIds: form.branchIds || [], serviceIds: form.serviceIds || [], comboIds: form.comboIds || [] }) }
          : { code: form.code.trim().toUpperCase(), name: form.name.trim(), description: form.description.trim(), discountType: form.discountType, discountValue: Number(form.discountValue), minOrderValue: Number(form.minOrderValue || 0), ...(form.maxDiscount ? { maxDiscount: Number(form.maxDiscount) } : {}), totalQuantity: Number(form.totalQuantity), maxUsagePerCustomer: Number(form.maxUsagePerCustomer), startDate: form.startDate, endDate: form.endDate, audience: form.audience, autoIssue: form.autoIssue, ...(platformCampaign ? {} : { businessId, branchIds: form.branchIds || [], serviceIds: form.serviceIds || [], comboIds: form.comboIds || [] }) };
        if (editing) await vouchersApi.update(item.id, payload); else await vouchersApi.create(payload);
      } else {
        const payload = editing
          ? { name: form.name.trim(), description: form.description.trim(), discountType: form.discountType, discountValue: Number(form.discountValue), startDate: form.startDate, endDate: form.endDate, status: form.status, audience: form.audience, totalQuantity: Number(form.totalQuantity), maxUsagePerCustomer: Number(form.maxUsagePerCustomer), autoApply: form.autoApply, stackingAllowed: form.stackingAllowed, ...(platformCampaign ? {} : { branchIds: form.branchIds || [], serviceIds: form.serviceIds || [], comboIds: form.comboIds || [] }) }
          : { name: form.name.trim(), description: form.description.trim(), discountType: form.discountType, discountValue: Number(form.discountValue), startDate: form.startDate, endDate: form.endDate, audience: form.audience, totalQuantity: Number(form.totalQuantity), maxUsagePerCustomer: Number(form.maxUsagePerCustomer), autoApply: form.autoApply, stackingAllowed: form.stackingAllowed, ...(platformCampaign ? {} : { businessIds: [businessId], branchIds: form.branchIds || [], serviceIds: form.serviceIds || [], comboIds: form.comboIds || [] }) };
        if (editing) await promotionsApi.update(item.id, payload); else await promotionsApi.create(payload);
      }
      toast.success(editing ? 'Đã cập nhật' : 'Đã tạo mới');
      onSaved();
    } catch (error) { toast.error(error.message || 'Không thể lưu dữ liệu'); }
    finally { setSaving(false); }
  };
  return <Dialog open={Boolean(modal)} onClose={onClose} title={`${editing ? 'Chỉnh sửa' : 'Tạo'} ${voucher ? 'voucher' : 'khuyến mãi'}`} description={editing && voucher ? 'Mã và giá trị giảm không thể đổi sau khi phát hành.' : 'Thời gian và giá trị được kiểm tra trước khi gửi.'} footer={<><Button variant="secondary" onClick={onClose}>Hủy</Button><Button type="submit" form="campaign-form" loading={saving}>Lưu</Button></>}>
    <form id="campaign-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
      {voucher && <Field className="sm:col-span-2" label="Mã voucher" required error={errors.code}><Input className="font-mono uppercase" disabled={editing} value={form.code || ''} onChange={set('code')} placeholder="WELCOME10" /></Field>}
      <Field className="sm:col-span-2" label="Tên" required error={errors.name}><Input value={form.name || ''} onChange={set('name')} /></Field>
      <Field className="sm:col-span-2" label="Mô tả"><Textarea value={form.description || ''} onChange={set('description')} /></Field>
      <Field label="Loại giảm"><Select disabled={voucher && editing} value={form.discountType || 'PERCENTAGE'} onChange={set('discountType')}><option value="PERCENTAGE">Phần trăm (%)</option><option value="FIXED_AMOUNT">Số tiền (VNĐ)</option></Select></Field>
      <Field label="Giá trị" required error={errors.discountValue}><Input disabled={voucher && editing} type="number" min="1" value={form.discountValue ?? ''} onChange={set('discountValue')} /></Field>
      {voucher && <><Field label="Đơn tối thiểu"><Input disabled={editing} type="number" min="0" value={form.minOrderValue ?? 0} onChange={set('minOrderValue')} /></Field><Field label="Giảm tối đa"><Input disabled={editing} type="number" min="1" value={form.maxDiscount ?? ''} onChange={set('maxDiscount')} /></Field><Field label="Tổng số lượng" required error={errors.totalQuantity}><Input type="number" min="1" value={form.totalQuantity ?? 1} onChange={set('totalQuantity')} /></Field></>}
      {!voucher && <Field label="Tổng lượt áp dụng"><Input type="number" min="1" value={form.totalQuantity ?? 100} onChange={set('totalQuantity')} /></Field>}
      <Field label="Tối đa mỗi khách"><Input type="number" min="1" value={form.maxUsagePerCustomer ?? 1} onChange={set('maxUsagePerCustomer')} /></Field>
      {!voucher && <><Field label="Nhóm khách áp dụng"><Select value={form.audience || 'ALL'} onChange={set('audience')}><option value="ALL">Tất cả khách</option><option value="NEW_CUSTOMER">Khách mới</option><option value="RETURNING_CUSTOMER">Khách quay lại</option><option value="BIRTHDAY">Sinh nhật hôm nay</option><option value="VIP">Khách VIP</option><option value="SELECTED">Khách được chọn</option></Select></Field><label className="flex min-h-11 items-center gap-3 self-end rounded-lg border border-[var(--bb-border)] px-3 text-sm"><input type="checkbox" checked={Boolean(form.autoApply)} onChange={(event) => setForm((current) => ({ ...current, autoApply: event.target.checked }))} />Tự động chọn ưu đãi tốt nhất</label><label className="flex min-h-11 items-center gap-3 self-end rounded-lg border border-[var(--bb-border)] px-3 text-sm"><input type="checkbox" checked={Boolean(form.stackingAllowed)} onChange={(event) => setForm((current) => ({ ...current, stackingAllowed: event.target.checked }))} />Cho phép cộng cùng voucher</label></>}
      {voucher && <><Field label="Nhóm khách nhận"><Select value={form.audience || 'ALL'} onChange={set('audience')}><option value="ALL">Tất cả khách</option><option value="NEW_CUSTOMER">Khách mới</option><option value="RETURNING_CUSTOMER">Khách quay lại</option><option value="BIRTHDAY">Sinh nhật hôm nay</option><option value="VIP">Khách VIP</option><option value="SELECTED">Khách được chọn</option></Select></Field><label className="flex min-h-11 items-center gap-3 self-end rounded-lg border border-[var(--bb-border)] px-3 text-sm"><input type="checkbox" className="h-5 w-5 accent-[var(--bb-brand)]" checked={Boolean(form.autoIssue)} onChange={(event) => setForm((current) => ({ ...current, autoIssue: event.target.checked }))} />Tự động phát voucher</label></>}
      <Field label="Bắt đầu" required error={errors.startDate}><Input disabled={voucher && editing} type="date" value={form.startDate || ''} onChange={set('startDate')} /></Field>
      <Field label="Kết thúc" required error={errors.endDate}><Input type="date" value={form.endDate || ''} onChange={set('endDate')} /></Field>
      {!platformCampaign && <>
        <ScopeChecklist label="Chi nhánh áp dụng" items={catalog.branches} selected={form.branchIds} loading={catalogLoading} onToggle={(id) => toggleScope('branchIds', id)} />
        <ScopeChecklist label="Dịch vụ áp dụng" items={catalog.services} selected={form.serviceIds} loading={catalogLoading} onToggle={(id) => toggleScope('serviceIds', id)} />
        <ScopeChecklist label="Combo áp dụng" items={catalog.combos} selected={form.comboIds} loading={catalogLoading} onToggle={(id) => toggleScope('comboIds', id)} />
      </>}
      {editing && <Field label="Trạng thái"><Select value={form.status || 'ACTIVE'} onChange={set('status')}>{voucher ? <><option value="ACTIVE">Hoạt động</option><option value="EXPIRED">Hết hạn</option><option value="REVOKED">Thu hồi</option></> : <><option value="ACTIVE">Hoạt động</option><option value="INACTIVE">Tạm dừng</option><option value="EXPIRED">Hết hạn</option></>}</Select></Field>}
    </form>
  </Dialog>;
}

function ItemCard({ item, type, manage, grant, onEdit, onDelete, onGrant, onDetail }) {
  const voucher = type === 'voucher';
  const expired = item.status === 'EXPIRED' || (item.endDate && new Date(item.endDate) <= new Date());
  const active = item.status === 'ACTIVE' && !expired;
  const used = Number(item.usedQuantity || 0);
  const total = Number(item.totalQuantity || 0);
  return <Card className="flex min-h-64 flex-col p-5">
    <div className="flex items-start justify-between gap-3"><span className="grid h-10 w-10 place-items-center rounded-lg bg-pink-50 text-pink-700">{voucher ? <Gift size={18} /> : <Tag size={18} />}</span><Badge tone={expired ? 'danger' : active ? 'success' : 'warning'}>{expired ? 'Hết hạn' : active ? 'Đang hoạt động' : item.status || 'Tạm dừng'}</Badge></div>
    {voucher && <code className="mt-4 w-fit rounded bg-zinc-100 px-2 py-1 text-sm font-bold text-[var(--bb-ink)]">{item.code}</code>}
    <h2 className={`${voucher ? 'mt-2' : 'mt-4'} font-bold text-[var(--bb-ink)]`}>{item.name}</h2>
    <p className="mt-2 text-2xl font-bold tabular-nums text-[var(--bb-brand-strong)]">{discount(item)}</p>
    <p className="mt-2 text-sm text-[var(--bb-muted)]"><CalendarDays className="mr-1 inline" size={14} />{formatDate(item.startDate)} – {formatDate(item.endDate)}</p>
    {voucher && <p className="mt-2 text-sm text-[var(--bb-muted)]">Đã dùng {used}/{total}{item.remaining !== undefined ? ` · Còn ${item.remaining}` : ''}</p>}
    <div className="mt-auto flex flex-wrap gap-2 border-t border-[var(--bb-border)] pt-4"><Button size="sm" variant="ghost" onClick={() => onDetail(item)}><Eye size={14} />Chi tiết</Button>{manage && <><Button size="sm" variant="secondary" onClick={() => onEdit(item)}><Pencil size={14} />Sửa</Button><Button size="sm" variant="ghost" className="text-[var(--bb-danger)]" onClick={() => onDelete(item)}><Trash2 size={14} />{voucher ? 'Thu hồi' : 'Xóa'}</Button></>}{voucher && grant && <Button size="sm" variant="secondary" onClick={() => onGrant(item)}><Gift size={14} />Cấp cho khách</Button>}</div>
  </Card>;
}

export function SalonPromotions() {
  const can = useAuthStore((state) => state.can);
  const user = useAuthStore((state) => state.user);
  const [tab, setTab] = useState('promotion');
  const [promotions, setPromotions] = useState([]);
  const [vouchers, setVouchers] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState(null);
  const [removeTarget, setRemoveTarget] = useState(null);
  const [grantTarget, setGrantTarget] = useState(null);
  const [customerId, setCustomerId] = useState('');
  const [busy, setBusy] = useState(false);
  const [selected, setSelected] = useState(null);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('ALL');
  const managePromotion = can('promotion:manage:tenant') || can('promotion:manage:platform');
  const manageVoucher = can('voucher:manage:tenant') || can('voucher:manage:platform');
  const grantVoucher = manageVoucher;
  const load = async () => {
    setLoading(true); setError('');
    try { const [p, v] = await Promise.all([promotionsApi.getAll(), vouchersApi.getAll()]); setPromotions(Array.isArray(p) ? p : p?.data || []); setVouchers(Array.isArray(v) ? v : v?.data || []); }
    catch (loadError) { setError(loadError.message || 'Không thể tải khuyến mãi và voucher.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);
  const remove = async () => {
    if (!removeTarget) return; setBusy(true);
    try { if (removeTarget.type === 'voucher') await vouchersApi.remove(removeTarget.item.id); else await promotionsApi.remove(removeTarget.item.id); toast.success(removeTarget.type === 'voucher' ? 'Đã thu hồi voucher' : 'Đã xóa khuyến mãi'); setRemoveTarget(null); await load(); }
    catch (error) { toast.error(error.message || 'Không thể thực hiện'); } finally { setBusy(false); }
  };
  const grant = async (event) => {
    event.preventDefault(); if (!customerId.trim()) return; setBusy(true);
    try { await vouchersApi.grant(grantTarget.id, customerId.trim()); toast.success('Đã cấp voucher cho khách hàng'); setGrantTarget(null); setCustomerId(''); await load(); }
    catch (error) { toast.error(error.message || 'Không thể cấp voucher'); } finally { setBusy(false); }
  };
  const items = tab === 'promotion' ? promotions : vouchers;
  const filteredItems = items.filter((item) => (!search.trim() || `${item.name} ${item.code || ''}`.toLocaleLowerCase('vi').includes(search.trim().toLocaleLowerCase('vi'))) && (statusFilter === 'ALL' || item.status === statusFilter));
  const canManageCurrent = tab === 'promotion' ? managePromotion : manageVoucher;
  const roleCodes = new Set([...(user?.roles || []), ...(user?.scopes || []).map((scope) => scope.code)]);
  const platformCampaign = roleCodes.has('PLATFORM_ADMIN');
  const businessId = user?.scopes?.find((scope) => scope.businessId)?.businessId || '';
  return <Page>
    <PageHeader eyebrow={platformCampaign ? 'Marketing nền tảng' : 'Tăng trưởng cơ sở'} title={platformCampaign ? 'Chiến dịch & voucher nền tảng' : 'Khuyến mãi của doanh nghiệp'} description={platformCampaign ? 'Quản lý chiến dịch ở phạm vi nền tảng theo quyền được cấp.' : 'Quản lý ưu đãi của doanh nghiệp và phạm vi áp dụng tại cơ sở.'} actions={canManageCurrent && <Button onClick={() => setModal({ type: tab })}><Plus size={17} />{tab === 'promotion' ? platformCampaign ? 'Tạo chiến dịch' : 'Tạo khuyến mãi' : 'Tạo voucher'}</Button>} />
    <div className="grid gap-3 sm:grid-cols-3"><MetricCard icon={Tag} label="Khuyến mãi" value={promotions.length} /><MetricCard icon={Gift} label="Voucher" value={vouchers.length} tone="info" /><MetricCard icon={Percent} label="Đang hoạt động" value={[...promotions, ...vouchers].filter((item) => item.status === 'ACTIVE').length} tone="success" /></div>
    <div role="tablist" aria-label="Loại ưu đãi" className="flex w-fit gap-1 rounded-[var(--bb-radius-control)] border border-[var(--bb-border)] bg-white p-1"><Button role="tab" aria-selected={tab === 'promotion'} variant={tab === 'promotion' ? 'primary' : 'ghost'} size="sm" onClick={() => setTab('promotion')}>Khuyến mãi ({promotions.length})</Button><Button role="tab" aria-selected={tab === 'voucher'} variant={tab === 'voucher' ? 'primary' : 'ghost'} size="sm" onClick={() => setTab('voucher')}>Voucher ({vouchers.length})</Button></div>
    <Card className="p-4"><div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_220px]"><Field label="Tìm ưu đãi"><div className="relative"><Search size={16} className="pointer-events-none absolute left-3 top-3.5 text-[var(--bb-muted)]" /><Input className="pl-9" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Tên hoặc mã voucher" /></div></Field><Field label="Trạng thái"><Select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)}><option value="ALL">Tất cả</option><option value="ACTIVE">Đang hoạt động</option><option value="INACTIVE">Tạm dừng</option><option value="EXPIRED">Hết hạn</option><option value="REVOKED">Đã thu hồi</option></Select></Field></div></Card>
    {loading ? <Card className="p-5"><Skeleton rows={6} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : filteredItems.length === 0 ? <Card><EmptyState icon={tab === 'promotion' ? Tag : Gift} title={items.length ? 'Không có ưu đãi phù hợp' : tab === 'promotion' ? 'Chưa có khuyến mãi' : 'Chưa có voucher'} action={canManageCurrent && !items.length ? <Button onClick={() => setModal({ type: tab })}><Plus size={17} />Tạo mới</Button> : null} /></Card> : <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{filteredItems.map((item) => <ItemCard key={item.id} item={item} type={tab} manage={canManageCurrent} grant={grantVoucher} onDetail={(value) => setSelected({ ...value, type: tab })} onEdit={(value) => setModal({ type: tab, item: value })} onDelete={(value) => setRemoveTarget({ type: tab, item: value })} onGrant={setGrantTarget} />)}</div>}
    <CampaignForm modal={modal} businessId={businessId} platformCampaign={platformCampaign} onClose={() => setModal(null)} onSaved={async () => { setModal(null); await load(); }} />
    <Dialog open={Boolean(removeTarget)} onClose={() => setRemoveTarget(null)} title={removeTarget?.type === 'voucher' ? 'Thu hồi voucher?' : 'Xóa khuyến mãi?'} description={removeTarget?.item?.name} footer={<><Button variant="secondary" onClick={() => setRemoveTarget(null)}>Hủy</Button><Button variant="danger" loading={busy} onClick={remove}>Xác nhận</Button></>}><p className="text-sm text-[var(--bb-muted)]">Hệ thống sẽ kiểm tra quyền quản lý và các điều kiện liên quan trước khi thực hiện.</p></Dialog>
    <Dialog open={Boolean(grantTarget)} onClose={() => setGrantTarget(null)} title="Cấp voucher cho khách" description={`Voucher ${grantTarget?.code || ''}`} footer={<><Button variant="secondary" onClick={() => setGrantTarget(null)}>Hủy</Button><Button type="submit" form="grant-voucher" loading={busy}>Cấp voucher</Button></>}><form id="grant-voucher" onSubmit={grant}><Field label="Khách hàng" required hint="Nhập email hoặc số điện thoại; nếu dùng họ tên, kết quả phải là duy nhất."><Input value={customerId} onChange={(event) => setCustomerId(event.target.value)} placeholder="email@domain.vn hoặc 09..." required /></Field></form></Dialog>
    <Dialog open={Boolean(selected)} onClose={() => setSelected(null)} title={selected?.name || 'Chi tiết ưu đãi'} description={selected?.code ? `Mã ${selected.code}` : 'Chiến dịch khuyến mãi'} footer={<Button variant="secondary" onClick={() => setSelected(null)}>Đóng</Button>}><div className="grid gap-3 sm:grid-cols-2"><Card className="p-4"><p className="text-xs font-semibold text-[var(--bb-muted)]">Giá trị</p><p className="mt-2 text-2xl font-bold text-[var(--bb-brand-strong)]">{selected ? discount(selected) : '—'}</p></Card><Card className="p-4"><p className="text-xs font-semibold text-[var(--bb-muted)]">Hiệu quả sử dụng</p><p className="mt-2 text-2xl font-bold">{selected?.usedInBookings ?? selected?.usedQuantity ?? 0}</p><p className="text-xs text-[var(--bb-muted)]">booking đã áp dụng</p></Card></div><p className="mt-4 text-sm leading-6 text-[var(--bb-muted)]">{selected?.description || 'Chưa có mô tả.'}</p><dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt className="text-[var(--bb-muted)]">Thời gian</dt><dd className="font-semibold">{formatDate(selected?.startDate)} – {formatDate(selected?.endDate)}</dd></div>{selected?.type === 'voucher' && <><div><dt className="text-[var(--bb-muted)]">Đã cấp</dt><dd className="font-semibold">{selected.claimedCount || 0} khách</dd></div><div><dt className="text-[var(--bb-muted)]">Đối tượng</dt><dd className="font-semibold">{selected.audience || 'ALL'}{selected.autoIssue ? ' · tự động phát' : ''}</dd></div></>}</dl></Dialog>
  </Page>;
}

export default SalonPromotions;
