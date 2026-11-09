import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';

const base = process.env.APP_URL || 'http://127.0.0.1:8013';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Local preview required');
assert.equal(process.env.STAFF_CALENDAR_TEST_DATABASE, 'beautybook_test', 'Explicit test database acknowledgement required');
const output = process.env.EVIDENCE_DIR;
const password = process.env.STAFF_CALENDAR_TEST_PASSWORD;
assert.ok(output && password, 'Set EVIDENCE_DIR and the test fixture password');
await fs.mkdir(output, { recursive: true });

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const errors = [];
const steps = [];
const weekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
const api = (suffix) => base + '/api/v1/' + suffix;

async function csrf(request) {
  const response = await request.get(base + '/beautybook/login');
  assert.equal(response.status(), 200);
  const token = /<meta name="csrf-token" content="([^"]+)"/.exec(await response.text())?.[1];
  assert.ok(token, 'CSRF token present');
  return token;
}

async function login(page, name) {
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(base + '/beautybook/login');
  await page.locator('#login-email').fill(name + '@beautybook.local');
  await page.locator('#login-password').fill(password);
  const response = page.waitForResponse((item) => item.url() === api('auth/login') && item.request().method() === 'POST');
  await page.getByRole('button', { name: 'Đăng nhập', exact: true }).click();
  assert.equal((await response).status(), 200, 'Browser login');
  await page.waitForURL((url) => !url.pathname.endsWith('/login'));
  await page.waitForLoadState('networkidle');
}

async function seededBooking(context) {
  const request = context.request;
  const login = await request.post(api('auth/login'), {
    headers: { 'X-CSRF-TOKEN': await csrf(request) },
    data: { email: 'customer@beautybook.local', password },
  });
  assert.equal(login.status(), 200, 'Test customer login');
  const accessToken = (await login.json()).accessToken;
  assert.ok(accessToken, 'Test customer access');
  const headers = { Authorization: 'Bearer ' + accessToken, 'X-CSRF-TOKEN': await csrf(request), 'Idempotency-Key': 'calendar-browser-booking' };
  const branches = await (await request.get(api('branches?search=' + encodeURIComponent('Tân Tạo')))).json();
  const branch = branches.data.find((item) => item.slug === 'tan-tao') ?? branches.data[0];
  assert.ok(branch, 'Seeded Tân Tạo branch');
  const staffRows = await (await request.get(api('staff/public?branchId=' + branch.id))).json();
  const staff = await (await request.get(api('staff/public/' + staffRows[0].id))).json();
  const serviceId = staff.staffServices.find((item) => item.service?.id)?.service.id;
  assert.ok(serviceId, 'Seeded staff qualification');
  const date = new Date(Date.now() + 8 * 86400000);
  if (date.getUTCDay() === 0) date.setUTCDate(date.getUTCDate() + 1);
  const day = date.toISOString().slice(0, 10);
  const response = await request.post(api('bookings'), { headers, data: {
    branchId: branch.id, staffId: staff.id, serviceIds: [serviceId], appointmentDate: day + 'T10:30:00+07:00',
    customerName: 'Khách kiểm thử', customerPhone: '0901234567', contactConsent: true,
  } });
  assert.equal(response.status(), 201, 'Seeded booking through the real API');
  return { day, weekday: weekdays[date.getUTCDay()], staff, booking: await response.json(), headers };
}

async function checkViewport(page) {
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1), 'Page must fit its viewport');
}

