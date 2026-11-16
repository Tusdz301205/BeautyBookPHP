import React, {useEffect, useRef, useState} from 'react';
import {FilterX, MessageSquareText, RefreshCw, X} from 'lucide-react';

import {reviewsApi} from '../../api/apiClient';
import {useOwnerResource} from '../../utils/useAbsaResource';
import {useAuthStore} from '../../store/authStore';
import {roleAt} from '../../utils/authScope';

import {ABSA_ASPECTS, ABSA_STATUSES, ASPECT_TITLES, PERIODS, STATUS_TITLES, formatNumber, periodOf, periodRange, validateAbsaReviews, validateAbsaSummary} from '../../utils/reviewAbsa';
import {Button, Card, EmptyState, ErrorState, Field, IconButton, Input, PageHeader, Select, Skeleton} from '../ui';
import AbsaOverview, {AbsaKpis, PipelineNotice} from './absa/AbsaOverview';
import AbsaReviewCard from './absa/AbsaReviewCard';


export {ReviewStars} from './absa/AbsaReviewCard';
export {default as ReviewCard} from './absa/AbsaReviewCard';
export const reviewAverage=value=>value===null?'Chưa có':new Intl.NumberFormat('vi-VN',{maximumFractionDigits:2}).format(value);
const PAGE_SIZE = 10;
const EMPTY = {from: '', to: '', rating: '', visibility: '', aspect: '', sentiment: '', status: '', page: 1};
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const QUICK = [['', 'Tất cả'], ['NEG', 'Có phàn nàn'], ['POS', 'Có lời khen']];

/** Validates one filter patch against the closed vocabularies the API accepts. */
export function sanitizeFilters(next) {
  const f = {...EMPTY, ...next};
  return {
    from: DATE.test(f.from) ? f.from : '', to: DATE.test(f.to) ? f.to : '',
    rating: [1, 2, 3, 4, 5].includes(f.rating) ? f.rating : '',
    visibility: ['PUBLIC', 'PRIVATE'].includes(f.visibility) ? f.visibility : '',
    aspect: ABSA_ASPECTS.includes(f.aspect) ? f.aspect : '', sentiment: ['POS', 'NEU', 'NEG'].includes(f.sentiment) ? f.sentiment : '',
    status: ABSA_STATUSES.includes(f.status) ? f.status : '', page: Number.isInteger(f.page) && f.page >= 1 ? f.page : 1,
  };
}

function scrollToList() {
  if (typeof window === 'undefined') return;
  const behavior = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
  window.requestAnimationFrame(() => document.getElementById('bb-absa-list')?.scrollIntoView({behavior, block: 'start'}));
}

/** Period for the whole page (overview and list). */
function PeriodControl({value, disabled, onChange, onReload}) {
  return <div className="bb-absa__period">
    <Select className="bb-absa__period-select" aria-label="Khoảng thời gian" value={value} disabled={disabled} onChange={e => onChange(e.target.value)}>{PERIODS.map(p => <option key={p.id} value={p.id}>{p.label}</option>)}</Select>
    <IconButton label="Làm mới dữ liệu" variant="secondary" disabled={disabled} onClick={onReload}><RefreshCw size={16} aria-hidden="true"/></IconButton>
  </div>;
}

function CustomRange({filters, onChange}) {
  const invalid = filters.from && filters.to && filters.from > filters.to;
  return <div className="bb-absa__range" role="group" aria-label="Khoảng ngày tùy chọn">
    <Field label="Từ ngày"><Input type="date" aria-label="Từ ngày" value={filters.from} max={filters.to || undefined} onChange={e => onChange({from: e.target.value})}/></Field>
    <Field label="Đến ngày" error={invalid ? 'Ngày kết thúc phải sau ngày bắt đầu' : undefined}><Input type="date" aria-label="Đến ngày" value={filters.to} min={filters.from || undefined} onChange={e => onChange({to: e.target.value})}/></Field>
  </div>;
}

