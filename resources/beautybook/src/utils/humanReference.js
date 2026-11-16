// Display-only reference. Commands and URLs always keep their original UUIDs.
const UUID=/^(?:BB-)?([0-9a-f]{8})-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export function bookingReference(value,startAt){
 const row=value&&typeof value==='object'?value:null,code=row?.bookingCode||row?.bookingId||row?.id||value;
 if(typeof code!=='string'||!code)return 'Lịch hẹn';
 const match=UUID.exec(code);if(!match)return code;
 const date=new Date(row?.startAt||startAt||'');let day='';
 if(Number.isFinite(date.getTime())){const parts=new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Ho_Chi_Minh',day:'2-digit',month:'2-digit',year:'2-digit'}).formatToParts(date);day=['day','month','year'].map(type=>parts.find(p=>p.type===type)?.value||'').join('')+'-';}
 return 'LH-'+day+match[1].toUpperCase();
}
