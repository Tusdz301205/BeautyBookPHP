export function recurringCalendar(row) {
 const invalid=()=>{throw new Error('Ngày, giờ hoặc chu kỳ không hợp lệ.');};
 const day=value=>{if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value)||!Number.isFinite(Date.parse(value+'T00:00:00Z'))||new Date(value+'T00:00:00Z').toISOString().slice(0,10)!==value)invalid();return value;};
 if(!row||!['WEEKLY','BIWEEKLY','MONTHLY'].includes(row.frequency)||!/^([01]\d|2[0-3]):[0-5]\d$/.test(row.preferredTime)||!Number.isInteger(row.occurrenceCount)||row.occurrenceCount<1||row.occurrenceCount>12)invalid();
 const startDate=day(row.startDate),endDate=row.endDate===undefined?undefined:day(row.endDate),base=new Date(startDate+'T00:00:00Z'),starts=[];
 for(let ordinal=0;ordinal<row.occurrenceCount;ordinal++){
  const date=new Date(base);
  if(row.frequency==='MONTHLY'){date.setUTCMonth(base.getUTCMonth()+ordinal,1);const last=new Date(date);last.setUTCMonth(date.getUTCMonth()+1,0);date.setUTCDate(Math.min(base.getUTCDate(),last.getUTCDate()));}
  else date.setUTCDate(base.getUTCDate()+ordinal*(row.frequency==='WEEKLY'?7:14));
  const local=date.toISOString().slice(0,10);if(endDate&&local>endDate)invalid();starts.push(new Date(local+'T'+row.preferredTime+':00+07:00').toISOString());
 }
 return Object.freeze({...row,starts:Object.freeze(starts)});
}
