import React from 'react';
import {AlertTriangle, ChevronRight, Clock3, Frown, Loader2, MessageSquareText, Scissors, Smile, Star, Tag, UserRound} from 'lucide-react';
import {ASPECT_TITLES, LOW_SAMPLE, SENTIMENT_TITLES, attentionAspect, formatAverage, formatNumber, percent, segments} from '../../../utils/reviewAbsa';
import {ReviewStars} from './AbsaReviewCard';
import '../../../styles/owner-review-analysis.css';

const ASPECT_ICONS = {SERVICE_QUALITY: Scissors, STAFF_ATTITUDE: UserRound, WAIT_TIME: Clock3, PRICE: Tag};

/** One KPI tile. With `action` it is a toggle button that filters the review list. */
function Kpi({icon: Icon, tone, label, value, suffix, note, action}) {
  const body = <>
    <span className={`bb-absa__kpi-icon bb-absa__tone--${tone}`}><Icon size={17} aria-hidden="true"/></span>
    <span className="bb-absa__kpi-value">{value}{suffix && <small>{suffix}</small>}</span>
    <span className="bb-absa__kpi-label">{label}</span>
    {note && <span className="bb-absa__kpi-note">{note}</span>}
    {action && <span className="bb-absa__kpi-cta" aria-hidden="true">{action.pressed ? 'Đang lọc' : 'Xem'}<ChevronRight size={14}/></span>}
  </>;
  return action
    ? <button type="button" className="bb-absa__kpi is-action" aria-pressed={action.pressed} aria-label={action.label} onClick={action.onClick}>{body}</button>
    : <div className="bb-absa__kpi">{body}</div>;
}

export function AbsaKpis({summary, activeAspect = '', activeSentiment = '', onShow}) {
  const {totals, sentimentReviews} = summary, analyzed = sentimentReviews.denominator, ai = summary.store.ready && analyzed > 0;
  const base = analyzed === totals.withText ? 'đánh giá có nhận xét' : `trong ${formatNumber(analyzed)} đánh giá đã phân tích`;
  const sentimentKpi = (sentiment, count, label, icon, tone) => {
    const pressed = !activeAspect && activeSentiment === sentiment;
    return <Kpi icon={icon} tone={tone} label={label} value={ai ? formatNumber(count) : '—'} note={ai ? `${percent(count / analyzed)} ${base}` : 'Chưa có dữ liệu AI'}
      action={ai && count && onShow ? {pressed, label: `${pressed ? 'Bỏ lọc' : 'Xem'} ${formatNumber(count)} đánh giá ${label.toLowerCase()}`, onClick: () => onShow('', sentiment)} : null}/>;
  };
  return <div className="bb-absa__kpis">
    <Kpi icon={MessageSquareText} tone="brand" label="Tổng đánh giá" value={formatNumber(totals.reviews)}
      note={totals.reviews ? `${formatNumber(totals.withText)} có nhận xét${totals.noText ? ` · ${formatNumber(totals.noText)} chỉ chấm sao` : ''}` : 'Chưa có đánh giá'}/>
    <Kpi icon={Star} tone="star" label="Điểm trung bình" value={formatAverage(totals.ratingAverage)} suffix={totals.ratingAverage == null ? null : '/5'}
      note={totals.ratingAverage == null ? null : <ReviewStars value={Math.round(totals.ratingAverage)} size={13}/>}/>
    {sentimentKpi('POS', sentimentReviews.withPositive, 'Có lời khen', Smile, 'success')}
    {sentimentKpi('NEG', sentimentReviews.withNegative, 'Có phàn nàn', Frown, 'danger')}
  </div>;
}

/** Shown only when the AI pipeline needs attention; the statistics exclude reviews that are not analysed yet. */
export function PipelineNotice({summary, onShowStatus}) {
  const {totals, store} = summary;
  if (!store.ready) return <p className="bb-absa__notice bb-absa__notice--warning" role="status"><AlertTriangle size={16} aria-hidden="true"/><span>Phân tích AI hiện chưa khả dụng. Đánh giá vẫn hiển thị đầy đủ.</span></p>;
  const items = [
    totals.processing && {status: 'PROCESSING', text: `${formatNumber(totals.processing)} đánh giá đang chờ AI phân tích`},
    totals.failed && {status: 'FAILED', text: `${formatNumber(totals.failed)} đánh giá AI chưa phân tích được`},
    totals.notAnalyzed && {status: 'NOT_ANALYZED', text: `${formatNumber(totals.notAnalyzed)} đánh giá chưa được phân tích`},
  ].filter(Boolean);
  if (!items.length) return null;
  const Icon = totals.processing && !totals.failed ? Loader2 : AlertTriangle;
  return <div className={`bb-absa__notice${totals.failed ? ' bb-absa__notice--warning' : ''}`} role="status">
    <Icon size={16} className={Icon === Loader2 ? 'bb-absa__spin' : undefined} aria-hidden="true"/>
    <p>{items.map((item, index) => <span key={item.status}>{index > 0 && ' · '}{item.text}{onShowStatus && <button type="button" className="bb-absa__link" onClick={() => onShowStatus(item.status)}>Xem</button>}</span>)}</p>
  </div>;
}

