import {depositReadiness} from '../walletDeposit.js';
export function bookingDepositState(response,walletResponse=null,series=null){
 const terms=series||response.terms;
 const serverReadiness=response.readiness||response.occurrences?.find(row=>row.readiness?.ok===false)?.readiness;
 if(serverReadiness?.direct&&serverReadiness.ok===true&&serverReadiness.required===false)return {depositOk:true,deposit:null};
 const readiness=serverReadiness?.direct&&serverReadiness.ok===false?{...serverReadiness,message:serverReadiness.reason}:depositReadiness({walletMode:response.walletMode,directMode:response.depositMode==='DIRECT'||Boolean(serverReadiness?.direct),terms,wallet:walletResponse?.wallet,labAvailable:Boolean(walletResponse?.bookingDepositAvailable??walletResponse?.liveLabAvailable)});
 if(readiness.direct&&readiness.ok===false)return {depositOk:false,deposit:{readiness,unavailable:true}};
 return {depositOk:readiness.ok,deposit:terms?.depositVnd>0?{...terms,amountVnd:terms.depositVnd,readiness,wallet:walletResponse?.wallet}:null};
}
