const DOCUMENT_TYPES = new Set(['application/pdf']);
const IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/avif']);
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

export function validateUploadedFile(file, { document = false } = {}) {
  const allowedTypes = document ? DOCUMENT_TYPES : IMAGE_TYPES;
  if (!allowedTypes.has(file?.type)) return document
    ? 'Chỉ nhận tài liệu PDF.'
    : 'Chỉ nhận ảnh JPG, PNG, WebP hoặc AVIF.';
  if (file.size < 1) return 'Tệp không được rỗng.';
  if (file.size > MAX_UPLOAD_BYTES) return 'Tệp không được lớn hơn 10 MB.';
  return '';
}
