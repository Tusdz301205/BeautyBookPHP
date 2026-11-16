import {lazy} from 'react';

export function lazyPage(loader){
 let pending;
 const load=()=>pending??=(loader().catch(error=>{pending=null;throw error;}));
 const component=lazy(load);
 component.preload=load;
 return component;
}

export function installPagePreloading(routes,{base='/beautybook',document:doc=document,location=window.location}={}){
 const ordered=[...routes].sort((a,b)=>b[0].length-a[0].length);
 const intent=event=>{
  const link=event.target?.closest?.('a[href]');if(!link||link.hasAttribute('download')||link.target==='_blank')return;
  const url=new URL(link.href,location.href);if(url.origin!==location.origin)return;
  if(base && url.pathname!==base && !url.pathname.startsWith(base+'/'))return;
  const path=url.pathname.slice(base.length)||'/';
  const match=ordered.find(([prefix])=>path===prefix||(prefix!=='/'&&path.startsWith(prefix.endsWith('/')?prefix:prefix+'/')));
  match?.[1].preload?.().catch(()=>{});
 };
 for(const event of ['pointerover','focusin','pointerdown'])doc.addEventListener(event,intent,{passive:true});
 return()=>{for(const event of ['pointerover','focusin','pointerdown'])doc.removeEventListener(event,intent);};
}
