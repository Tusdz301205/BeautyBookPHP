import {policyCacheFresh,shouldReloadAvailability} from '../../utils/customerBooking/previewCache';
import DatePicker from '../../components/ui/DatePicker';
import DepositPanel from '../../components/payments/DirectDepositPanel';
import {bookingReference} from '../../utils/humanReference';
import {useEffect,useLayoutEffect,useRef,useState} from 'react';
import {Link} from 'react-router-dom';
import {customerBookingApi} from '../../api/customerBookingApi';
import {useAuthStore} from '../../store/authStore';
import {captureSessionGeneration,isSessionGenerationCurrent} from '../../utils/sessionGeneration';
const publicMoney=value=>Number(value).toLocaleString('vi-VN')+' ₫';
import {BookingOptions} from './BookingOptions';
import BookingPromotions from '../../components/booking/BookingPromotions';
import BookingSummary, {BookingPriceBreakdown} from '../../components/booking/BookingSummary';
import '../../styles/glowme/customer-booking-flow.css';

const localDay=()=>new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Ho_Chi_Minh',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
export const bookingTime=value=>new Intl.DateTimeFormat('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',hour:'2-digit',minute:'2-digit',day:'2-digit',month:'2-digit',year:'numeric'}).format(new Date(value));

const steps=['Dịch vụ','Nhân viên','Thời gian','Thông tin','Xác nhận'];
const clock=value=>new Intl.DateTimeFormat('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',hour:'2-digit',minute:'2-digit'}).format(new Date(value));
const dayLabel=value=>new Intl.DateTimeFormat('vi-VN',{timeZone:'Asia/Ho_Chi_Minh',weekday:'short',day:'2-digit',month:'2-digit'}).format(new Date(value+'T12:00:00+07:00'));
const upcomingDays=()=>Array.from({length:7},(_,i)=>new Date(Date.parse(localDay()+'T12:00:00+07:00')+i*86400000).toISOString().slice(0,10));
export default function CustomerBookingForm({kind,item,branch,onLockChange}){
 const user=useAuthStore(s=>s.user),[variantId,setVariant]=useState(''),[staffId,setStaff]=useState(''),[quantity,setQuantity]=useState(1),[date,setDate]=useState(localDay);
 const [contact,setContact]=useState({name:user?.fullName||'',phone:'',email:user?.email||''}),[note,setNote]=useState(''),[ack,setAck]=useState(false);
 const [slot,setSlot]=useState(null),[report,setReport]=useState({}),[reload,setReload]=useState(0),[error,setError]=useState(''),[busy,setBusy]=useState(false),[uncertain,setUncertain]=useState(false),[receipt,setReceipt]=useState(null);
 const [policyRevision,setPolicyRevision]=useState(0);
 const [promotionCode,setPromotionCode]=useState(''),[promotionChecking,setPromotionChecking]=useState(false);
 const [step,setStep]=useState(1),[consent,setConsent]=useState(false),[optionState,setOptionState]=useState({});
 const selections=[{[kind==='service'?'serviceId':'comboId']:item.id,quantity,...(variantId?{variantId}:{}),...(staffId?{staffId}:{})}],recipe={branchId:branch.id,selections,...(promotionCode?{promotionCode}:{})};
 const slotKey=JSON.stringify([selections,date,user?.sessionId,reload]),key=JSON.stringify([recipe,date,user?.sessionId,reload,slot?.start,policyRevision]),renderKey=useRef(key),life=useRef(null),attempt=useRef(null),sending=useRef(false),lease=captureSessionGeneration();renderKey.current=key;
 const dataScope=JSON.stringify([kind,item.id,branch.id,user?.id,user?.sessionId]);
 const availabilityCache=useRef(null),policyCache=useRef(null);
 const policyKey=JSON.stringify([branch.id,user?.id,user?.sessionId,reload,policyRevision]);
 useEffect(()=>{const owner={active:true};life.current=owner;return()=>{owner.active=false;};},[]);
 useEffect(()=>{
  let active=true;const controller=new AbortController();setAck(false);
  void(async()=>{try{
   const policyPromise=policyCacheFresh(policyCache.current,policyKey)?Promise.resolve(policyCache.current.value):customerBookingApi.policy({branchId:branch.id,businessId:branch.businessId}).then(value=>{if(active&&isSessionGenerationCurrent(lease))policyCache.current={key:policyKey,value,readAt:Date.now()};return value;});
   const availabilityPromise=availabilityCache.current?.key===slotKey?Promise.resolve(availabilityCache.current.value):customerBookingApi.slots({...recipe,date},null,{signal:controller.signal}).then(value=>{if(active&&isSessionGenerationCurrent(lease))availabilityCache.current={key:slotKey,value};return value;});
   const pricingPromise=Promise.all([customerBookingApi.preview({...recipe,...(slot?.key===slotKey?{startAt:slot.start}:{})},{signal:controller.signal}),policyPromise]);
   void pricingPromise.then(([quote,policy])=>{if(active&&renderKey.current===key&&isSessionGenerationCurrent(lease))setReport({key,scope:dataScope,quote,policy,pending:true});},()=>{});
   const [[quote,policy],availability]=await Promise.all([pricingPromise,availabilityPromise]);
   if(active&&renderKey.current===key&&isSessionGenerationCurrent(lease))setReport({key,scope:dataScope,quote,policy,availability});
  }catch(reason){if(active&&renderKey.current===key&&isSessionGenerationCurrent(lease))setReport({key,scope:dataScope,error:reason.message});}})();
  return()=>{active=false;controller.abort();};
 },[key]);
 useEffect(()=>{const restriction=report.policy?.restriction;if(!restriction?.active||busy||uncertain||receipt)return;const end=new Date(restriction.ends_at).getTime();if(!Number.isFinite(end))return;const timer=setTimeout(()=>{policyCache.current=null;setPolicyRevision(x=>x+1);},Math.min(2147483647,Math.max(250,end-Date.now()+100)));return()=>clearTimeout(timer);},[report.policy,busy,uncertain,receipt]);
 useEffect(()=>{if(!busy&&!uncertain)return;const warn=e=>{e.preventDefault();e.returnValue='';};window.addEventListener('beforeunload',warn);return()=>window.removeEventListener('beforeunload',warn);},[busy,uncertain]);
 const heading=useRef(null);
 const headingPosition=useRef({step,receiptId:receipt?.id});
 useEffect(()=>{if(headingPosition.current.step===step&&headingPosition.current.receiptId===receipt?.id)return;headingPosition.current={step,receiptId:receipt?.id};const target=heading.current;if(target){target.focus({preventScroll:true});target.scrollIntoView({block:'start',behavior:'auto'});}},[step,receipt?.id]);
 const current=report.key===key&&!report.pending?report:null,selected=slot?.key===slotKey?current?.availability?.slots.find(s=>s.start===slot.start):null,locked=busy||uncertain||Boolean(receipt)||promotionChecking;
 const slotsViewport=useRef(null),slotsHeight=useRef(0);
 useLayoutEffect(()=>{if(current?.availability&&slotsViewport.current)slotsHeight.current=slotsViewport.current.getBoundingClientRect().height;},[current?.availability,step]);
 useEffect(()=>{onLockChange?.(busy||uncertain);return()=>onLockChange?.(false);},[busy,uncertain,onLockChange]);
 // Protect links in the enclosing customer shell as well as this form while a command is unresolved.
 useEffect(()=>{if(!busy&&!uncertain)return;const stop=e=>{if(e.target?.closest?.('a[href]')){e.preventDefault();e.stopPropagation();}};document.addEventListener?.('click',stop,true);return()=>document.removeEventListener?.('click',stop,true);},[busy,uncertain]);
 const optionsReady=optionState.ready&&optionState.panel===(step===1?'variants':'staff')&&optionState.variantId===variantId&&optionState.staffId===staffId;
 const selectionReady=report.key===key&&Boolean(report.quote)&&!report.error&&optionsReady;
 const validQuantity=Number.isInteger(quantity)&&quantity>=1&&quantity<=100;
 const contactError=(contact.name.normalize('NFC').trim().length<2||contact.name.normalize('NFC').trim().length>120)?'Nhập họ và tên có ít nhất 2 ký tự.':(contact.phone.length>20||!/^\+?[0-9]{8,15}$/.test(contact.phone.replace(/[ ()-]/g,'')))?'Nhập số điện thoại hợp lệ (8–15 chữ số).':contact.email.trim()&&(contact.email.trim().length>254||!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact.email.trim()))?'Nhập email hợp lệ.':'';
 function advance(){
  if(locked||sending.current||!isSessionGenerationCurrent(lease))return;
  if(step<=2&&!selectionReady){setError('Chờ kiểm tra giá và lựa chọn hợp lệ trước khi tiếp tục.');return;}
  if(!validQuantity){setError('Chọn số lượng từ 1 đến 100.');return;}
  if(step>=3&&(!selected||!current?.policy?.selfBookingAllowed)){setError('Chọn giờ trống phù hợp trước khi tiếp tục.');return;}
  if(step===4&&contactError){setError(contactError);return;}
  setError('');setStep(x=>Math.min(5,x+1));
 }
 function back(){if(!locked&&!sending.current){setError('');setStep(x=>Math.max(1,x-1));}}
 async function submit(event){
  event.preventDefault();if(step<5){advance();return;}const owner=life.current;if(promotionChecking||sending.current||!owner?.active||renderKey.current!==key||!isSessionGenerationCurrent(lease))return;
  if(!attempt.current&&(!validQuantity||contactError||!consent)){setError(!validQuantity?'Chọn số lượng từ 1 đến 100.':contactError||'Đồng ý xử lý thông tin để gửi yêu cầu đặt lịch.');return;}
  if(!attempt.current&&(quote?.depositOk===false||!selected||!current?.policy?.selfBookingAllowed||(current.policy.acknowledgmentRequired&&!ack))){setError('Chọn giờ trống và xác nhận chính sách trước khi đặt lịch.');return;}
  sending.current=true;setBusy(true);setError('');
  try{
   if(!attempt.current)attempt.current=customerBookingApi.prepare({...recipe,...((quote?.walletDeposit||quote?.deposit)?{depositQuote:(quote.walletDeposit||quote.deposit).quote}:{}),startAt:selected.start,contact,note,...(current.policy.acknowledgmentRequired?{violationAcknowledged:ack}:{})});
   const result=await customerBookingApi.submit(attempt.current);
   if(owner.active&&life.current===owner&&isSessionGenerationCurrent(lease)){setReceipt(result);setUncertain(false);}
  }catch(reason){if(owner.active&&life.current===owner&&isSessionGenerationCurrent(lease)){
   setError(reason.message);if(reason.outcome==='UNKNOWN')setUncertain(true);else{attempt.current=null;setUncertain(false);if(shouldReloadAvailability(reason)){availabilityCache.current=null;policyCache.current=null;setSlot(null);setStep(3);setReload(x=>x+1);}}
  }}finally{sending.current=false;if(owner.active&&life.current===owner)setBusy(false);}
 }
 if(receipt)return <section className="my-8 rounded-2xl border border-[var(--bb-border)] bg-white p-6" role="status"><p className="bb-home-kicker">Đã tiếp nhận yêu cầu</p><h2 ref={heading} tabIndex={-1} className="my-3 scroll-mt-24 text-2xl font-semibold">{report.quote?.deposit?.readiness?.direct?'Thanh toán cọc 30%':'Lịch hẹn đã được tạo'}</h2><DepositPanel bookingId={receipt.id}/><p>Mã lịch: <strong>{bookingReference(receipt)}</strong></p>{report.quote?.deposit?.readiness?.direct&&<p className="my-3">Chuyển đúng số tiền và nội dung bên trên để hoàn tất đặt cọc.</p>}<Link className="bb-home-button bb-home-button--primary" to={'/customer/appointments?bookingId='+receipt.id}>Xem lịch hẹn</Link></section>;
 const displayedQuote=report.scope===dataScope&&!report.error?report.quote:null;
 const quote=displayedQuote;
 const priceSummary=<BookingPriceBreakdown quote={quote} updating={!current} error={current?.error} disabled={locked} onRetry={()=>{if(!locked)setReload(x=>x+1);}}/>;
 return <form onSubmit={submit} noValidate className="bb-booking" onKeyDown={e=>{if(e.key==='Enter'&&!e.isComposing&&step<5&&!['TEXTAREA','BUTTON','A'].includes(e.target?.tagName)){e.preventDefault();advance();}}}>
 <nav aria-label="Tiến trình đặt lịch" className="bb-booking-progress"><div className="bb-booking-progress__caption"><span>Tiến trình đặt lịch</span><span>Bước {step} / {steps.length}</span></div><ol className="bb-booking-progress__list">{steps.map((label,index)=><li key={label} className={'bb-booking-progress__step'+(step===index+1?' bb-booking-progress__step--current':step>index+1?' bb-booking-progress__step--done':'')} aria-current={step===index+1?'step':undefined}><span className={'bb-booking-progress__number'+(step>index+1?' bb-booking-progress__number--done':'')}>{step>index+1?'✓':index+1}</span><span className="bb-booking-progress__label">{label}</span></li>)}</ol></nav>
 <header className="bb-booking-heading"><p className="bb-home-kicker">Bước {step} / 5</p><h1 ref={heading} className="scroll-mt-24" tabIndex={-1}>{['Chọn dịch vụ','Chọn nhân viên','Chọn ngày và khung giờ','Thông tin liên hệ','Xem lại và xác nhận'][step-1]}</h1><p className="bb-booking-muted">{['Dịch vụ bạn đã chọn từ trang tìm dịch vụ.','Chọn người bạn muốn gặp hoặc để cơ sở tự phân công.','Thời gian tại TP.HCM (UTC+7). Giờ trống được kiểm tra lại khi tạo lịch.','Điền thông tin để cơ sở liên hệ và xác nhận lịch hẹn.','Kiểm tra thông tin và giá dự kiến trước khi gửi yêu cầu.'][step-1]}</p></header>
 <div className="bb-booking-content"><section className="bb-booking-panel" aria-label={'Bước '+step+' · '+steps[step-1]}>
 {step===1&&<><div className="bb-booking-service-row"><span className="bb-booking-selection__icon" aria-hidden="true">◈</span><div><p className="bb-home-kicker">{kind==='combo'?'Gói dịch vụ đã chọn':'Dịch vụ đã chọn'}</p><h3>{item.name}</h3><p className="bb-booking-muted">{branch.name} · {branch.address}</p></div></div><label className="bb-booking-field">Số lượng<input aria-label="Số lượng" type="number" min="1" max="100" value={quantity} disabled={locked} onChange={e=>{if(!locked)setQuantity(Number(e.target.value));}}/></label></>}
 <div hidden={step>2}><BookingOptions kind={kind} baseOption={item} id={item.id} branchId={branch.id} onVariant={setVariant} onStaff={setStaff} variantId={variantId} staffId={staffId} panel={step===1?"variants":"staff"} presentation="cards" onReady={setOptionState} disabled={locked}/></div>
 {step===3&&<><div className="bb-booking-days" role="group" aria-label="Ngày gợi ý">{upcomingDays().map(day=><button key={day} type="button" disabled={locked} aria-pressed={date===day} onClick={()=>{if(!locked)setDate(day);}}>{dayLabel(day)}</button>)}</div><label className="bb-booking-field">Ngày hẹn<DatePicker aria-label="Ngày hẹn" type="date" min={localDay()} value={date} disabled={locked} required onChange={e=>{if(!locked)setDate(e.target.value);}}/></label><h3>Khung giờ khả dụng · {dayLabel(date||localDay())}</h3>
 <div className="bb-booking-slot-results" ref={slotsViewport} style={!current&&slotsHeight.current?{minHeight:slotsHeight.current}:undefined} aria-busy={!current}>{!current?<div className="bb-booking-slots-loading" role="status"><p>Đang cập nhật giờ trống…</p><div aria-hidden="true" className="bb-booking-slot-placeholders">{Array.from({length:10},(_,index)=><span key={index}/>)}</div></div>:current.error?<div role="alert"><p>{current.error}</p><button disabled={locked} type="button" className="bb-home-text-link" onClick={()=>setReload(x=>x+1)}>Kiểm tra lại</button></div>:!current.availability.slots.length?<p>Chưa có giờ trống cho lựa chọn này. Thử ngày hoặc nhân viên khác.</p>:<div role="group" aria-label="Giờ trống" className="bb-booking-time-grid">{current.availability.slots.map(s=><button key={s.start} type="button" aria-pressed={selected?.start===s.start} disabled={locked} onClick={()=>{if(!locked&&renderKey.current===key)setSlot({key:slotKey,start:s.start});}}>{clock(s.start)}</button>)}</div>}</div>
 <p className="bb-booking-slot-assignment">{selected?<>Nhân viên: {[...new Set(selected.assignments.map(a=>a.staffName))].join(' → ')}</>:'Chọn khung giờ để xem nhân viên thực hiện.'}</p></>}
 {step===4&&<fieldset className="bb-booking-contact" disabled={locked}><legend className="bb-sr-only">Thông tin liên hệ</legend>{[['name','Họ và tên','text'],['phone','Số điện thoại','tel'],['email','Địa chỉ email','email']].map(([field,label,type])=><label key={field} className="bb-booking-field">{label}{field!=='email'?' *':' (không bắt buộc)'}<input aria-label={label} type={type} minLength={field==='name'?2:undefined} autoComplete={field==='name'?'name':field==='phone'?'tel':'email'} required={field!=='email'} maxLength={field==='name'?120:field==='phone'?20:254} value={contact[field]} onChange={e=>{if(!locked)setContact({...contact,[field]:e.target.value});}}/></label>)}<label className="bb-booking-field">Ghi chú<textarea aria-label="Ghi chú" maxLength={1000} value={note} onChange={e=>{if(!locked)setNote(e.target.value);}}/></label></fieldset>}
 {step===5&&<><h3>Dịch vụ đã chọn</h3><div className="bb-booking-review-items">{quote?.items.map((unit,i)=><div className="bb-booking-service-row" key={i}><div><strong>{unit.serviceNameSnapshot}</strong>{unit.variantNameSnapshot&&<p>{unit.variantNameSnapshot}</p>}<small>{unit.durationSnapshot} phút</small></div><strong>{publicMoney(unit.priceFinalSnapshot)}</strong></div>)}</div><dl className="bb-booking-details"><div><dt>Chi nhánh</dt><dd>{branch.name} · {branch.address}</dd></div><div><dt>Thời gian</dt><dd>{selected?bookingTime(selected.start):'Chọn lại giờ trống'}</dd></div><div><dt>Nhân viên</dt><dd>{selected?[...new Set(selected.assignments.map(a=>a.staffName))].join(' → '):'Cơ sở tự phân công'}</dd></div><div><dt>Người đặt</dt><dd>{contact.name} · {contact.phone}{contact.email&&<span> · {contact.email}</span>}</dd></div>{note&&<div><dt>Ghi chú</dt><dd>{note}</dd></div>}</dl><BookingPromotions kind={kind} itemId={item.id} recipe={recipe} code={promotionCode} quote={displayedQuote} disabled={locked} onPending={setPromotionChecking} onChange={setPromotionCode}/>{priceSummary}<label className="bb-booking-consent"><input aria-label="Đồng ý xử lý thông tin đặt lịch" type="checkbox" checked={consent} disabled={locked} onChange={e=>{if(!locked)setConsent(e.target.checked);}}/>Tôi đồng ý để cơ sở xử lý thông tin liên hệ nhằm tiếp nhận và xác nhận lịch hẹn này.</label>{current?.policy?.acknowledgmentRequired&&<label className="bb-booking-consent"><input type="checkbox" checked={ack} disabled={locked} onChange={e=>{if(!locked)setAck(e.target.checked);}}/>Tôi đã đọc cảnh báo về các lần hủy muộn hoặc vắng hẹn và sẽ tuân thủ giờ hẹn.</label>}</>}
 {current?.policy&&!current.policy.selfBookingAllowed&&<p role="alert">Tài khoản đang bị hạn chế tự đặt lịch tại cơ sở này. Vui lòng liên hệ cơ sở.</p>}
 {error&&<p role="alert" className="bb-booking-error">{error}</p>}{uncertain&&<p role="status">Chưa xác minh được kết quả. Bấm kiểm tra lại để gửi đúng yêu cầu cũ. Vui lòng kiểm tra kết quả trước khi rời trang hoặc đặt lịch khác.</p>}
 <div className="bb-booking-actions"><button type="button" className="bb-booking-back" disabled={locked||step===1} onClick={back}>Quay lại</button>{step<5?<button key="advance" type="button" className="bb-home-button bb-home-button--primary" disabled={locked||!validQuantity||(step<=2&&!selectionReady)||(step===3&&(!selected||!current?.policy?.selfBookingAllowed))} onClick={event=>{event.preventDefault();advance();}}>{['Chọn nhân viên','Chọn thời gian','Nhập thông tin','Xem lại lịch hẹn'][step-1]}</button>:<button key="confirm" className="bb-home-button bb-home-button--primary" type="submit" disabled={busy||promotionChecking||(!uncertain&&(quote?.depositOk===false||!consent||Boolean(contactError)||!selected||!current?.policy?.selfBookingAllowed||(current.policy.acknowledgmentRequired&&!ack)))} aria-busy={busy}>{busy?'Đang kiểm tra…':uncertain?'Kiểm tra lại yêu cầu':'Xác nhận đặt lịch'}</button>}</div>
 </section><aside className="bb-booking-aside" aria-label="Tóm tắt lịch hẹn"><BookingSummary kind={kind} item={item} branch={branch} quantity={quantity} quote={displayedQuote} updating={!current} error={current?.error} selected={selected} variantName={kind==='combo'?'Gói dịch vụ':variantId?(optionState.variantId===variantId&&optionState.variantName||'Đang cập nhật lựa chọn'):'Tiêu chuẩn'} staffName={staffId?(optionState.staffId===staffId&&optionState.staffName||'Đang cập nhật nhân viên'):'Cơ sở tự phân công'} formatTime={bookingTime} disabled={locked} onRetry={()=>{if(!locked)setReload(x=>x+1);}}/><Link className="bb-booking-bill__history" aria-disabled={busy||uncertain} onClick={e=>{if(busy||uncertain||sending.current)e.preventDefault();}} to="/customer/appointments">Xem lịch hẹn của tôi</Link></aside></div></form>;
}
