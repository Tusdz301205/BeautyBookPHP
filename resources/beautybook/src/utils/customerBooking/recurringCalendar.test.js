import {test} from 'node:test';
import assert from 'node:assert/strict';
import {recurringCalendar} from './recurringCalendar.js';
test('monthly plans return to original day after leap and short months',()=>{
 const plan=recurringCalendar({startDate:'2028-01-31',preferredTime:'09:00',frequency:'MONTHLY',occurrenceCount:4});
 assert.deepEqual(plan.starts,['2028-01-31T02:00:00.000Z','2028-02-29T02:00:00.000Z','2028-03-31T02:00:00.000Z','2028-04-30T02:00:00.000Z']);
});
test('biweekly crosses years and Vietnam midnight has correct UTC date',()=>{
 const plan=recurringCalendar({startDate:'2026-12-25',preferredTime:'00:15',frequency:'BIWEEKLY',occurrenceCount:2});
 assert.deepEqual(plan.starts,['2026-12-24T17:15:00.000Z','2027-01-07T17:15:00.000Z']);
});
test('invalid dates, hours, counts and truncated plans are rejected',()=>{
 const base={startDate:'2027-01-31',preferredTime:'09:00',frequency:'MONTHLY',occurrenceCount:3};
 for(const extra of [{startDate:'2027-02-29'},{preferredTime:'24:00'},{occurrenceCount:13},{occurrenceCount:0},{occurrenceCount:1.5},{endDate:'2027-02-28'}])assert.throws(()=>recurringCalendar({...base,...extra}));
});
