import StableSurface from '../../components/ui/StableSurface';
import {useEffect,useRef,useState} from 'react';
import {Scissors, UserRound} from 'lucide-react';
import {publicCatalogApi} from '../../api/publicCatalogApi';
import {publicPageEnvelope} from '../../utils/publicCatalog';

const price=value=>Number(value).toLocaleString('vi-VN',{maximumFractionDigits:2})+' ₫';
// Public choices remain mounted while the booking quote is recalculated.
export function BookingOptions({kind,id,branchId,baseOption,onVariant,onStaff,disabled=false,panel="all",presentation="select",variantId,staffId,onReady}){
 const [variants,setVariants]=useState({items:[],total:0,page:0}),[staff,setStaff]=useState({items:[],total:0,page:0}),[error,setError]=useState('');
 const [loading,setLoading]=useState(false),[retry,setRetry]=useState(0);
 const scope=JSON.stringify([kind,id,branchId]),[loadedScope,setLoadedScope]=useState(''),liveScope=useRef(scope),life=useRef(null),paging=useRef(false);liveScope.current=scope;
 useEffect(()=>{const owner={active:true};life.current=owner;return()=>{owner.active=false;};},[]);
 const optionsLoading=loading||loadedScope!==scope;
 const visibleVariants=loadedScope===scope?variants.items.filter(v=>v.active&&v.serviceId===id):[],visibleStaff=loadedScope===scope?staff.items.filter(s=>s.branchId===branchId):[];
 const selectedVariant=visibleVariants.find(v=>v.id===variantId),selectedStaff=visibleStaff.find(s=>s.id===staffId);
 const ready=!optionsLoading&&!error&&variants.page>0&&staff.page>0&&(panel==='staff'||!variantId||Boolean(selectedVariant))&&(panel==='variants'||!staffId||Boolean(selectedStaff));
 useEffect(()=>{onReady?.({panel,variantId,staffId,ready,variantName:selectedVariant?.name,staffName:selectedStaff?.name});},[panel,variantId,staffId,ready,selectedVariant?.name,selectedStaff?.name,onReady]);
 useEffect(()=>{let active=true;setLoading(true);setError('');void Promise.all([
  kind==='service'?publicCatalogApi.variants(id,{page:1,limit:50}):Promise.resolve({items:[],total:0,page:1,limit:50}),
  kind==='service'?publicCatalogApi.staff({branchId,serviceId:id,page:1,limit:50}):Promise.resolve({items:[],total:0,page:1,limit:50}),
 ]).then(([v,s])=>{if(active){setVariants(publicPageEnvelope(v,{page:1,limit:50}));setStaff(publicPageEnvelope(s,{page:1,limit:50}));setLoadedScope(scope);}}).catch(e=>{if(active)setError(e.message);}).finally(()=>{if(active)setLoading(false);});return()=>{active=false;};},[id,kind,branchId,retry]);
 async function more(type){
  if(disabled||optionsLoading||paging.current)return;
  const owner=life.current,current=type==='variants'?variants:staff;const valid=()=>owner?.active&&life.current===owner&&liveScope.current===scope;
  paging.current=true;setLoading(true);setError('');
  try{const query={page:current.page+1,limit:50},raw=type==='variants'?await publicCatalogApi.variants(id,query):await publicCatalogApi.staff({branchId,...(kind==='service'?{serviceId:id}:{}),...query}),next=publicPageEnvelope(raw,query);if(valid())(type==='variants'?setVariants:setStaff)({...next,items:[...current.items,...next.items]});}
  catch(e){if(valid())setError(e.message);}finally{paging.current=false;if(valid())setLoading(false);}
 }
 const cards=presentation==='cards';
 const optionMeta=row=>[row.price!==undefined?price(row.price):'',row.durationMinutes?row.durationMinutes+' phút':''].filter(Boolean).join(' · ');
 function choices(type,label,items,value,callback){
  const base=type==='staff'?{id:'',name:'Cơ sở tự phân công'}:{...baseOption,id:'',name:'Tiêu chuẩn'},Icon=type==='staff'?UserRound:Scissors;
  return <fieldset className="bb-booking-options" disabled={disabled||optionsLoading}><legend>{label}</legend><div className="bb-booking-card-grid">{[base,...items].map(row=><button key={row.id} type="button" className="bb-booking-selection" aria-pressed={(value||'')===row.id} onClick={()=>{if(!disabled&&!optionsLoading&&(value||'')!==row.id)callback(row.id);}}><span className="bb-booking-selection__icon" aria-hidden="true"><Icon size={19} strokeWidth={1.6}/></span><span className="bb-booking-selection__body"><strong className="bb-booking-selection__title">{row.name}</strong><small className="bb-booking-selection__meta">{type==='staff'?(row.id?(row.professionalTitle||'Nhân viên tại chi nhánh'):'Cơ sở chọn người phù hợp với giờ bạn đặt'):(optionMeta(row)||'Giá và thời lượng được kiểm tra theo lựa chọn')}</small></span></button>)}</div>{(type==='variants'?variants:staff).items.length<(type==='variants'?variants:staff).total&&<button type="button" disabled={disabled||optionsLoading} className="bb-home-text-link" onClick={()=>more(type)}>{type==='variants'?'Xem thêm lựa chọn':'Xem thêm nhân viên'}</button>}</fieldset>;
 }
 return <StableSurface as="div" className={cards?'bb-booking-options-panels':'bb-booking-options-select grid min-w-0 gap-4 sm:grid-cols-2'}>
 {cards&&kind==='service'&&panel!=='staff'&&choices('variants','Lựa chọn dịch vụ',visibleVariants,variantId,onVariant)}
 {cards&&panel!=='variants'&&(kind==='service'?choices('staff','Nhân viên',visibleStaff,staffId,onStaff):<p>Nhân viên phù hợp được cơ sở tự phân công cho từng dịch vụ trong gói dịch vụ.</p>)}
 {!cards&&panel!=='staff'&&kind==='service'&&<label className="grid gap-2">Lựa chọn dịch vụ<select className="min-w-0 w-full rounded-lg border p-3" aria-label="Lựa chọn dịch vụ" value={variantId} disabled={disabled||optionsLoading} onChange={e=>onVariant(e.target.value)}><option value="">Tiêu chuẩn{baseOption?' · '+optionMeta(baseOption):''}</option>{visibleVariants.map(v=><option key={v.id} value={v.id}>{v.name} · {optionMeta(v)}</option>)}</select>{variants.items.length<variants.total&&<button type="button" disabled={disabled||optionsLoading} className="bb-home-text-link" onClick={()=>more('variants')}>Xem thêm lựa chọn</button>}</label>}
 {!cards&&panel!=='variants'&&(kind==='service'?<label className="grid gap-2">Nhân viên<select className="min-w-0 w-full rounded-lg border p-3" aria-label="Nhân viên" value={staffId} disabled={disabled||optionsLoading} onChange={e=>onStaff(e.target.value)}><option value="">Cơ sở tự phân công</option>{visibleStaff.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select>{staff.items.length<staff.total&&<button type="button" disabled={disabled||optionsLoading} className="bb-home-text-link" onClick={()=>more('staff')}>Xem thêm nhân viên</button>}</label>:<p>Nhân viên phù hợp được cơ sở tự phân công cho từng dịch vụ trong gói dịch vụ.</p>)}
 <div className="bb-booking-options-status">{!optionsLoading&&!error&&loadedScope===scope&&!ready&&<p role="alert">Lựa chọn không còn khả dụng. Vui lòng chọn lại.</p>}{optionsLoading&&!error&&<p role="status">Đang tải lựa chọn…</p>}{error&&<div role="alert"><p>{error}</p><button type="button" className="bb-home-text-link" disabled={disabled||loading} onClick={()=>setRetry(x=>x+1)}>Tải lại lựa chọn</button></div>}</div>
 </StableSurface>;
}
