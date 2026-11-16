import {useEffect,useRef,useState} from 'react';
import {Link} from 'react-router-dom';
import {Star,MessageCircle} from 'lucide-react';
import {reviewsApi} from '../../api/apiClient';

// GlowMe's review preview, adapted to the PHP public review response.
export default function ServiceCardReviews({serviceId,detailState}){
 const root=useRef(null),[visible,setVisible]=useState(false),[revision,reload]=useState(0),[state,setState]=useState({loading:true});
 useEffect(()=>{if(!window.IntersectionObserver){setVisible(true);return;}const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)){setVisible(true);observer.disconnect();}},{rootMargin:'150px'});if(root.current)observer.observe(root.current);return()=>observer.disconnect();},[serviceId]);
 useEffect(()=>{if(!visible)return;let active=true;setState({loading:true});reviewsApi.getByService(serviceId).then(data=>{if(!Array.isArray(data?.ratings)||!Number.isSafeInteger(Number(data.totalRatings))||Number(data.totalRatings)<0||(Number(data.totalRatings)>0&&(!Number.isFinite(Number(data.averageRating))||Number(data.averageRating)<1||Number(data.averageRating)>5)))throw Error('Chưa tải được đánh giá.');if(active)setState({data});}).catch(()=>{if(active)setState({error:true});});return()=>{active=false;};},[serviceId,visible,revision]);
 const data=state.data,latest=data?.ratings.find(row=>row.comment?.trim());
 return <div ref={root} className="bb-service-review-preview">
  {state.error?<button type="button" className="bb-service-review-preview__retry" onClick={()=>reload(value=>value+1)}><MessageCircle size={14}/>Tải lại đánh giá</button>:!data?<span className="bb-service-review-preview__loading" role="status">Đang tải đánh giá…</span>:Number(data.totalRatings)>0?<><Link className="bb-service-review-preview__rating" to={`/explore/services/${serviceId}#detail-section-6`} state={detailState}><Star size={15} fill="currentColor"/><strong>{Number(data.averageRating).toLocaleString('vi-VN',{minimumFractionDigits:1,maximumFractionDigits:1})}</strong><span>/ 5</span><span className="bb-service-review-preview__count">· {data.totalRatings} lượt đánh giá</span></Link>{latest&&<blockquote><p>“{latest.comment}”</p><cite>{latest.customerName||'Khách hàng'}</cite></blockquote>}</>:<p className="bb-service-review-preview__empty"><Star size={14}/>Chưa có đánh giá dịch vụ</p>}
 </div>;
}
