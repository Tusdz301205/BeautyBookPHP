export const BUSINESS_FIELD_LIMITS = Object.freeze({
  name: 150,
  slug: 70,
  description: 2000,
  contactEmail: 254,
  contactPhone: 30,
  addressLine: 500,
  legalRepresentative: 150,
  companyName: 255,
  taxCode: 100,
  identityCardNumber: 100,
});

export function isInternalBusinessDraftName(value) {
  return typeof value === 'string' && /^hồ sơ cơ sở của\b/i.test(value.trim());
}

export function normalizeBusinessPhone(value) {
  const compact = String(value || '').trim().replace(/[\s().-]/g, '');
  return compact.startsWith('0') ? `+84${compact.slice(1)}` : compact;
}

export function validateBusinessIdentity(identity) {
  const errors = {};
  const value = Object.fromEntries(Object.entries(identity).map(([key, item]) => [
    key,
    typeof item === 'string' ? item.trim() : '',
  ]));

  if (isInternalBusinessDraftName(value.name) || value.name.length < 2) errors.name = 'Nhập tên thương hiệu có ít nhất 2 ký tự.';
  else if (value.name.length > BUSINESS_FIELD_LIMITS.name) errors.name = 'Tên thương hiệu không được vượt quá 150 ký tự.';

  if (!value.slug) errors.slug = 'Nhập đường dẫn BeautyBook.';
  else if (value.slug.length > BUSINESS_FIELD_LIMITS.slug || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/i.test(value.slug)) {
    errors.slug = 'Đường dẫn chỉ gồm chữ không dấu, số và dấu gạch nối, tối đa 70 ký tự.';
  }

  if (value.companyName.length < 2) errors.companyName = 'Nhập tên pháp nhân có ít nhất 2 ký tự.';
  else if (value.companyName.length > BUSINESS_FIELD_LIMITS.companyName) errors.companyName = 'Tên pháp nhân không được vượt quá 255 ký tự.';
  if (!value.taxCode) errors.taxCode = 'Nhập mã số thuế.';
  else if (value.taxCode.length > BUSINESS_FIELD_LIMITS.taxCode) errors.taxCode = 'Mã số thuế không được vượt quá 100 ký tự.';

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.contactEmail) || value.contactEmail.length > BUSINESS_FIELD_LIMITS.contactEmail) {
    errors.contactEmail = 'Nhập email liên hệ hợp lệ (tối đa 254 ký tự).';
  }
  const normalizedPhone = normalizeBusinessPhone(value.contactPhone);
  if (!/^\+84\d{9}$/.test(normalizedPhone)) errors.contactPhone = 'Dùng số Việt Nam gồm 10 chữ số, bắt đầu bằng 0 hoặc +84.';
  if (value.addressLine.length < 1) errors.addressLine = 'Nhập địa chỉ đăng ký.';
  else if (value.addressLine.length > BUSINESS_FIELD_LIMITS.addressLine) errors.addressLine = 'Địa chỉ không được vượt quá 500 ký tự.';
  if (value.legalRepresentative.length < 2) errors.legalRepresentative = 'Nhập họ tên người đại diện.';
  else if (value.legalRepresentative.length > BUSINESS_FIELD_LIMITS.legalRepresentative) errors.legalRepresentative = 'Tên người đại diện không được vượt quá 150 ký tự.';
  if (value.description.length > BUSINESS_FIELD_LIMITS.description) errors.description = 'Phần giới thiệu không được vượt quá 2.000 ký tự.';
  if (value.identityCardNumber.length > BUSINESS_FIELD_LIMITS.identityCardNumber) errors.identityCardNumber = 'Số định danh không được vượt quá 100 ký tự.';

  return errors;
}

export function validateBusinessType(value, catalog) {
  return catalog.some((item) => item.code === value)
    ? ''
    : 'Chọn một loại hình dịch vụ đang được hỗ trợ.';
}

