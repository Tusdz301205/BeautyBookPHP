import React,{useEffect,useId,useRef,useState} from 'react';
import {createPortal} from 'react-dom';
import {CalendarDays,ChevronLeft,ChevronRight} from 'lucide-react';
import '../../styles/glowme/report-date-picker.css';

const dayKey=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
const parse=value=>value?new Date(`${value}T12:00:00`):new Date();

export default React.forwardRef(function DatePicker({value:controlledValue,defaultValue='',onChange,min,max,id,disabled,readOnly,required,name,form,step,className='',onInvalid,onBlur,onFocus,type='date',...aria},ref) {
  const [internalValue,setInternalValue]=useState(defaultValue);
  const value=controlledValue??internalValue;
  const generatedId=useId(),popupId=`${generatedId}-calendar`;
  const portalHost=useRef(null);
  const emit=next=>{if(controlledValue===undefined)setInternalValue(next);const target={value:next,name};onChange?.({target,currentTarget:target});};
  const trigger=useRef(null),panel=useRef(null);
  const [open,setOpen]=useState(false),[month,setMonth]=useState(()=>parse(value)),[position,setPosition]=useState({});
  const today=dayKey(new Date());
  const allowed=key=>(!min||key>=min)&&(!max||key<=max);
  const close=()=>{setOpen(false);trigger.current?.focus({preventScroll:true});};
  const select=key=>{if(!disabled&&!readOnly&&allowed(key)){emit(key);close();}};
  useEffect(()=>{
    if(!open||disabled||readOnly)return;
    const place=()=>{
      const rect=trigger.current.getBoundingClientRect(),width=Math.min(320,window.innerWidth-24);
      const top=rect.bottom+8;
      setPosition({width,left:Math.max(12,Math.min(rect.right-width,window.innerWidth-width-12)),top,maxHeight:Math.max(0,window.innerHeight-top-12)});
    };
    place();
    panel.current?.querySelector('[data-selected="true"], [data-current-month="true"]:not(:disabled)')?.focus({preventScroll:true});
    const outside=event=>{if(!panel.current?.contains(event.target)&&!trigger.current?.contains(event.target))setOpen(false);};
    const escape=event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();}};
    const scroll=event=>{if(!panel.current?.contains(event.target))place();};
    document.addEventListener('pointerdown',outside);document.addEventListener('keydown',escape,true);
    window.addEventListener('resize',place);window.addEventListener('scroll',scroll,true);
    return()=>{document.removeEventListener('pointerdown',outside);document.removeEventListener('keydown',escape,true);window.removeEventListener('resize',place);window.removeEventListener('scroll',scroll,true);};
  },[open,disabled,readOnly]);
  const start=new Date(month.getFullYear(),month.getMonth(),1,12);
  start.setDate(start.getDate()-(start.getDay()+6)%7);
  const days=Array.from({length:42},(_,index)=>{const date=new Date(start);date.setDate(start.getDate()+index);return date;});
  const move=offset=>setMonth(current=>new Date(current.getFullYear(),current.getMonth()+offset,1,12));
  const firstYear=Math.min(1900,month.getFullYear(),Number(min?.slice(0,4))||1900);
  const lastYear=Math.max(new Date().getFullYear()+20,month.getFullYear(),Number(max?.slice(0,4))||0);
  return <span className="bb-date-control">
    <input ref={ref} type="date" className="bb-date-validation" tabIndex={-1} aria-hidden="true" value={value} min={min} max={max} required={required} name={name} form={form} step={step} disabled={disabled} readOnly={readOnly} onChange={event=>emit(event.target.value)} onInvalid={event=>{event.preventDefault();trigger.current?.focus();onInvalid?.(event);}}/>
    <button {...aria} id={id} ref={trigger} type="button" disabled={disabled} aria-readonly={readOnly || undefined} aria-required={required || undefined} onBlur={onBlur} onFocus={onFocus} className={`bb-report-date-trigger bb-control ${className}`} aria-haspopup="dialog" aria-controls={open?popupId:undefined} aria-expanded={open} onClick={()=>{if(readOnly)return;portalHost.current=trigger.current.closest('[role="dialog"], [role="alertdialog"]')||document.body;setMonth(parse(value));setOpen(!open);}}>
      <span>{value?value.split('-').reverse().join('/'):'Chọn ngày'}</span><CalendarDays size={18} aria-hidden="true"/>
    </button>
    {open&&!disabled&&!readOnly&&createPortal(<div id={popupId} ref={panel} role="dialog" aria-label="Chọn ngày" className="bb-report-calendar" style={position} onClick={event=>event.stopPropagation()} onKeyDown={event=>{
      if(event.key==='Tab'){
        const buttons=[...panel.current.querySelectorAll('button:not(:disabled),select:not(:disabled)')],first=buttons[0],last=buttons.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
      }
    }}>
      <div className="bb-report-calendar__heading"><button type="button" aria-label="Tháng trước" onClick={()=>move(-1)}><ChevronLeft size={18}/></button><div className="bb-calendar-month-year"><select aria-label="Tháng" value={month.getMonth()} onChange={event=>setMonth(new Date(month.getFullYear(),Number(event.target.value),1,12))}>{Array.from({length:12},(_,index)=><option key={index} value={index}>Tháng {index+1}</option>)}</select><select aria-label="Năm" value={month.getFullYear()} onChange={event=>setMonth(new Date(Number(event.target.value),month.getMonth(),1,12))}>{Array.from({length:lastYear-firstYear+1},(_,index)=>firstYear+index).map(year=><option key={year} value={year}>{year}</option>)}</select></div><button type="button" aria-label="Tháng sau" onClick={()=>move(1)}><ChevronRight size={18}/></button></div>
      <div className="bb-report-calendar__grid">
        {['T2','T3','T4','T5','T6','T7','CN'].map(day=><span className="bb-report-calendar__weekday" key={day}>{day}</span>)}
        {days.map(date=>{const key=dayKey(date);return <button type="button" key={key} disabled={!allowed(key)} data-current-month={date.getMonth()===month.getMonth()} data-selected={key===value} aria-pressed={key===value} aria-current={key===today?'date':undefined} aria-label={date.toLocaleDateString('vi-VN',{weekday:'long',day:'numeric',month:'long',year:'numeric'})} onClick={()=>select(key)}>{date.getDate()}</button>;})}
      </div>
      <div className="bb-report-calendar__footer">{!required&&<button type="button" onClick={()=>{emit('');close();}}>Xóa</button>}<button type="button" onClick={close}>Đóng</button><button type="button" disabled={!allowed(today)} onClick={()=>select(today)}>Hôm nay</button></div>
    </div>,portalHost.current||document.body)}
  </span>;
});
