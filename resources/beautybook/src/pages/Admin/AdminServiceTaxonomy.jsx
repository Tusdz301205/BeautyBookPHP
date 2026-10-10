import { useCallback, useEffect, useMemo, useState } from 'react';
import { FolderTree, GitMerge, Plus, Search, Sparkles } from 'lucide-react';
import toast from 'react-hot-toast';
import { servicesApi } from '../../api/apiClient';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Input, Page, PageHeader, Select, Skeleton, Textarea } from '../../components/ui';
import { statusLabel, statusTone } from '../../utils/displayLabels';

const emptyForm = { code: '', slug: '', name: '', description: '', synonyms: '', parentId: '', status: 'ACTIVE', replacementCanonicalId: '' };

function slugify(value) {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

export default function AdminServiceTaxonomy() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('ALL');
  const [editor, setEditor] = useState(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true); setError('');
    try {
      const response = await servicesApi.getCanonicalManage();
      setItems(Array.isArray(response) ? response : response?.data || []);
    } catch (requestError) { setError(requestError.message || 'Không thể tải danh mục dịch vụ chuẩn.'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { load(); }, [load]);

  const itemById = useMemo(() => new Map(items.map((item) => [item.id, item])), [items]);
  const filtered = useMemo(() => {
    const keyword = query.trim().toLocaleLowerCase('vi');
    return items.filter((item) => {
      if (status !== 'ALL' && item.status !== status) return false;
      return !keyword || [item.name, item.description, ...(item.synonyms || [])].filter(Boolean).some((value) => String(value).toLocaleLowerCase('vi').includes(keyword));
    });
  }, [items, query, status]);

  const open = (item = null) => {
    setEditor(item || {});
    setForm(item ? {
      code: item.code || '', slug: item.slug || '', name: item.name || '',
      description: item.description || '', synonyms: (item.synonyms || []).join(', '),
      parentId: item.parentId || '', status: item.status || 'ACTIVE',
      replacementCanonicalId: item.replacementCanonicalId || '',
    } : emptyForm);
  };
  const set = (key) => (event) => setForm((current) => ({ ...current, [key]: event.target.value }));
  const save = async (event) => {
    event.preventDefault();
    const name = form.name.trim();
    if (!name) return toast.error('Vui lòng nhập tên dịch vụ.');
    if (form.status === 'MERGED' && !form.replacementCanonicalId) return toast.error('Vui lòng chọn dịch vụ nhận dữ liệu sau khi gộp.');
    const slug = form.slug.trim() || slugify(name);
    const code = form.code.trim() || slug.replace(/-/g, '_').toUpperCase();
    if (!slug || !code) return toast.error('Tên dịch vụ chưa thể tạo mã kỹ thuật hợp lệ.');
    setSaving(true);
    try {
      const payload = {
        slug,
        name,
        description: form.description.trim() || undefined,
        synonyms: form.synonyms.split(',').map((value) => value.trim()).filter(Boolean),
        parentId: form.parentId || undefined,
        status: form.status,
        ...(form.status === 'MERGED' ? { replacementCanonicalId: form.replacementCanonicalId } : {}),
      };
      if (editor?.id) await servicesApi.updateCanonical(editor.id, payload);
      else await servicesApi.createCanonical({ ...payload, code });
      toast.success(editor?.id ? 'Đã cập nhật loại dịch vụ' : 'Đã thêm loại dịch vụ');
      setEditor(null); await load();
    } catch (requestError) { toast.error(requestError.message || 'Không thể lưu loại dịch vụ.'); }
    finally { setSaving(false); }
  };

  return <Page>
    <PageHeader eyebrow="Tìm kiếm & phân loại" title="Danh mục dịch vụ chuẩn" description="Quản lý các loại dịch vụ dùng chung để khách hàng tìm kiếm nhất quán trên BeautyBook." actions={<Button onClick={() => open()}><Plus size={16} />Thêm loại dịch vụ</Button>} />
    <Card className="p-4"><div className="grid gap-3 md:grid-cols-[minmax(260px,1fr)_240px]"><Field label="Tìm loại dịch vụ"><div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 text-zinc-400" size={16} /><Input className="pl-9" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Tên hoặc từ khóa liên quan" /></div></Field><Field label="Trạng thái"><Select value={status} onChange={(event) => setStatus(event.target.value)}><option value="ALL">Tất cả trạng thái</option><option value="ACTIVE">Đang sử dụng</option><option value="DEPRECATED">Ngừng dùng cho lựa chọn mới</option><option value="MERGED">Đã gộp</option></Select></Field></div></Card>
    {loading ? <Card className="p-5"><Skeleton rows={7} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !filtered.length ? <Card><EmptyState icon={Sparkles} title="Không có loại dịch vụ phù hợp" /></Card> : <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{filtered.map((item) => <Card key={item.id} className="flex min-h-64 flex-col p-5"><div className="flex items-start justify-between gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]"><FolderTree size={18} /></span><Badge tone={statusTone(item.status)}>{statusLabel(item.status)}</Badge></div><h2 className="mt-4 font-bold text-zinc-950">{item.name}</h2>{item.parentId && <p className="mt-1 text-xs font-semibold text-[var(--bb-muted)]">Thuộc nhóm {itemById.get(item.parentId)?.name || 'dịch vụ khác'}</p>}{item.description && <p className="mt-3 line-clamp-3 text-sm leading-6 text-zinc-600">{item.description}</p>}<div className="mt-3 flex flex-wrap gap-1.5">{(item.synonyms || []).slice(0, 5).map((word) => <Badge key={word}>{word}</Badge>)}</div>{item.status === 'MERGED' && <p className="mt-3 flex items-center gap-1.5 text-xs font-semibold text-zinc-600"><GitMerge size={14} />Đã chuyển về {itemById.get(item.replacementCanonicalId)?.name || 'dịch vụ thay thế'}</p>}<p className="mt-auto pt-4 text-xs text-[var(--bb-muted)]">Đang được {item._count?.businessServices || 0} dịch vụ doanh nghiệp sử dụng</p><div className="mt-3"><Button size="sm" variant="secondary" onClick={() => open(item)}>Chỉnh sửa</Button></div><details className="mt-4 border-t border-[var(--bb-border)] pt-3 text-xs text-[var(--bb-muted)]"><summary className="cursor-pointer font-semibold">Thông tin kỹ thuật</summary><p className="mt-2 break-all">Mã: {item.code}</p><p className="mt-1 break-all">Đường dẫn: {item.slug}</p></details></Card>)}</div>}
    <Dialog open={editor !== null} onClose={() => setEditor(null)} size="lg" title={editor?.id ? 'Chỉnh sửa loại dịch vụ' : 'Thêm loại dịch vụ'} description="Thông tin này giúp BeautyBook kết nối cách gọi khác nhau của cùng một dịch vụ." footer={<><Button variant="secondary" onClick={() => setEditor(null)}>Hủy</Button><Button type="submit" form="canonical-service-form" loading={saving}>Lưu</Button></>}>
      <form id="canonical-service-form" onSubmit={save} className="grid min-w-0 gap-4 sm:grid-cols-2">
        <Field className="min-w-0 sm:col-span-2" label="Tên loại dịch vụ" required><Input autoFocus value={form.name} onChange={set('name')} placeholder="Ví dụ: Chăm sóc da mặt" /></Field>
        <Field className="min-w-0 sm:col-span-2" label="Mô tả"><Textarea value={form.description} onChange={set('description')} placeholder="Mô tả ngắn để đội ngũ quản trị hiểu phạm vi của loại dịch vụ" /></Field>
        <Field className="min-w-0 sm:col-span-2" label="Tên gọi khác / từ khóa liên quan" hint="Phân cách bằng dấu phẩy"><Input value={form.synonyms} onChange={set('synonyms')} placeholder="facial, dưỡng da, làm sạch da" /></Field>
        <Field className="min-w-0" label="Nhóm cha"><Select value={form.parentId} onChange={set('parentId')}><option value="">Không thuộc nhóm nào</option>{items.filter((item) => item.id !== editor?.id && item.status === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>
        <Field className="min-w-0" label="Trạng thái"><Select value={form.status} onChange={set('status')}><option value="ACTIVE">Đang sử dụng</option><option value="DEPRECATED">Ngừng dùng cho lựa chọn mới</option><option value="MERGED">Gộp vào loại dịch vụ khác</option></Select></Field>
        {form.status === 'MERGED' && <Field className="min-w-0 sm:col-span-2" label="Chuyển về loại dịch vụ" required><Select value={form.replacementCanonicalId} onChange={set('replacementCanonicalId')}><option value="">Chọn loại dịch vụ nhận dữ liệu</option>{items.filter((item) => item.id !== editor?.id && item.status === 'ACTIVE').map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</Select></Field>}
        <details className="min-w-0 rounded-xl border border-[var(--bb-border)] p-4 sm:col-span-2"><summary className="cursor-pointer text-sm font-bold">Thông tin kỹ thuật nâng cao</summary><div className="mt-4 grid min-w-0 gap-4 sm:grid-cols-2"><Field className="min-w-0" label="Mã hệ thống" hint="Tự tạo từ tên nếu để trống"><Input value={form.code} disabled={Boolean(editor?.id)} onChange={set('code')} placeholder="CHAM_SOC_DA" /></Field><Field className="min-w-0" label="Đường dẫn kỹ thuật" hint="Tự tạo từ tên nếu để trống"><Input value={form.slug} onChange={set('slug')} placeholder="cham-soc-da" /></Field></div></details>
      </form>
    </Dialog>
  </Page>;
}
