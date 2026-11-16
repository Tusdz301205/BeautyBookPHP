import {request,walletApi} from './apiClient';
import {customerBookingApi,quoteShape} from './customerBookingApi';
import {captureSessionGeneration,assertSessionGeneration} from '../utils/sessionGeneration';
import {seriesTerms} from '../utils/walletDeposit';
import {bookingDepositState} from '../utils/customerBooking/deposit';
const attempts=new WeakMap();
export const customerRecurringApi={
 async preview(input,_business,{signal}={}){
  const [raw,policy]=await Promise.all([request('/recurring/preview',{method:'POST',body:JSON.stringify(input),signal}),customerBookingApi.policy(input)]);
  const series=seriesTerms(raw),wallet=raw.walletMode&&series.depositVnd>0?await walletApi.mine({scope:'LIVE_LAB'}):null,state=bookingDepositState(raw,wallet,series);
  return {...raw,depositOk:state.depositOk,occurrenceCount:raw.occurrences.length,customerPolicy:policy,blockingReasons:policy.selfBookingAllowed?[]:['CUSTOMER_RESTRICTED'],occurrences:raw.occurrences.map(row=>({...row,ordinal:row.index,startAt:row.appointmentDate,reasonCode:row.reason||'STAFF_UNAVAILABLE',quote:{...quoteShape(row.quote),deposit:row.walletDeposit?{...row.walletDeposit,amountVnd:row.walletDeposit.depositVnd,seriesTerms:series,readiness:state.deposit?.readiness,wallet:wallet?.wallet}:state.deposit||null}}))};
 },
 prepare(input){const attempt=Object.freeze({key:'web-recurring-'+crypto.randomUUID()});attempts.set(attempt,{lease:captureSessionGeneration(),body:JSON.stringify({...input,contactConsent:true}),pending:null,result:null,unknown:false});return attempt;},
 submit(attempt){const command=attempts.get(attempt);if(!command)throw new Error('Yêu cầu không hợp lệ.');assertSessionGeneration(command.lease);if(command.result)return Promise.resolve(command.result);if(command.pending)return command.pending;
  command.pending=(async()=>{try{const result=await request('/recurring',{method:'POST',headers:{'Idempotency-Key':attempt.key},body:command.body});assertSessionGeneration(command.lease);return command.result=result;}catch(error){if(!error.status||error.status>=500||error.code==='STALE_SESSION')command.unknown=true;error.outcome=command.unknown?'UNKNOWN':'REJECTED';throw error;}finally{command.pending=null;}})();return command.pending;
 },
 mine:()=>request('/recurring/mine'),
 cancel:id=>request('/recurring/'+id,{method:'DELETE'}),
 cancelOccurrence:(id,bookingId)=>request('/recurring/'+id+'/occurrences/'+bookingId,{method:'DELETE'}),
};
