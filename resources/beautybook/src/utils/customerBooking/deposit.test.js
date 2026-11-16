import {test} from 'node:test';
import assert from 'node:assert/strict';
import {bookingDepositState} from './deposit.js';
import {seriesTerms} from '../walletDeposit.js';

test('missing receiving account stays blocked when the server has no terms or deposit mode',()=>{
 const result=bookingDepositState({walletMode:false,depositMode:null,terms:null,readiness:{direct:true,required:true,ok:false,reason:'Cơ sở chưa sẵn sàng nhận cọc'}});
 assert.equal(result.depositOk,false);assert.equal(result.deposit.unavailable,true);assert.equal(result.deposit.readiness.message,'Cơ sở chưa sẵn sàng nhận cọc');
});

test('a free direct booking requires neither a bank connection nor a payment quote',()=>{
 assert.deepEqual(bookingDepositState({walletMode:false,depositMode:null,terms:null,readiness:{direct:true,required:false,ok:true}}),{depositOk:true,deposit:null});
});

test('an unconfigured occurrence blocks a direct recurring plan instead of looking like zero deposit',()=>{
 const result=bookingDepositState({walletMode:false,occurrences:[{readiness:{direct:true,required:true,ok:false,reason:'Chưa có tài khoản'}}]},null,{depositVnd:0,remainingVnd:0,rows:[]});
 assert.equal(result.depositOk,false);assert.equal(result.deposit.readiness.direct,true);assert.equal(result.deposit.unavailable,true);
});
test('direct deposit unwraps the PHP terms envelope and preserves the acceptance quote',()=>{
 const result=bookingDepositState({walletMode:false,depositMode:'DIRECT',terms:{fundingSource:'DIRECT_SEPAY',depositVnd:30000,remainingVnd:70000,quote:'signed-quote'}});
 assert.equal(result.depositOk,true);assert.equal(result.deposit.quote,'signed-quote');assert.equal(result.deposit.amountVnd,30000);assert.equal(result.deposit.readiness.direct,true);
});
test('a wallet must fund the whole recurring series, including differently priced dates',()=>{
 const series=seriesTerms({occurrences:[{index:0,available:true,walletDeposit:{depositVnd:30000,totalVnd:100000,remainingVnd:70000,quote:'first'}},{index:1,available:true,walletDeposit:{depositVnd:60000,totalVnd:200000,remainingVnd:140000,quote:'second'}}]});
 const result=bookingDepositState({walletMode:true},{liveLabAvailable:true,wallet:{availableVnd:80000}},series);
 assert.equal(result.depositOk,false);assert.equal(result.deposit.readiness.shortfall,10000);assert.equal(result.deposit.depositVnd,90000);assert.deepEqual(result.deposit.quotes,['first','second']);
});
test('wallet allow-list failures block booking while a free booking needs no deposit',()=>{
 assert.equal(bookingDepositState({walletMode:true,terms:{depositVnd:30000}},{liveLabAvailable:false}).deposit.readiness.reason,'NOT_IN_LAB');
 assert.deepEqual(bookingDepositState({walletMode:true,terms:{depositVnd:0}}),{depositOk:true,deposit:null});
 assert.deepEqual(bookingDepositState({walletMode:false,terms:null}),{depositOk:true,deposit:null});
});

test('existing balance can hold a deposit while central bank top-ups are unconfigured',()=>{
 const result=bookingDepositState({walletMode:true,terms:{depositVnd:18000,remainingVnd:42000}},{bookingDepositAvailable:true,topUpAvailable:false,liveLabAvailable:false,wallet:{availableVnd:20000}});
 assert.equal(result.depositOk,true);assert.equal(result.deposit.wallet.availableVnd,20000);
});
