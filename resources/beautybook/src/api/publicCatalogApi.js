import {request} from './apiClient';
const envelope=(raw,{page=1,limit=50}={})=>{const items=Array.isArray(raw)?raw:raw.items||raw.data||[];return{items,total:raw.total??items.length,page,limit};};
export const publicCatalogApi={
 service:id=>request(`/services/${encodeURIComponent(id)}`),
 combo:id=>request(`/combos/public/${encodeURIComponent(id)}`),
 branch:id=>request(`/branches/${encodeURIComponent(id)}`),
 async variants(id,options){const raw=await request(`/services/${id}/variants`);return envelope((Array.isArray(raw)?raw:raw.data||[]).map(v=>({...v,serviceId:String(id),active:v.isActive??v.status==='ACTIVE',name:v.name,price:v.price??v.unitPrice})),options);},
 async staff({branchId,serviceId,...options}){const raw=await request('/staff/public?'+new URLSearchParams({branchId,...(serviceId?{serviceIds:serviceId}:{})}));return envelope((Array.isArray(raw)?raw:raw.data||raw.items||[]).map(v=>({...v,branchId:String(v.branchId??v.branch?.id??branchId),name:v.name||v.fullName})),options);},
 async promotions(kind,id,options={}){const query=Object.fromEntries(Object.entries(options).filter(([,value])=>value!==undefined&&value!==null&&value!==''));const raw=await request(`/promotions/${kind==='combo'?'combo':'service'}/${id}?`+new URLSearchParams(query));return envelope(raw,options);},
};
