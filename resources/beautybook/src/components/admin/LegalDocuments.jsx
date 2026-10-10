import { useEffect, useRef, useState } from 'react';
import { mediaApi } from '../../api/apiClient';
import { Badge, Button, Dialog, EmptyState } from '../ui';
import { documentLabel, statusLabel, statusTone } from '../../utils/displayLabels';
import { currentLegalVersion } from '../../utils/registrationIntent';

export default function LegalDocuments({ documents = [] }) {
  const [selected, setSelected] = useState(null);
  const [preview, setPreview] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const sequence = useRef(0);
  const blobUrl = useRef('');
  const clear = () => { if (blobUrl.current) URL.revokeObjectURL(blobUrl.current); blobUrl.current = ''; };
  useEffect(() => () => { sequence.current += 1; clear(); }, []);
  const close = () => { sequence.current += 1; clear(); setPreview(''); setSelected(null); setBusy(false); setError(''); };
  const open = async (document, version) => {
    const request = ++sequence.current;
    clear(); setPreview(''); setSelected({ document, version }); setBusy(true); setError('');
    try {
      const blob = await mediaApi.download(version.mediaId);
      if (sequence.current !== request) return;
      if (blob.type !== 'application/pdf') throw new Error('Tài liệu không phải PDF có thể xem.');
      blobUrl.current = URL.createObjectURL(blob); setPreview(blobUrl.current);
    } catch (requestError) { if (sequence.current === request) setError(requestError.message || 'Không thể mở tài liệu.'); }
    finally { if (sequence.current === request) setBusy(false); }
  };
  return <>
    {!documents.length ? <EmptyState title="Chưa có tài liệu" /> : <div className="space-y-3">{documents.map((document) => {
      const current = currentLegalVersion(document);
      return <section key={document.id} className="rounded-xl border border-[var(--bb-border)] p-4"><div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-bold">{documentLabel(document.documentType)}</h3><Badge tone={statusTone(document.status)}>{statusLabel(document.status)}</Badge></div>
        <p className="mt-2 text-sm">Phiên bản hiện hành: {document.currentVersion}. {current ? current.documentName : 'Không có tệp hiện hành trong hồ sơ trả về; không dùng bản cũ thay thế.'}</p>
        {current?.mediaId && <Button className="mt-3" size="sm" onClick={() => open(document, current)}>Xem tài liệu hiện hành</Button>}
        <details className="mt-3"><summary className="cursor-pointer text-sm font-semibold">Các phiên bản đã tải ({document.versions?.length || 0})</summary><div className="mt-2 space-y-2">{(document.versions || []).map((version) => <div key={version.id} className="flex flex-wrap items-center justify-between gap-2 border-t border-[var(--bb-border)] pt-2"><p className="min-w-0 break-words text-sm">Phiên bản {version.version} · {version.documentName}{version.version === document.currentVersion ? ' · hiện hành' : ' · lịch sử'}</p>{version.mediaId && <Button size="sm" variant="secondary" onClick={() => open(document, version)}>Xem bản {version.version}</Button>}</div>)}</div></details>
      </section>;
    })}</div>}
    <Dialog open={Boolean(selected)} onClose={close} size="lg" title={selected ? documentLabel(selected.document.documentType) : 'Tài liệu'} description={selected ? 'Phiên bản ' + selected.version.version + (selected.version.version === selected.document.currentVersion ? ' · hiện hành' : ' · bản lịch sử') : ''}>
      {busy ? <p role="status">Đang tải tài liệu riêng tư…</p> : error ? <div role="alert"><p>{error}</p><Button className="mt-3" variant="secondary" onClick={() => open(selected.document, selected.version)}>Thử lại</Button></div> : preview ? <div><p className="mb-3 text-sm text-[var(--bb-muted)]">Nếu trình duyệt không hiển thị PDF, bạn có thể tải đúng phiên bản đang chọn để xem.</p><a className="mb-3 inline-flex min-h-11 items-center font-semibold text-[var(--bb-brand-strong)] underline" href={preview} download={(selected?.version.documentName || "tai-lieu") + ".pdf"}>Tải PDF phiên bản đang chọn</a><iframe title="Nội dung tài liệu pháp lý" src={preview} className="h-[60vh] w-full rounded-lg border border-[var(--bb-border)]" /></div> : null}
    </Dialog>
  </>;
}
