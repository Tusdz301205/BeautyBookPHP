import {test} from 'node:test';
import assert from 'node:assert/strict';
import {policyCacheFresh,shouldReloadAvailability} from './previewCache.js';
test('a policy is refreshed after its restriction ends, TTL expires or scope changes',()=>{
 const now=Date.parse('2026-11-13T10:00:00Z'),row={key:'customer:branch',readAt:now,value:{restriction:{active:true,ends_at:'2026-11-13T10:00:10Z'}}};
 assert.equal(policyCacheFresh(row,row.key,now+9999),true);assert.equal(policyCacheFresh(row,row.key,now+10000),false);
 assert.equal(policyCacheFresh({...row,value:{restriction:null}},row.key,now+30000),false);
 assert.equal(policyCacheFresh(row,'another-customer',now),false);
});
test('availability conflicts and new restrictions refresh slots; unknown outcomes keep their command',()=>{
 for(const status of [403,409,422])assert.equal(shouldReloadAvailability({status}),true);
 for(const status of [undefined,500,502])assert.equal(shouldReloadAvailability({status}),false);
});
