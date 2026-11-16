import StableSurface from '../ui/StableSurface';
import {useEffect,useRef,useState} from 'react';
import {Search,TicketPercent} from 'lucide-react';
import {publicCatalogApi} from '../../api/publicCatalogApi';
import {customerBookingApi} from '../../api/customerBookingApi';
import {captureSessionGeneration,isSessionGenerationCurrent} from '../../utils/sessionGeneration';
import './booking-promotions.css';

const money=value=>Number(value).toLocaleString('vi-VN',{maximumFractionDigits:2})+' ₫';
export default function BookingPromotions({kind,itemId,recipe,code='',quote,disabled,onChange,onPending}) {
  const [input,setInput]=useState(code),[search,setSearch]=useState(''),[query,setQuery]=useState(''),[page,setPage]=useState(1),[open,setOpen]=useState(false),[list,setList]=useState(null),[loading,setLoading]=useState(false),[checking,setChecking]=useState(false),[error,setError]=useState(''),[message,setMessage]=useState('');
  const scope=JSON.stringify(recipe),generation=useRef(0),gate=useRef(false);
  useEffect(()=>{setInput(code);},[code]);
  useEffect(()=>{generation.current++;return()=>{generation.current++;gate.current=false;onPending?.(false);};},[scope,onPending]);
  useEffect(()=>{
    if(!open)return;let active=true;const lease=captureSessionGeneration();setLoading(true);setList(null);
    publicCatalogApi.promotions(kind,itemId,{search:query||undefined,includeCodes:true,page,limit:6}).then(value=>{if(active&&isSessionGenerationCurrent(lease))setList(value);}).catch(()=>{if(active&&isSessionGenerationCurrent(lease))setList({error:'Chưa tải được ưu đãi. Hãy thử tìm lại.'});}).finally(()=>{if(active)setLoading(false);});
    return()=>{active=false;};
  },[open,kind,itemId,query,page]);
  async function apply(raw){
    if(disabled||gate.current)return;const next=raw.trim().toUpperCase();
    if(!/^[A-Z0-9][A-Z0-9_-]{2,31}$/.test(next)){setError('Nhập mã từ 3–32 ký tự gồm chữ, số, - hoặc _.');return;}
    const request=generation.current,lease=captureSessionGeneration();gate.current=true;setChecking(true);onPending?.(true);setError('');setMessage('');
    try{
      await customerBookingApi.preview({...recipe,promotionCode:next});
      if(request!==generation.current||!isSessionGenerationCurrent(lease))return;
      setInput(next);setMessage('Mã hợp lệ. Hệ thống chọn giá tốt hơn giữa mã và ưu đãi tự động, không cộng dồn.');onChange(next);
    }catch(reason){if(request===generation.current&&isSessionGenerationCurrent(lease))setError(reason.message);}
    finally{gate.current=false;setChecking(false);onPending?.(false);}
  }
  return <StableSurface as="section" className="bb-booking-offers" aria-label="Khuyến mãi áp dụng">
    <header><span className="bb-booking-offers__icon"><TicketPercent size={20}/></span><div><h3>Ưu đãi cho lịch hẹn</h3><p>Ưu đãi tự động vẫn được kiểm tra để chọn giá tốt nhất.</p></div></header>
    <label className="bb-booking-offers__label" htmlFor="booking-promotion-code">Bạn có mã ưu đãi?</label>
    <div className="bb-booking-offers__input"><input id="booking-promotion-code" aria-label="Mã ưu đãi" autoComplete="off" placeholder="Ví dụ: BEAUTY20" maxLength={32} value={input} disabled={disabled||checking} onChange={e=>setInput(e.target.value.toUpperCase())} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();e.stopPropagation();void apply(input);}}}/><button type="button" disabled={disabled||checking||!input.trim()} onClick={()=>void apply(input)}>{checking?'Đang kiểm tra…':'Áp dụng mã'}</button></div>
    {code&&<div className="bb-booking-offers__applied"><span>Đã chọn mã <strong>{code}</strong></span><button type="button" disabled={disabled||checking} onClick={()=>{setInput('');setError('');setMessage('');onChange('');}}>Bỏ mã</button></div>}
    {error&&<p role="alert" className="bb-booking-offers__error">{error}</p>}{message&&<p role="status">{message}</p>}
    {quote&&<p className="bb-booking-offers__saving">{Number(quote.totalDiscountAmount)>0?<>Tổng ưu đãi: <strong>{money(quote.totalDiscountAmount)}</strong>{[...new Set(quote.items.map(i=>i.promotionNameSnapshot).filter(Boolean))].map(name=><span key={name}> · {name}</span>)}</>:'Hiện chưa có ưu đãi giảm thêm cho lựa chọn này.'}</p>}
    <button type="button" className="bb-booking-offers__browse" aria-expanded={open} disabled={disabled||checking} onClick={()=>setOpen(s=>!s)}><Search size={15}/>{open?'Ẩn danh sách ưu đãi':'Tìm ưu đãi của cơ sở'}</button>
    {open&&<div className="bb-booking-offers__catalog"><div className="bb-booking-offers__input"><input aria-label="Tìm tên hoặc mã ưu đãi" placeholder="Tìm theo tên hoặc mã" value={search} disabled={disabled} onChange={e=>setSearch(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();e.stopPropagation();setPage(1);setQuery(search.trim());}}}/><button type="button" disabled={disabled||loading} onClick={()=>{setPage(1);setQuery(search.trim());}}>Tìm ưu đãi</button></div>
      <div className="bb-booking-offers__results" aria-busy={loading}>{loading?<p role="status">Đang tìm ưu đãi…</p>:list?.error?<p role="alert">{list.error}</p>:!list?.items?.length?<p>Chưa có ưu đãi đang hoạt động phù hợp.</p>:list.items.map(p=><article key={p.id}><div><strong>{p.name}</strong><p>Giảm {p.type==='PERCENT'?Number(p.value)+'%':money(p.value)}</p><small>Đến {new Date(p.endsAt).toLocaleDateString('vi-VN',{timeZone:'Asia/Ho_Chi_Minh'})}</small>{p.code&&<p>Mã: <strong>{p.code}</strong></p>}</div>{p.code?<button type="button" disabled={disabled||checking||p.code===code} onClick={()=>void apply(p.code)}>{p.code===code?'Đã chọn':'Dùng mã'}</button>:<small>{p.autoApply?'Tự động kiểm tra':'Chưa có mã'}</small>}</article>)}</div>
      {list?.total>6&&<div className="bb-booking-offers__pages"><button type="button" disabled={disabled||loading||page===1} onClick={()=>setPage(p=>p-1)}>Trước</button><span>Trang {page} / {Math.ceil(list.total/6)}</span><button type="button" disabled={disabled||loading||page*6>=list.total} onClick={()=>setPage(p=>p+1)}>Sau</button></div>}
      <small>Điều kiện được kiểm tra theo dịch vụ, chi nhánh và thời điểm đặt lịch.</small>
    </div>}
  </StableSurface>;
}