export function AspectCard({row, analyzed, active, attention, onShow}) {
  const Icon = ASPECT_ICONS[row.aspect], parts = segments(row.counts), title = ASPECT_TITLES[row.aspect];
  const describe = parts.map(p => `${SENTIMENT_TITLES[p.label]} ${p.count}`).join(', ');
  const pill = (sentiment, label) => <button type="button" className={`bb-absa__pill bb-absa__pill--${sentiment}`} aria-pressed={active === sentiment} disabled={!row.counts[sentiment]}
    aria-label={`${active === sentiment ? 'Bỏ lọc' : 'Xem'} ${label.toLowerCase()} về ${title.toLowerCase()} (${row.counts[sentiment]})`} onClick={() => onShow(row.aspect, sentiment)}>{label}<span>{row.counts[sentiment]}</span></button>;
  return <article className={`bb-owner-analysis__row${active ? ' is-active' : ''}`} aria-label={title}>
    <div className="bb-owner-analysis__aspect">
      <header className="bb-absa__aspect-head"><span className="bb-absa__aspect-icon"><Icon size={17} aria-hidden="true"/></span><h3>{title}</h3></header>
      <p className="bb-absa__mentions">Được nhắc trong <strong>{formatNumber(row.mentioned)}</strong>/{formatNumber(analyzed)} đánh giá đã phân tích</p>
      {row.mentioned > 0 && row.mentioned < LOW_SAMPLE && <span className="bb-absa__low">Ít dữ liệu</span>}
      {attention && row.mentioned >= LOW_SAMPLE && <span className="bb-owner-analysis__attention">Tỷ lệ phàn nàn cao nhất</span>}
    </div>
    <div className="bb-owner-analysis__distribution">
      <div className="bb-absa__bar" role="img" aria-label={`${title}: ${describe}`}>{parts.map(p => p.width > 0 && <span key={p.label} className={`bb-absa__fill--${p.label}`} style={{width: `${p.width}%`}}/>)}</div>
      {row.mentioned ? <dl className="bb-absa__split">{parts.map(p => <div key={p.label}><dt><i className={`bb-absa__dot bb-absa__fill--${p.label}`} aria-hidden="true"/>{SENTIMENT_TITLES[p.label]}</dt><dd>{formatNumber(p.count)}<span>{percent(p.count / row.mentioned)}</span></dd></div>)}</dl>
        : <p className="bb-absa__none">{analyzed ? 'Chưa có đánh giá nào nhắc tới.' : 'Chưa có dữ liệu AI.'}</p>}
    </div>
    <div className="bb-absa__aspect-actions" role="group" aria-label={`Lọc đánh giá về ${title.toLowerCase()}`}>{pill('NEG', 'Phàn nàn')}{pill('POS', 'Lời khen')}</div>
  </article>;
}

export default function AbsaOverview({summary, activeAspect, activeSentiment, onShow}) {
  const analyzed = summary.totals.analyzed, attention = attentionAspect(summary.aspects);
  return <section className="bb-absa__overview bb-owner-analysis" aria-labelledby="bb-absa-aspects">
    <div className="bb-absa__section-head">
      <div><h2 id="bb-absa-aspects">Cảm nhận theo khía cạnh</h2></div>
    </div>
    <div className="bb-owner-analysis__comparison">
      <div className="bb-owner-analysis__columns" aria-hidden="true"><span>Khía cạnh</span><span>Phân bố cảm nhận</span><span>Xem đánh giá</span></div>
      {summary.aspects.map(row => <AspectCard key={row.aspect} row={row} analyzed={analyzed} attention={attention === row.aspect}
        active={activeAspect === row.aspect ? activeSentiment || 'ANY' : ''} onShow={onShow}/>)}
    </div>
  </section>;
}
