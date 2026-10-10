import test from 'node:test';
import assert from 'node:assert/strict';
import { useBookingStore } from './bookingStore.js';

const selectExistingAppointment = () => {
  useBookingStore.getState().reset();
  useBookingStore.getState().setBranch('branch-a');
  useBookingStore.getState().toggleService('haircut');
  useBookingStore.getState().setStaff('hair-stylist');
  useBookingStore.getState().setDate('2026-09-07');
  useBookingStore.getState().setSlot({ start: '2026-09-07T10:00:00', end: '2026-09-07T10:30:00' });
  useBookingStore.getState().setPricePreview({ finalAmount: 200000 });
  useBookingStore.getState().setRecurringPreview({ availableCount: 4 });
};

test('adding a service requires selecting staff and availability again for the new duration/skills', () => {
  selectExistingAppointment();
  useBookingStore.getState().toggleService('manicure');
  const state = useBookingStore.getState();
  assert.deepEqual(state.serviceIds, ['haircut', 'manicure']);
  for (const field of ['staffId', 'date', 'slot', 'pricePreview', 'recurringPreview']) assert.equal(state[field], null, field);
});

test('removing a selected service removes its variant and invalidates the old slot', () => {
  selectExistingAppointment();
  useBookingStore.setState({ variantSelections: { haircut: 'long-hair' } });
  useBookingStore.getState().toggleService('haircut');
  const state = useBookingStore.getState();
  assert.deepEqual(state.serviceIds, []);
  assert.deepEqual(state.variantSelections, {});
  assert.equal(state.slot, null);
});

test('changing date invalidates both quote and recurrence preview without losing services', () => {
  selectExistingAppointment();
  useBookingStore.getState().setDate('2026-09-08');
  const state = useBookingStore.getState();
  assert.deepEqual(state.serviceIds, ['haircut']);
  assert.equal(state.staffId, 'hair-stylist');
  assert.equal(state.date, '2026-09-08');
  for (const field of ['slot', 'pricePreview', 'recurringPreview']) assert.equal(state[field], null, field);
});

test('reset removes contact details, consent and selections before another account books', () => {
  selectExistingAppointment();
  useBookingStore.getState().setCustomerInfo({ fullName: 'Customer A', phone: '0900000000', consent: true, note: 'Private contact instructions' });
  useBookingStore.getState().reset();
  const state = useBookingStore.getState();
  assert.equal(state.branchId, null);
  assert.deepEqual(state.serviceIds, []);
  assert.equal(state.customerInfo.fullName, '');
  assert.equal(state.customerInfo.phone, '');
  assert.equal(state.customerInfo.note, '');
  assert.equal(Boolean(state.customerInfo.consent), false);
});

test('review labels follow selections and clear with dependent choices', () => {
  useBookingStore.getState().reset();
  useBookingStore.getState().setBranch('branch-a', 'Chi nhánh A', '123 Đường Hoa');
  useBookingStore.getState().toggleService('haircut');
  useBookingStore.getState().setStaff('staff-a', 'Nguyễn Thị Phương Anh');
  assert.equal(useBookingStore.getState().branchName, 'Chi nhánh A');
  assert.equal(useBookingStore.getState().branchAddress, '123 Đường Hoa');
  assert.equal(useBookingStore.getState().staffName, 'Nguyễn Thị Phương Anh');
  useBookingStore.getState().toggleService('manicure');
  assert.equal(useBookingStore.getState().staffId, null);
  assert.equal(useBookingStore.getState().staffName, '');
  assert.equal(useBookingStore.getState().branchName, 'Chi nhánh A');
  useBookingStore.getState().setStaff('staff-b');
  useBookingStore.getState().setStaffName('staff-a', 'Tên cũ');
  assert.equal(useBookingStore.getState().staffName, '');
  useBookingStore.getState().setStaffName('staff-b', 'Tên đúng');
  assert.equal(useBookingStore.getState().staffName, 'Tên đúng');
  useBookingStore.getState().reset();
  assert.equal(useBookingStore.getState().branchName, '');
  assert.equal(useBookingStore.getState().staffName, '');
});
