import {test} from 'node:test';
import assert from 'node:assert/strict';
import {lazyPage,installPagePreloading} from './lazyPage.js';
test('intent and render share one module download; a failed preload may retry',async()=>{
 let calls=0;
 const page=lazyPage(async()=>{calls++;if(calls===1)throw new Error('network');return {default:()=>null};});
 await assert.rejects(page.preload(),/network/);
 const first=page.preload();assert.equal(page.preload(),first);await first;assert.equal(calls,2);
});
test('prefetch matches the deepest local route and ignores external/download links',async()=>{
 const listeners=new Map(),calls=[];
 const document={addEventListener:(event,fn)=>listeners.set(event,fn),removeEventListener:event=>listeners.delete(event)};
 const routes=[['/salon',{preload:async()=>calls.push('home')}],['/salon/reviews',{preload:async()=>calls.push('reviews')}],['/customer/appointments/',{preload:async()=>calls.push('detail')}]];
 const dispose=installPagePreloading(routes,{document,location:{href:'http://localhost/beautybook/',origin:'http://localhost'},base:'/beautybook'});
 const hover=(href,download=false)=>listeners.get('pointerover')({target:{closest:()=>({href,target:'',hasAttribute:()=>download})}});
 hover('http://localhost/beautybook/salon/reviews?branchId=16');hover('http://localhost/beautybook/customer/appointments/123');hover('https://external.test/beautybook/salon');hover('http://localhost/beautybookevil/salon');hover('http://localhost/beautybook/salon',true);
 assert.deepEqual(calls,['reviews','detail']);dispose();assert.equal(listeners.size,0);
});
