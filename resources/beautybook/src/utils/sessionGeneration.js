import {useAuthStore} from '../store/authStore';
let generation=0,subscribed=false;
const identity=user=>JSON.stringify([user?.id,user?.sessionType,user?.workspace,user?.businessId,user?.branchId,user?.sessionId]);
function watch(){if(subscribed)return;subscribed=true;useAuthStore.subscribe((state,previous)=>{if(identity(state.user)!==identity(previous.user))generation++;});}
export function captureSessionGeneration(){watch();return generation;}
export function isSessionGenerationCurrent(lease){watch();return lease===generation;}
export function assertSessionGeneration(lease){if(!isSessionGenerationCurrent(lease))throw Object.assign(new Error('Phiên đăng nhập đã thay đổi.'),{code:'STALE_SESSION'});}
export const sameSessionIdentity=(left,right)=>identity(left)===identity(right);
