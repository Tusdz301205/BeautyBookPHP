import {test} from 'node:test';
import assert from 'node:assert/strict';
import {paymentLabel} from './paymentLabels.js';
import {sanitizeApiErrorMessage,toUserFacingRequestError} from './requestError.js';

test('financial terminology distinguishes cash, deposit, refund, reversal and platform fee',()=>{
 assert.equal(paymentLabel('CASH'),'Tiền mặt');assert.equal(paymentLabel('SEPAY_LIVE'),'Chuyển khoản qua SePay');
 assert.equal(paymentLabel('SETTLEMENT'),'Chi trả cho cơ sở');
 assert.equal(paymentLabel('SIMULATED_PAID'),'Đã chi trả (mô phỏng)');
 assert.equal(paymentLabel('SIMULATED_COMPLETED'),'Đã chi trả (mô phỏng)');
 assert.equal(paymentLabel('REFUND'),'Hoàn tiền');assert.equal(paymentLabel('REVERSAL'),'Điều chỉnh giao dịch');
 assert.equal(paymentLabel('PLATFORM_FEE'),'Phí nền tảng');assert.equal(paymentLabel('UNKNOWN','Chưa xác định'),'Chưa xác định');
});
test('wallet errors explain the corrective action in Vietnamese without exposing server details',()=>{
 assert.match(sanitizeApiErrorMessage(402,'Insufficient wallet balance'),/Số dư ví không đủ/);
 assert.match(toUserFacingRequestError({status:409,message:'Bank account changed'}).message,/Vui lòng tải lại/);
 assert.equal(sanitizeApiErrorMessage(422,'Số tiền chưa hợp lệ'),'Số tiền chưa hợp lệ');
 assert.match(sanitizeApiErrorMessage(422,'Invalid booking amount'),/Giá trị lịch hẹn/);
 assert.match(sanitizeApiErrorMessage(403,'Customer required'),/tài khoản khách hàng/);
 assert.equal(sanitizeApiErrorMessage(500,'SQLSTATE secret'), 'Máy chủ đang gặp sự cố tạm thời. Vui lòng thử lại sau.');
});
