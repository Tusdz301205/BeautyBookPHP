import React from 'react';
import {AlertTriangle, Loader2, Sparkles, Star} from 'lucide-react';
import {ReviewPhotoGallery} from '../../customer/ReviewPhotoGallery';
const bookingReference = id => `#${id}`;
import {ASPECT_TITLES, SENTIMENT_TITLES, STATUS_TITLES, mentionedAspects} from '../../../utils/reviewAbsa';
import {Badge, Card} from '../../ui';

export function ReviewStars({value, size = 15}) {
  return <span className="inline-flex" aria-label={`${value} trên 5 sao`}>{[1, 2, 3, 4, 5].map(star => <Star key={star} size={size} className={star <= value ? 'fill-amber-400 text-amber-400' : 'text-zinc-300'} aria-hidden="true"/>)}</span>;
}

/** AI labels of one review: chips for mentioned aspects only, or a short analysis state. Rating-only reviews have none. */
export function AbsaLabels({absa}) {
  if (absa.status === 'NO_TEXT') return null;
  if (absa.status === 'ANALYZED') {
    const mentioned = mentionedAspects(absa.labels);
    const stale = absa.stale && <span className="bb-absa__ai-stale">Nội dung đã sửa sau lần phân tích</span>;
    if (!mentioned.length) return <p className="bb-absa__ai-state">Không nhắc tới khía cạnh nào.{stale}</p>;
    return <><ul className="bb-absa__chips" aria-label="Cảm xúc AI dự đoán theo khía cạnh">{mentioned.map(({aspect, label}) => <li key={aspect} className={`bb-absa__chip bb-absa__chip--${label}`}><span>{ASPECT_TITLES[aspect]}</span><strong>{SENTIMENT_TITLES[label]}</strong></li>)}</ul>{stale}</>;
  }
  const icon = absa.status === 'PROCESSING' ? <Loader2 size={13} className="bb-absa__spin" aria-hidden="true"/> : absa.status === 'FAILED' ? <AlertTriangle size={13} aria-hidden="true"/> : null;
  return <p className={`bb-absa__ai-state bb-absa__ai-state--${absa.status}`}>{icon}{STATUS_TITLES[absa.status]}</p>;
}

export default function AbsaReviewCard({review}) {
  const name = review.customerName || 'Khách hàng', date = new Date(review.createdAt), services = review.itemRatings || [];
  const absa = review.absa || {status: review.comment ? 'NOT_ANALYZED' : 'NO_TEXT', labels: null};
  return <Card as="article" className="bb-absa__review">
    <header className="bb-absa__review-who">
      <span className="bb-absa__avatar" aria-hidden="true">{name.slice(0, 1).toUpperCase()}</span>
      <div className="bb-absa__who">
        <h3>{name}</h3>
        <ReviewStars value={review.rating}/>
        <p className="bb-absa__meta"><time dateTime={date.toISOString()}>{date.toLocaleDateString('vi-VN', {timeZone: 'Asia/Ho_Chi_Minh'})}</time><span>Lịch hẹn {bookingReference(review.bookingId)}</span></p>
        {review.visibility !== 'PUBLIC' && <Badge tone="neutral" className="bb-absa__private">Riêng tư</Badge>}
      </div>
    </header>
    <div className="bb-absa__review-body">
      {services.length > 0 && <ul className="bb-absa__services" aria-label="Dịch vụ đã đánh giá">{services.map((item, index) => <li key={index}>
        <span className="bb-absa__service-copy"><span>{item.service.serviceNameSnapshot}{item.service.variantNameSnapshot ? ` · ${item.service.variantNameSnapshot}` : ''}</span>
          {(item.service.unitOrdinal !== undefined || item.service.comboNameSnapshot) && <small>{item.service.unitOrdinal !== undefined ? `Lượt dịch vụ ${item.service.unitOrdinal + 1}` : ''}{item.service.comboNameSnapshot ? ` · Combo ${item.service.comboNameSnapshot}` : ''}</small>}
        </span>
        {item.staff && <span className="bb-absa__staff">{item.staff.name}</span>}<ReviewStars value={item.rating} size={11}/>
        {item.comment && <p className="bb-absa__service-comment">{item.comment}</p>}
      </li>)}</ul>}
      {review.comment ? <p className="bb-absa__comment">{review.comment}</p> : <p className="bb-absa__comment bb-absa__comment--empty">Khách chỉ chấm sao, không để lại nhận xét.</p>}
      <ReviewPhotoGallery photos={review.photos}/>
      {absa.status !== 'NO_TEXT' && <div className="bb-absa__ai"><span className="bb-absa__ai-label"><Sparkles size={12} aria-hidden="true"/>AI</span><AbsaLabels absa={absa}/></div>}
    </div>
  </Card>;
}