try {
  const customer = await browser.newContext();
  const { day, weekday, staff, booking, headers } = await seededBooking(customer);
  steps.push('created a real booking in the isolated database');

  const owner = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await owner.newPage();
  page.setDefaultTimeout(45000);
  await login(page, 'owner');
  const detail = base + '/beautybook/salon/staff/' + staff.id;
  await page.goto(detail + '?tab=hours');
  const hours = page.getByRole('tabpanel', { name: 'Giờ làm' });
  await hours.getByText('Chưa có ca riêng. Nhân viên đang theo giờ chi nhánh.').waitFor();
  const weekdayGroup = hours.getByRole('group', { name: weekday, exact: true });
  await weekdayGroup.getByRole('checkbox').uncheck();
  await weekdayGroup.getByLabel('Bắt đầu ca 1', { exact: false }).fill('09:00');
  await weekdayGroup.getByLabel('Kết thúc ca 1', { exact: false }).fill('14:00');
  await weekdayGroup.getByRole('button', { name: 'Thêm ca ' + weekday }).click();
  await weekdayGroup.getByLabel('Bắt đầu ca 2', { exact: false }).fill('13:00');
  await weekdayGroup.getByLabel('Kết thúc ca 2', { exact: false }).fill('17:00');
  await hours.getByRole('button', { name: 'Lưu giờ làm' }).click();
  await hours.getByText('Ca làm bị chồng lấn.').waitFor();
  await weekdayGroup.getByLabel('Kết thúc ca 1', { exact: false }).fill('10:00');
  const weekSaved = page.waitForResponse((item) => item.url() === api('staff/' + staff.id + '/working-hours') && item.request().method() === 'PUT');
  await hours.getByRole('button', { name: 'Lưu giờ làm' }).click();
  assert.equal((await weekSaved).status(), 200);
  await hours.getByText('1 lượt dịch vụ đã đặt bị ảnh hưởng').waitFor();
  await hours.getByText('Đã lưu giờ làm.', { exact: true }).waitFor();
  await checkViewport(page);
  await hours.getByText('1 lượt dịch vụ đã đặt bị ảnh hưởng').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(output, 'staff-hours-desktop.png'), animations: 'disabled' });
  steps.push('owner saved split shifts and saw an existing booking warning');

  const hoursTab = page.getByRole('tab', { name: 'Giờ làm', exact: true });
  await hoursTab.press('ArrowRight');
  const absences = page.getByRole('tabpanel', { name: 'Lịch nghỉ' });
  await absences.getByText('Chưa có lịch nghỉ trong khoảng này').waitFor();
  await page.setViewportSize({ width: 375, height: 812 });
  assert.equal(await page.getByRole('tab', { name: 'Lịch nghỉ', exact: true }).evaluate((element) => {
    const box = element.getBoundingClientRect();
    return box.left >= 0 && box.right <= window.innerWidth;
  }), true, 'Active tab remains visible on mobile');
  await absences.getByRole('button', { name: 'Thêm lịch nghỉ' }).click();
  const editor = page.getByRole('dialog', { name: 'Thêm lịch nghỉ' });
  await editor.getByLabel('Bắt đầu nghỉ', { exact: false }).fill(day + 'T10:30');
  await editor.getByLabel('Đi làm lại', { exact: false }).fill(day + 'T10:00');
  await editor.getByRole('button', { name: 'Lưu lịch nghỉ' }).click();
  await editor.getByText('Giờ kết thúc phải sau giờ bắt đầu', { exact: false }).waitFor();
  await editor.getByLabel('Đi làm lại', { exact: false }).fill(day + 'T11:00');
  await editor.getByLabel('Lý do nghỉ').fill('Nghỉ thử nghiệm');
  const created = page.waitForResponse((item) => item.url() === api('staff/' + staff.id + '/time-off') && item.request().method() === 'POST');
  await editor.getByRole('button', { name: 'Lưu lịch nghỉ' }).click();
  const absence = await created;
  assert.equal(absence.status(), 201);
  assert.equal(absence.request().postDataJSON().startsAt, day + 'T03:30:00.000Z');
  await absences.getByText('1 lượt dịch vụ đã đặt bị ảnh hưởng').waitFor();
  await absences.getByText('Nghỉ thử nghiệm', { exact: true }).waitFor();
  await checkViewport(page);
  await absences.getByText('1 lượt dịch vụ đã đặt bị ảnh hưởng').scrollIntoViewIfNeeded();
  await page.screenshot({ path: path.join(output, 'staff-time-off-mobile.png'), animations: 'disabled' });

  await absences.getByRole('button', { name: 'Sửa lịch nghỉ' }).click();
  const editing = page.getByRole('dialog', { name: 'Sửa lịch nghỉ' });
  await editing.getByLabel('Lý do nghỉ').fill('Đã cập nhật lý do thử nghiệm');
  const updated = page.waitForResponse((item) => item.url().includes('/time-off/') && item.request().method() === 'PUT');
  await editing.getByRole('button', { name: 'Lưu lịch nghỉ' }).click();
  assert.equal((await updated).status(), 200);
  await absences.getByText('Đã cập nhật lý do thử nghiệm', { exact: true }).waitFor();
  steps.push('owner created and edited time off with the branch timezone and mobile warnings');

  const reception = await browser.newContext();
  const receptionPage = await reception.newPage();
  await login(receptionPage, 'receptionist');
  await receptionPage.goto(detail + '?tab=time-off');
  const readOnly = receptionPage.getByRole('tabpanel', { name: 'Lịch nghỉ' });
  await readOnly.getByText('Đã cập nhật lý do thử nghiệm', { exact: true }).waitFor();
  assert.equal(await readOnly.getByRole('button', { name: 'Thêm lịch nghỉ' }).count(), 0);
  assert.equal(await readOnly.getByRole('button', { name: 'Sửa lịch nghỉ' }).count(), 0);
  steps.push('assigned receptionist read the calendar without write controls');

  await absences.getByRole('button', { name: 'Gỡ lịch nghỉ' }).click();
  const removed = page.waitForResponse((item) => item.url().includes('/time-off/') && item.request().method() === 'DELETE');
  await page.getByRole('dialog', { name: 'Gỡ lịch nghỉ?' }).getByRole('button', { name: 'Xác nhận gỡ' }).click();
  assert.equal((await removed).status(), 200);
  await absences.getByText('Chưa có lịch nghỉ trong khoảng này').waitFor();
  const retained = await customer.request.get(api('bookings/' + booking.id), { headers });
  assert.equal(retained.status(), 200);
  const saved = await retained.json();
  assert.equal(saved.appointmentStartAt, booking.appointmentStartAt);
  assert.equal(saved.status, booking.status);
  assert.equal(saved.bookingServices[0].id, booking.bookingServices[0].id);
  steps.push('owner removed time off and the existing booking was preserved');

  assert.deepEqual(errors, []);
  const result = { steps, errors };
  await fs.writeFile(path.join(output, 'browser-results.json'), JSON.stringify(result, null, 2));
  console.log(JSON.stringify(result));
} finally {
  await browser.close();
}
