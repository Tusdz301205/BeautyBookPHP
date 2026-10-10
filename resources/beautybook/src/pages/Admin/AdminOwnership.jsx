import { useCallback, useEffect, useState } from 'react';
import { Building2, RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { ownershipApi } from '../../api/apiClient';
import { Badge, Button, Card, Dialog, EmptyState, ErrorState, Field, Page, PageHeader, Skeleton, Textarea } from '../../components/ui';
import { ownershipVersionsReady } from '../../utils/businessCompletionRules';

const stamp = (value) => (value ? new Date(value).toLocaleString('vi-VN') : '—');

export default function AdminOwnership() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reviewing, setReviewing] = useState(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState('');
  const load = useCallback(async () => {
    setLoading(true); setError('');
    try { setRows(await ownershipApi.platformQueue()); }
    catch (requestError) { setError(requestError.message || 'Không thể tải hồ sơ chuyển quyền'); }
    finally { setLoading(false); }
  }, []);
  useEffect(() => { void load(); }, [load]);
  const review = async (decision) => {
    if (!reviewing || !reason.trim()) return;
    setBusy(reviewing.id);
    try {
      await ownershipApi.review(reviewing.id, {
        approve: decision === 'APPROVE', needMoreInfo: decision === 'MORE_INFO', reason: reason.trim(),
      });
      toast.success(decision === 'APPROVE' ? 'Đã duyệt hồ sơ' : decision === 'MORE_INFO' ? 'Đã yêu cầu bổ sung' : 'Đã từ chối hồ sơ');
      setReviewing(null); setReason(''); await load();
    } catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  const execute = async (row) => {
    setBusy(row.id);
    try { await ownershipApi.execute(row.id); toast.success('Đã hoàn tất chuyển quyền sở hữu'); await load(); }
    catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  const verifyVersion = async (type, version, approve) => {
    if (!version) return;
    const reasonText = window.prompt(approve ? 'Căn cứ xác minh hợp lệ' : 'Lý do từ chối phiên bản');
    if (!reasonText?.trim()) return;
    const key = `${type}-${version.id}`;
    setBusy(key);
    try {
      const action = type === 'legal' ? ownershipApi.verifyLegalVersion : ownershipApi.verifyPayoutVersion;
      await action(version.id, { approve, reason: reasonText.trim() });
      toast.success(approve ? 'Đã xác minh phiên bản' : 'Đã từ chối phiên bản');
      await load();
    } catch (requestError) { toast.error(requestError.message); }
    finally { setBusy(''); }
  };
  return <Page>
    <PageHeader eyebrow="QUẢN TRỊ PHÁP LÝ" title="Chuyển quyền sở hữu" description="Xác minh hai bên, xem trước tác động và thực thi nguyên tử vào ngày hiệu lực. Lịch sử booking và tài chính không bị đổi chủ ngược." actions={<Button variant="secondary" loading={loading} onClick={load}><RefreshCw size={16} />Làm mới</Button>} />
    {loading ? <Card className="p-5"><Skeleton rows={7} /></Card> : error ? <Card><ErrorState message={error} onRetry={load} /></Card> : !rows.length ? <Card><EmptyState icon={Building2} title="Không có hồ sơ chờ xử lý" /></Card> : <div className="space-y-4">{rows.map((row) => <Card key={row.id} className="p-5"><div className="flex flex-col gap-4 lg:flex-row lg:items-start"><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold">{row.business?.name || row.businessId}</h2><Badge tone={row.status === 'EXECUTION_FAILED' ? 'danger' : 'warning'}>{row.status}</Badge></div><p className="mt-1 text-sm text-[var(--bb-muted)]">{row.requester?.email || row.requestedBy} → {row.newOwner?.email || row.newOwnerUserId} · hiệu lực {stamp(row.effectiveAt)}</p><p className="mt-3 text-sm">{row.reason}</p><dl className="mt-4 grid gap-3 text-sm sm:grid-cols-4"><div><dt className="text-[var(--bb-muted)]">Lịch tương lai</dt><dd className="font-bold">{row.impactSnapshot?.futureBookings || 0}</dd></div><div><dt className="text-[var(--bb-muted)]">Thanh toán chờ</dt><dd className="font-bold">{row.impactSnapshot?.pendingPayments || 0}</dd></div><div><dt className="text-[var(--bb-muted)]">Refund chờ</dt><dd className="font-bold">{row.impactSnapshot?.pendingRefunds || 0}</dd></div><div><dt className="text-[var(--bb-muted)]">Chi nhánh</dt><dd className="font-bold">{row.impactSnapshot?.branches?.length || 0}</dd></div></dl><div className="mt-4 grid gap-3 sm:grid-cols-2"><VersionReview title="Pháp nhân" version={row.legalEntityVersion} type="legal" busy={busy} verify={verifyVersion} /><VersionReview title="Tài khoản nhận tiền" version={row.payoutAccountVersion} type="payout" busy={busy} verify={verifyVersion} /></div>{row.failureReason && <p className="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800">{row.failureReason}</p>}</div><div className="flex shrink-0 flex-wrap gap-2">{row.status === 'UNDER_REVIEW' && <Button onClick={() => { setReviewing(row); setReason(''); }}>Xét duyệt</Button>}{row.status === 'NEED_MORE_INFO' && <Badge tone="warning">Chờ chủ cũ bổ sung</Badge>}{['APPROVED', 'SCHEDULED', 'EXECUTION_FAILED'].includes(row.status) && new Date(row.effectiveAt) <= new Date() && <Button loading={busy === row.id} onClick={() => execute(row)}>Thực thi</Button>}</div></div></Card>)}</div>}
    <Dialog open={Boolean(reviewing)} onClose={() => setReviewing(null)} title="Kết luận xác minh" description={reviewing?.business?.name || 'Hồ sơ chuyển quyền'} footer={<><Button variant="danger" loading={busy === reviewing?.id} disabled={!reason.trim()} onClick={() => review('REJECT')}>Từ chối</Button><Button variant="secondary" loading={busy === reviewing?.id} disabled={!reason.trim()} onClick={() => review('MORE_INFO')}>Cần bổ sung</Button><Button loading={busy === reviewing?.id} disabled={!reason.trim() || !ownershipVersionsReady(reviewing)} onClick={() => review('APPROVE')}>Phê duyệt</Button></>}><Field label="Kết luận / lý do" required><Textarea value={reason} onChange={(event) => setReason(event.target.value)} /></Field>{reviewing && !ownershipVersionsReady(reviewing) && <p className="mt-3 text-sm text-amber-800">Cần xác minh cả pháp nhân và tài khoản nhận tiền trước khi phê duyệt.</p>}</Dialog>
  </Page>;
}

function VersionReview({ title, version, type, busy, verify }) {
  if (!version) return <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"><b>{title}</b><p>Chưa có phiên bản để xác minh.</p></div>;
  return <div className="rounded-lg border border-[var(--bb-border)] p-3 text-sm"><div className="flex flex-wrap items-center justify-between gap-2"><b>{title} v{version.version}</b><Badge tone={version.verificationStatus === 'VERIFIED' ? 'success' : version.verificationStatus === 'REJECTED' ? 'danger' : 'warning'}>{version.verificationStatus}</Badge></div><p className="mt-1 text-xs text-[var(--bb-muted)]">{type === 'legal' ? version.legalName : `${version.bankName} · ${version.maskedAccountNumber}`}</p>{version.verificationStatus === 'PENDING' && <div className="mt-3 flex gap-2"><Button size="sm" loading={busy === `${type}-${version.id}`} onClick={() => verify(type, version, true)}>Xác minh</Button><Button size="sm" variant="ghost" disabled={Boolean(busy)} onClick={() => verify(type, version, false)}>Từ chối</Button></div>}</div>;
}