export function validateDocuments(documents, requiredTypes) {
  const errors = {};
  const started = documents.filter((item) => item.mediaId || item.media || item.documentName?.trim()
    || item.documentNumber?.trim() || item.expiresAt || item.note?.trim());
  if (started.some((item) => !item.documentName?.trim())) {
    errors.documentDetails = 'Nhập tên cho mỗi tài liệu đã tải lên hoặc đã bắt đầu nhập.';
  }
  if (started.some((item) => !['BUSINESS_LICENSE', 'OWNER_ID_CARD', 'TAX_DOCUMENT', 'OTHER'].includes(item.documentType))) {
    errors.documentType = 'Chọn loại tài liệu được hỗ trợ.';
  }
  if (started.some((item) => item.expiresAt && !isValidDocumentDate(item.expiresAt))) {
    errors.documentDetails = 'Nhập ngày hết hạn hợp lệ theo ngày, tháng và năm.';
  }
  const uploaded = documents.filter((item) => item.documentName?.trim() && item.mediaId);
  const knownTypes = new Set(uploaded.map((item) => item.documentType));
  const countedTypes = uploaded.map((item) => item.documentType).filter((type) => type !== 'OTHER');
  if (new Set(countedTypes).size !== countedTypes.length) {
    errors.documentType = 'Mỗi loại giấy tờ chỉ được có một bản đang hiệu lực.';
  }
  if (started.some((item) => !item.mediaId)) {
    errors.upload = 'Hoàn tất tải tệp PDF lên trước khi lưu hoặc gửi hồ sơ.';
  }
  if (documents.some((item) => (item.documentName?.trim().length ?? 0) > 255
    || (item.documentNumber?.trim().length ?? 0) > 100
    || (item.note?.trim().length ?? 0) > 1000)) {
    errors.documentDetails = 'Tên tài liệu tối đa 255 ký tự, số tài liệu 100 ký tự và ghi chú 1.000 ký tự.';
  }
  for (const type of requiredTypes) {
    if (!knownTypes.has(type)) errors[type] = type === 'BUSINESS_LICENSE'
      ? 'Tải lên giấy phép kinh doanh dạng PDF.'
      : 'Tải lên giấy tờ người đại diện dạng PDF.';
  }
  return errors;
}

export function isValidDocumentDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

// Completion is an acknowledgement of valid data, not a permanently latched flag.
export function validCompletedSteps(completedSteps, stepErrors) {
  const completed = [];
  for (let step = 1; step <= 3; step += 1) {
    if (!completedSteps.includes(step) || Object.keys(stepErrors[step] || {}).length) break;
    completed.push(step);
  }
  return completed;
}

export function businessErrorField(error) {
  const message = typeof error?.message === 'string' ? error.message : '';
  const mappings = [
    [/mã số thuế|tax.?code/i, 'taxCode'],
    [/slug|đường dẫn/i, 'slug'],
    [/tên thương hiệu|business name|\bname\b/i, 'name'],
    [/email liên hệ|contactEmail/i, 'contactEmail'],
    [/phone verification|xác minh số điện thoại/i, 'phoneVerification'],
    [/số điện thoại|contactPhone/i, 'contactPhone'],
    [/địa chỉ đăng ký|addressLine/i, 'addressLine'],
    [/người đại diện|legalRepresentative/i, 'legalRepresentative'],
    [/tên pháp nhân|companyName/i, 'companyName'],
    [/loại hình dịch vụ|businessType|loại hình doanh nghiệp/i, 'businessType'],
    [/giấy phép kinh doanh|BUSINESS_LICENSE/i, 'BUSINESS_LICENSE'],
    [/giấy tờ người đại diện|OWNER_ID_CARD/i, 'OWNER_ID_CARD'],
  ];
  const match = mappings.find(([pattern]) => pattern.test(message));
  if (!match) return null;
  const field = match[1];
  const translated = toBusinessOnboardingError(error);
  return { field, message: translated };
}

export function toBusinessOnboardingError(error) {
  const message = typeof error?.message === 'string' ? error.message : '';
  if (/Thiếu thông tin:\s*name/i.test(message)) return 'Nhập tên thương hiệu trước khi gửi hồ sơ.';
  if (/tên thương hiệu/i.test(message)) return 'Nhập tên thương hiệu trước khi gửi hồ sơ.';
  if (/slug/i.test(message) && /đã được sử dụng/i.test(message)) return 'Đường dẫn BeautyBook đã được sử dụng. Hãy chọn đường dẫn khác.';
  if (/tax.?code|mã số thuế/i.test(message) && /đã được sử dụng|unique/i.test(message)) return 'Mã số thuế này đã được sử dụng trong một hồ sơ khác.';
  if (/^Hoàn thiện trước khi gửi hồ sơ:/i.test(message)) return message;
  if (/\bname\b|\bbusinessType\b|contactEmail|contactPhone|legalRepresentative|companyName|taxCode|addressLine/.test(message)) {
    return 'Một số thông tin hồ sơ chưa hợp lệ. Kiểm tra các mục được đánh dấu rồi thử lại.';
  }
  if (/^(?:Tên thương hiệu|Đường dẫn|Tên pháp nhân|Mã số thuế|Email liên hệ|Dùng số điện thoại|Số điện thoại|Địa chỉ|Người đại diện|Giới thiệu|Số định danh|Giấy tờ|Tài liệu|Loại hình)/i.test(message)) {
    return message.slice(0, 280);
  }
  if (error?.status === 409) return 'Hồ sơ vừa thay đổi hoặc thông tin đã được sử dụng. Tải lại hồ sơ rồi thử lại.';
  if (error?.status >= 500 || error?.status || /^HTTP \d+$/.test(message)) return 'Máy chủ chưa thể xử lý yêu cầu. Dữ liệu trên biểu mẫu vẫn được giữ lại.';
  return 'Chưa thể lưu hồ sơ. Kiểm tra kết nối và thử lại; nội dung đã nhập vẫn được giữ lại.';
}
