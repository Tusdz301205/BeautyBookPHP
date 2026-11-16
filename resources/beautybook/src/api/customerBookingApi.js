import {request,walletApi} from './apiClient';
import {captureSessionGeneration,assertSessionGeneration} from '../utils/sessionGeneration';
import {useAuthStore} from '../store/authStore';
import {bookingDepositState} from '../utils/customerBooking/deposit';
const attempts=new WeakMap();
const customer=()=>{const user=useAuthStore.getState().user;if(user?.sessionType!=='customer')throw Object.assign(new Error('Vui lòng dùng tài khoản khách hàng để đặt lịch.'),{status:403});return user;};
export const quoteShape=q=>({...q,totalOriginalAmount:q.subtotalAmount,totalDiscountAmount:q.discountAmount,totalEstimatedAmount:q.finalAmount,totalDurationMinutes:q.durationMinutes,items:q.items.map((r,i)=>({...r,serviceId:String(r.branch_service_offering_id),serviceNameSnapshot:r.service_name,variantNameSnapshot:r.variant_name,priceOriginalSnapshot:r.unit_price,priceFinalSnapshot:r.priceFinalSnapshot??r.unit_price,durationSnapshot:r.duration_minutes}))});
export const customerBookingApi={
 async preview(recipe,{signal}={}){const raw=await request('/bookings/preview-price',{method:'POST',body:JSON.stringify(recipe),signal});if(recipe.promotionCode&&!raw.promotionCodeValid)throw Object.assign(new Error('Mã ưu đãi không còn hợp lệ cho lựa chọn này.'),{status:422});const q=quoteShape(raw);const response=await walletApi.depositTerms(recipe.branchId,Math.round(Number(q.totalEstimatedAmount)));const wallet=response.walletMode&&response.terms?.depositVnd>0?await walletApi.mine({scope:'LIVE_LAB'}):null;return {...q,...bookingDepositState(response,wallet)};},
 slots:(recipe,_quote,{signal}={})=>request('/bookings/available-slots',{method:'POST',body:JSON.stringify(recipe),signal}),
 policy:recipe=>request('/bookings/self-booking-policy?'+new URLSearchParams({branchId:recipe.branchId})),
 prepare(input){const user=customer(),attempt=Object.freeze({key:'web-booking-'+crypto.randomUUID()});attempts.set(attempt,{body:JSON.stringify({...input,contactConsent:true,walletDepositQuote:input.depositQuote}),userId:user.id,lease:captureSessionGeneration(),pending:null,result:null,unknown:false});return attempt;},
 submit(attempt){const command=attempts.get(attempt);if(!command)throw new Error('Yêu cầu không hợp lệ.');const current=()=>{assertSessionGeneration(command.lease);if(customer().id!==command.userId)throw new Error('Tài khoản đã thay đổi.');};current();if(command.result)return Promise.resolve(command.result);if(command.pending)return command.pending;
 command.pending=(async()=>{try{const result=await request('/bookings',{method:'POST',headers:{'Idempotency-Key':attempt.key},body:command.body});current();command.result=result;return result;}catch(error){if(!error.status||error.status>=500||error.code==='STALE_SESSION')command.unknown=true;error.outcome=command.unknown?'UNKNOWN':'REJECTED';throw error;}finally{command.pending=null;}})();return command.pending;},
};