/** Filters that only narrow the list below; the overview above follows the page period. */
function ListToolbar({filters, filtered, onChange, onClear}) {
  const set = patch => onChange({...filters, ...patch, page: 1});
  return <div className="bb-absa__toolbar">
    <div className="bb-absa__segmented" role="group" aria-label="Lọc theo cảm nhận">{QUICK.map(([value, label]) => <button key={label} type="button" aria-pressed={filters.sentiment === value && Boolean(value || !filters.status)} onClick={() => set({sentiment: value, status: ''})}>{label}</button>)}</div>
    <Select className="bb-absa__toolbar-select" aria-label="Lọc theo khía cạnh" value={filters.aspect} onChange={e => set({aspect: e.target.value})}><option value="">Mọi khía cạnh</option>{ABSA_ASPECTS.map(a => <option key={a} value={a}>{ASPECT_TITLES[a]}</option>)}</Select>
    <Select className="bb-absa__toolbar-select" aria-label="Lọc theo số sao" value={String(filters.rating)} onChange={e => set({rating: e.target.value ? Number(e.target.value) : ''})}><option value="">Mọi số sao</option>{[5, 4, 3, 2, 1].map(v => <option key={v} value={v}>{v} sao</option>)}</Select>
    <Select className="bb-absa__toolbar-select" aria-label="Hiển thị đánh giá" value={filters.visibility} onChange={e => set({visibility: e.target.value})}><option value="">Mọi hiển thị</option><option value="PUBLIC">Công khai</option><option value="PRIVATE">Riêng tư</option></Select>
    <Select className="bb-absa__toolbar-select" aria-label="Trạng thái phân tích AI" value={filters.status} onChange={e => set({status: e.target.value})}><option value="">Mọi trạng thái AI</option>{ABSA_STATUSES.map(status=><option key={status} value={status}>{STATUS_TITLES[status]}</option>)}</Select>
    {filters.status && <span className="bb-absa__status-chip">{STATUS_TITLES[filters.status]}<button type="button" aria-label={`Bỏ lọc ${STATUS_TITLES[filters.status].toLowerCase()}`} onClick={() => set({status: ''})}><X size={13} aria-hidden="true"/></button></span>}
    {filtered && <Button variant="ghost" size="sm" className="bb-absa__clear" onClick={onClear}><FilterX size={15} aria-hidden="true"/>Xóa lọc</Button>}
  </div>;
}

