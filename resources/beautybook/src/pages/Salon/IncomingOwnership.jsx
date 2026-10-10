import { useCallback, useEffect, useState } from 'react';
import toast from 'react-hot-toast';
import { ownershipApi } from '../../api/apiClient';
import { Badge, Button, Card, EmptyState, ErrorState, Page, PageHeader, Skeleton } from '../../components/ui';

export default function IncomingOwnership() {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      setRows((await ownershipApi.incoming()) || []);
    } catch (requestError) {
      setError(requestError.message || 'Không thể tải lời mời chuyển chủ');
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const accept = async (id) => {
    setBusy(id);
    try {
      await ownershipApi.accept(id);
      toast.success('Đã xác nhận tiếp nhận; hồ sơ chuyển sang xét duyệt');
      await load();
    } catch (requestError) {
      toast.error(requestError.message || 'Lời mời đã thay đổi; vui lòng tải lại');
      await load();
    } finally {
      setBusy('');
    }
  };

  return <Page>
    <PageHeader eyebrow="Tài khoản doanh nghiệp" title="Lời mời tiếp nhận doanh nghiệp" description="Chỉ những yêu cầu gửi tới tài khoản của bạn được hiển thị tại đây." />
    {error ? <ErrorState message={error} onRetry={load} /> : loading ? <Card className="p-5"><Skeleton rows={5} /></Card> :
      rows.length === 0 ? <Card><EmptyState title="Chưa có lời mời tiếp nhận" description="Khi chủ doanh nghiệp gửi yêu cầu, bạn sẽ thấy tại đây." /></Card> :
      <div className="space-y-3">{rows.map((row) => <Card key={row.id} className="p-5">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="font-bold">{row.scopeSnapshot?.branches?.[0]?.name || 'Chuyển quyền sở hữu doanh nghiệp'}</h2><Badge tone="warning">{row.status}</Badge></div>
            <p className="mt-2 text-sm text-[var(--bb-muted)]">Hiệu lực dự kiến {new Date(row.effectiveAt).toLocaleString('vi-VN')} · {row.reason}</p>
            <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-3"><div><dt>Lịch tương lai</dt><dd className="font-bold">{row.impactSnapshot?.futureBookings || 0}</dd></div><div><dt>Thanh toán chờ</dt><dd className="font-bold">{row.impactSnapshot?.pendingPayments || 0}</dd></div><div><dt>Hoàn tiền chờ</dt><dd className="font-bold">{row.impactSnapshot?.pendingRefunds || 0}</dd></div></dl>
          </div>
          {row.status === 'PENDING_NEW_OWNER_ACCEPTANCE' && <Button loading={busy === row.id} onClick={() => accept(row.id)}>Xác nhận tiếp nhận</Button>}
        </div>
      </Card>)}</div>}
  </Page>;
}
