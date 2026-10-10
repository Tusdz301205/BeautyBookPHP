import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { format, startOfMonth } from 'date-fns';
import { BookOpenCheck, FileClock, PackageCheck, ReceiptText } from 'lucide-react';
import toast from 'react-hot-toast';
import { paymentsApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  Field,
  Input,
  Select,
  Skeleton,
  Textarea,
  cx,
} from '../../components/ui';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const today = () => format(new Date(), 'yyyy-MM-dd');

export default function PaymentCorePanels() {
  const user = useAuthStore((state) => state.user);
  const can = useAuthStore((state) => state.can);
  const scopedBusinessId = useMemo(
    () => user?.scopes?.find((scope) => scope.businessId)?.businessId || '',
    [user],
  );
  const [businessId, setBusinessId] = useState(scopedBusinessId);
  const tabs = useMemo(() => [
    { id: 'ledger', label: 'Sổ tài chính', icon: BookOpenCheck, visible: can('financial_ledger:read:tenant') || can('financial_ledger:read:platform') },
    { id: 'policies', label: 'Chính sách thanh toán', icon: ReceiptText, visible: can('payment_policy:manage:tenant') },
    { id: 'packages', label: 'Gói liệu trình', icon: PackageCheck, visible: can('treatment_package:manage:tenant') },
    { id: 'statements', label: 'Đối soát nền tảng', icon: FileClock, visible: can('platform_statement:read:tenant') || can('platform_statement:manage:platform') },
  ].filter((item) => item.visible), [can]);
  const [tab, setTab] = useState(tabs[0]?.id || '');
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [showForm, setShowForm] = useState(false);
  const [policy, setPolicy] = useState({
    name: '',
    depositType: 'NONE',
    depositValue: '0',
    allowSplitPayment: true,
    allowInstallments: false,
    effectiveFrom: today(),
  });
  const [treatmentPackage, setTreatmentPackage] = useState({
    name: '',
    description: '',
    totalPrice: '',
    sessionCount: '1',
    validityDays: '90',
  });
  const [statement, setStatement] = useState({
    periodStart: format(startOfMonth(new Date()), 'yyyy-MM-dd'),
    periodEnd: today(),
  });

  useEffect(() => {
    if (!businessId && scopedBusinessId) setBusinessId(scopedBusinessId);
  }, [businessId, scopedBusinessId]);
  useEffect(() => {
    if (!tabs.some((item) => item.id === tab)) setTab(tabs[0]?.id || '');
    setShowForm(false);
  }, [tab, tabs]);

  const load = useCallback(async () => {
    if (!tab) return;
    if (['policies', 'packages'].includes(tab) && !businessId) {
      setRows([]);
      setError('');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const result = tab === 'ledger'
        ? await paymentsApi.getLedger(businessId ? { businessId } : {})
        : tab === 'policies'
          ? await paymentsApi.getPolicies(businessId)
          : tab === 'packages'
            ? await paymentsApi.getPackages({ businessId })
            : await paymentsApi.getStatements(businessId || undefined);
      setRows(result || []);
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải dữ liệu tài chính');
    } finally {
      setLoading(false);
    }
  }, [businessId, tab]);

  useEffect(() => { void load(); }, [load]);
  if (!tabs.length) return null;

  const createPolicy = async (event) => {
    event.preventDefault();
    setBusy('policy');
    try {
      await paymentsApi.createPolicy({
        businessId,
        name: policy.name.trim(),
        depositType: policy.depositType,
        depositValue: Number(policy.depositValue || 0),
        allowSplitPayment: policy.allowSplitPayment,
        allowInstallments: policy.allowInstallments,
        effectiveFrom: policy.effectiveFrom,
      });
      toast.success('Đã tạo phiên bản chính sách mới');
      setShowForm(false);
      setPolicy((current) => ({ ...current, name: '', depositValue: '0' }));
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const createPackage = async (event) => {
    event.preventDefault();
    setBusy('package');
    try {
      await paymentsApi.createPackage({
        businessId,
        name: treatmentPackage.name.trim(),
        description: treatmentPackage.description.trim() || undefined,
        totalPrice: Number(treatmentPackage.totalPrice),
        sessionCount: Number(treatmentPackage.sessionCount),
        validityDays: Number(treatmentPackage.validityDays),
      });
      toast.success('Đã tạo gói liệu trình');
      setShowForm(false);
      setTreatmentPackage({ name: '', description: '', totalPrice: '', sessionCount: '1', validityDays: '90' });
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const generateStatement = async (event) => {
    event.preventDefault();
    setBusy('statement');
    try {
      await paymentsApi.generateStatement({ businessId, ...statement });
      toast.success('Đã tạo bản đối soát nháp');
      setShowForm(false);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const transitionStatement = async (item, status) => {
    setBusy(item.id);
    try {
      await paymentsApi.transitionStatement(item.id, {
        status,
        reason: `Chuyển trạng thái đối soát sang ${status}`,
      });
      toast.success(`Đã chuyển đối soát sang ${status}`);
      await load();
    } catch (requestError) {
      toast.error(requestError.message);
    } finally {
      setBusy('');
    }
  };

  const actionVisible =
    (tab === 'policies' && can('payment_policy:manage:tenant')) ||
    (tab === 'packages' && can('treatment_package:manage:tenant')) ||
    (tab === 'statements' && can('platform_statement:manage:platform'));

  return <section className="space-y-4" aria-labelledby="payment-core-title">
    <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
      <div><h2 id="payment-core-title" className="text-xl font-bold">Vận hành tài chính</h2><p className="mt-1 text-sm text-[var(--bb-muted)]">Dữ liệu ledger, chính sách, gói liệu trình và đối soát được tách khỏi trạng thái booking.</p></div>
      {!scopedBusinessId && <Field label="Business ID" hint="Finance có thể lọc một doanh nghiệp; để trống khi xem toàn nền tảng."><Input value={businessId} onChange={(event) => setBusinessId(event.target.value.trim())} placeholder="UUID doanh nghiệp" /></Field>}
    </div>
    <div className="flex gap-1 overflow-x-auto rounded-xl border border-[var(--bb-border)] bg-white p-1" role="tablist">
      {tabs.map(({ id, label, icon: Icon }) => <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={cx('inline-flex min-h-11 shrink-0 items-center gap-2 rounded-lg px-4 text-sm font-semibold', tab === id ? 'bg-[var(--bb-brand-soft)] text-[var(--bb-brand-strong)]' : 'text-[var(--bb-muted)] hover:bg-[var(--bb-surface-subtle)]')}><Icon size={16} />{label}</button>)}
    </div>
    {actionVisible && <div className="flex justify-end"><Button disabled={!businessId} onClick={() => setShowForm((value) => !value)}>{showForm ? 'Đóng biểu mẫu' : tab === 'policies' ? 'Tạo chính sách' : tab === 'packages' ? 'Tạo gói' : 'Tạo đối soát'}</Button></div>}
    {showForm && tab === 'policies' && <Card className="p-5"><form onSubmit={createPolicy} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><Field label="Tên chính sách" required><Input required value={policy.name} onChange={(event) => setPolicy({ ...policy, name: event.target.value })} /></Field><Field label="Loại đặt cọc" required><Select value={policy.depositType} onChange={(event) => setPolicy({ ...policy, depositType: event.target.value })}><option value="NONE">Không bắt buộc</option><option value="FIXED">Số tiền cố định</option><option value="PERCENTAGE">Phần trăm</option><option value="FULL_PREPAYMENT">Trả trước toàn bộ</option></Select></Field><Field label="Giá trị" required><Input type="number" min="0" value={policy.depositValue} onChange={(event) => setPolicy({ ...policy, depositValue: event.target.value })} /></Field><Field label="Có hiệu lực từ" required><Input type="date" required value={policy.effectiveFrom} onChange={(event) => setPolicy({ ...policy, effectiveFrom: event.target.value })} /></Field><label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={policy.allowSplitPayment} onChange={(event) => setPolicy({ ...policy, allowSplitPayment: event.target.checked })} />Cho phép thanh toán nhiều lần</label><label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={policy.allowInstallments} onChange={(event) => setPolicy({ ...policy, allowInstallments: event.target.checked })} />Cho phép trả góp gói liệu trình</label><div className="md:col-span-2 xl:col-span-4"><Button type="submit" loading={busy === 'policy'}>Lưu phiên bản chính sách</Button></div></form></Card>}
    {showForm && tab === 'packages' && <Card className="p-5"><form onSubmit={createPackage} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><Field label="Tên gói" required><Input required value={treatmentPackage.name} onChange={(event) => setTreatmentPackage({ ...treatmentPackage, name: event.target.value })} /></Field><Field label="Tổng giá" required><Input type="number" min="1" required value={treatmentPackage.totalPrice} onChange={(event) => setTreatmentPackage({ ...treatmentPackage, totalPrice: event.target.value })} /></Field><Field label="Số buổi" required><Input type="number" min="1" max="100" required value={treatmentPackage.sessionCount} onChange={(event) => setTreatmentPackage({ ...treatmentPackage, sessionCount: event.target.value })} /></Field><Field label="Hiệu lực (ngày)" required><Input type="number" min="1" required value={treatmentPackage.validityDays} onChange={(event) => setTreatmentPackage({ ...treatmentPackage, validityDays: event.target.value })} /></Field><Field label="Mô tả" className="md:col-span-2 xl:col-span-4"><Textarea value={treatmentPackage.description} onChange={(event) => setTreatmentPackage({ ...treatmentPackage, description: event.target.value })} /></Field><div className="md:col-span-2 xl:col-span-4"><Button type="submit" loading={busy === 'package'}>Tạo gói liệu trình</Button></div></form></Card>}
    {showForm && tab === 'statements' && <Card className="p-5"><form onSubmit={generateStatement} className="grid gap-4 md:grid-cols-3"><Field label="Từ ngày" required><Input type="date" required value={statement.periodStart} onChange={(event) => setStatement({ ...statement, periodStart: event.target.value })} /></Field><Field label="Đến ngày" required><Input type="date" required value={statement.periodEnd} onChange={(event) => setStatement({ ...statement, periodEnd: event.target.value })} /></Field><div className="flex items-end"><Button type="submit" loading={busy === 'statement'} className="w-full">Tạo bản nháp</Button></div></form></Card>}
    {loading ? <Skeleton rows={5} /> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !businessId && ['policies', 'packages'].includes(tab) ? <Card><EmptyState title="Cần chọn doanh nghiệp" description="Nhập Business ID để tải dữ liệu trong đúng tenant." /></Card> : !rows.length ? <Card><EmptyState title="Chưa có dữ liệu" description="Không có bản ghi trong phạm vi đang xem." /></Card> : <CoreRows tab={tab} rows={rows} busy={busy} canManageStatements={can('platform_statement:manage:platform')} onTransition={transitionStatement} />}
  </section>;
}

function CoreRows({ tab, rows, busy, canManageStatements, onTransition }) {
  if (tab === 'ledger') return <Card className="overflow-x-auto"><table className="min-w-full text-left text-sm"><thead className="bg-[var(--bb-surface-subtle)] text-xs uppercase text-[var(--bb-muted)]"><tr><th className="p-4">Thời gian</th><th className="p-4">Loại</th><th className="p-4">Nguồn</th><th className="p-4 text-right">Số tiền</th></tr></thead><tbody className="divide-y divide-[var(--bb-border)]">{rows.map((item) => <tr key={item.id}><td className="p-4">{new Date(item.occurredAt).toLocaleString('vi-VN')}</td><td className="p-4"><Badge>{item.type}</Badge></td><td className="p-4 bb-mono text-xs">{item.bookingId || item.sourceId}</td><td className="p-4 text-right font-bold">{item.direction === 'CREDIT' ? '−' : '+'}{money(item.amount)}</td></tr>)}</tbody></table></Card>;
  if (tab === 'policies') return <div className="grid gap-3 lg:grid-cols-2">{rows.map((item) => <Card key={item.id} className="p-5"><div className="flex items-start justify-between gap-3"><div><h3 className="font-bold">{item.name}</h3><p className="mt-1 text-xs text-[var(--bb-muted)]">Phiên bản {item.version} · hiệu lực {new Date(item.effectiveFrom).toLocaleDateString('vi-VN')}</p></div><Badge tone={item.status === 'ACTIVE' ? 'success' : 'neutral'}>{item.status}</Badge></div><p className="mt-4 text-sm">{item.depositType} · {item.depositType === 'PERCENTAGE' ? `${Number(item.depositValue)}%` : money(item.depositValue)}</p></Card>)}</div>;
  if (tab === 'packages') return <div className="grid gap-3 lg:grid-cols-2">{rows.map((item) => <Card key={item.id} className="p-5"><div className="flex items-start justify-between gap-3"><div><h3 className="font-bold">{item.name}</h3><p className="mt-1 text-sm text-[var(--bb-muted)]">{item.description || 'Không có mô tả'}</p></div><Badge tone="success">ACTIVE</Badge></div><p className="mt-4 font-bold">{money(item.totalPrice)} · {item.sessionCount} buổi</p><p className="mt-1 text-xs text-[var(--bb-muted)]">Hiệu lực {item.validityDays} ngày</p></Card>)}</div>;
  return <div className="space-y-3">{rows.map((item) => {
    const next = item.status === 'DRAFT' ? 'REVIEW' : item.status === 'REVIEW' ? 'ISSUED' : item.status === 'ISSUED' || item.status === 'OVERDUE' ? 'PAID' : null;
    return <Card key={item.id} className="p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-center"><div className="min-w-0 flex-1"><h3 className="font-bold">{item.business?.name || item.businessId}</h3><p className="mt-1 text-xs text-[var(--bb-muted)]">{new Date(item.periodStart).toLocaleDateString('vi-VN')} – {new Date(item.periodEnd).toLocaleDateString('vi-VN')} · phiên bản {item.version}</p></div><p className="font-bold">{money(item.netAmount)}</p><Badge tone={item.status === 'PAID' ? 'success' : item.status === 'OVERDUE' ? 'danger' : 'warning'}>{item.status}</Badge>{canManageStatements && next && <Button size="sm" loading={busy === item.id} onClick={() => onTransition(item, next)}>Chuyển sang {next}</Button>}</div></Card>;
  })}</div>;
}