export default function ReviewList({businessId, branchId}) {
  const user = useAuthStore(s => s.user), owner = roleAt(user, 'BUSINESS_OWNER', businessId);
  const revision = useAuthStore(s => s.contextRevision ?? 0), mounted = useRef(false), rendered = useRef(null);
  const view = {lease: useAuthStore.getState().accessToken}; rendered.current = view;
  useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);
  const scope = JSON.stringify([businessId, branchId]), fresh = {scope, filters: EMPTY, custom: false};
  const [stored, setStored] = useState(fresh);
  if (stored.scope !== scope) setStored(fresh);
  const {filters, custom} = stored.scope === scope ? stored : fresh;
  const invalidRange = Boolean(filters.from && filters.to && filters.from > filters.to);
  const base = {permission: 'branch:update:tenant', businessId: owner && !invalidRange ? businessId : null, branchId, requiredBranch: true};
  const summaryQuery = {from: filters.from, to: filters.to};
  const summary = useOwnerResource({...base, resourceKey: JSON.stringify(['absa-summary', summaryQuery]),
    read: async () => validateAbsaSummary(await reviewsApi.absaSummary(branchId, summaryQuery), {businessId, branchId})});
  const list = useOwnerResource({...base, resourceKey: JSON.stringify(['absa-reviews', filters]),
    read: async () => validateAbsaReviews(await reviewsApi.absaReviews(branchId, {...filters, limit: PAGE_SIZE}))});
  const update = (next, nextCustom = custom) => {
    const current = useAuthStore.getState();
    if (!mounted.current || rendered.current !== view || !(useAuthStore.getState().accessToken === view.lease) ||
        current.user?.id !== user?.id || current.user?.sessionId !== user?.sessionId ||
        (current.contextRevision ?? 0) !== revision || current.activeContext().tenantId !== businessId ||
        !roleAt(current.user, 'BUSINESS_OWNER', businessId) || !current.can('branch:update:tenant', {tenantId: businessId, branchId})) return false;
    setStored({scope, filters: sanitizeFilters(next), custom: nextCustom});
    return true;
  };
  const locked = !list.ready;
  const listFiltered = Boolean(filters.aspect || filters.sentiment || filters.status || filters.rating || filters.visibility);
  const period = custom ? 'custom' : periodOf(filters);
  const setPeriod = id => id === 'custom' ? update(filters, true) : update({...filters, ...periodRange(id), page: 1}, false);
  const show = (aspect, sentiment) => {
    const same = filters.aspect === aspect && filters.sentiment === sentiment && !filters.status;
    if (update({...filters, aspect: same ? '' : aspect, sentiment: same ? '' : sentiment, status: '', page: 1}) && !same) scrollToList();
  };
  const showStatus = status => { if (update({...filters, aspect: '', sentiment: '', status, page: 1})) scrollToList(); };
  const clearListFilters = () => update({...filters, aspect: '', sentiment: '', status: '', rating: '', visibility: '', page: 1});
  const goPage = page => { if (list.ready && page >= 1 && page <= pages && update({...filters, page})) scrollToList(); };
  const reload = () => { void summary.reload(); void list.reload(); };

  if (!owner) return <Card><ErrorState message="Chọn chi nhánh thuộc quyền Chủ doanh nghiệp để xem đánh giá."/></Card>;
  const pages = list.ready ? Math.max(1, Math.ceil(list.value.total / PAGE_SIZE)) : 1;
  const periodEmpty = !listFiltered && (filters.from || filters.to);
  return <div className="bb-absa">
    <PageHeader eyebrow="Chất lượng dịch vụ" title="Đánh giá khách hàng" actions={<PeriodControl value={period} disabled={false} onChange={setPeriod} onReload={reload}/>}/>
    {period === 'custom' && <CustomRange filters={filters} onChange={patch => update({...filters, ...patch, page: 1}, true)}/>}
    {invalidRange ? <Card><EmptyState icon={FilterX} title="Khoảng ngày không hợp lệ" description="Chọn ngày kết thúc sau ngày bắt đầu."/></Card> : <>
      <section className="bb-absa__summary" aria-label="Tổng quan đánh giá">
        {!summary.allowed ? <Card><ErrorState message="Bạn không có quyền xem phân tích của chi nhánh này."/></Card>
          : summary.error ? <Card><ErrorState message="Không thể tải tổng quan đánh giá." onRetry={summary.reload}/></Card>
          : !summary.ready ? <div className="bb-absa__kpis" aria-busy="true"><Skeleton rows={1}/><Skeleton rows={1}/><Skeleton rows={1}/><Skeleton rows={1}/></div>
          : <>
            <AbsaKpis summary={summary.value} activeAspect={filters.aspect} activeSentiment={filters.status ? '' : filters.sentiment} onShow={show}/>
            <PipelineNotice summary={summary.value} onShowStatus={showStatus}/>
            {summary.value.totals.reviews > 0 && summary.value.store.ready && <AbsaOverview summary={summary.value} activeAspect={filters.aspect} activeSentiment={filters.sentiment} onShow={show}/>}
          </>}
      </section>
      <section id="bb-absa-list" className="bb-absa__list" aria-labelledby="bb-absa-list-title">
        <div className="bb-absa__list-head"><h2 id="bb-absa-list-title">Danh sách đánh giá</h2>
          <p aria-live="polite">{list.ready ? `${formatNumber(list.value.total)} đánh giá${listFiltered ? ' phù hợp' : ''}` : 'Đang tải…'}</p></div>
        <ListToolbar filters={filters} filtered={listFiltered} onChange={update} onClear={clearListFilters}/>
        {!list.allowed ? null : list.error ? <Card><ErrorState message="Không thể tải danh sách đánh giá." onRetry={list.reload}/></Card>
          : !list.ready ? <Skeleton rows={4}/>
          : !list.value.items.length ? <Card><EmptyState icon={MessageSquareText}
              title={listFiltered ? 'Không có đánh giá phù hợp bộ lọc' : periodEmpty ? 'Không có đánh giá trong khoảng thời gian này' : 'Chi nhánh chưa có đánh giá'}
              description={listFiltered ? 'Thử bỏ bớt điều kiện lọc.' : periodEmpty ? 'Chọn khoảng thời gian dài hơn để xem thêm.' : 'Đánh giá của khách sẽ xuất hiện sau khi lịch hẹn hoàn thành.'}
              action={listFiltered ? <Button variant="secondary" onClick={clearListFilters}>Xóa lọc</Button> : periodEmpty ? <Button variant="secondary" onClick={() => setPeriod('all')}>Xem toàn bộ thời gian</Button> : null}/></Card>
          : <div className="bb-absa__reviews">{list.value.items.map(review => <AbsaReviewCard key={review.id} review={review}/>)}</div>}
        {list.ready && list.value.total > PAGE_SIZE && <nav className="bb-absa__pager" aria-label="Phân trang đánh giá"><span>Trang {filters.page}/{pages}</span><div>
          <Button variant="secondary" size="sm" disabled={locked || filters.page <= 1} onClick={() => goPage(filters.page - 1)}>Trang trước</Button>
          <Button variant="secondary" size="sm" disabled={locked || filters.page >= pages} onClick={() => goPage(filters.page + 1)}>Trang sau</Button></div></nav>}
      </section>
    </>}
  </div>;
}
