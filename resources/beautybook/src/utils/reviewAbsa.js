// Owner review analysis (PhoBERT Beauty v2). Taxonomy and labels match the trained checkpoint exactly; never remap here.
export const ABSA_ASPECTS = ['SERVICE_QUALITY', 'STAFF_ATTITUDE', 'WAIT_TIME', 'PRICE'];
export const ABSA_LABELS = ['NOT_MENTIONED', 'POS', 'NEG', 'NEU'];
export const ABSA_SENTIMENTS = ['POS', 'NEU', 'NEG'];
export const ABSA_STATUSES = ['ANALYZED', 'PROCESSING', 'FAILED', 'NOT_ANALYZED', 'NO_TEXT'];
export const ASPECT_TITLES = {SERVICE_QUALITY: 'Chất lượng dịch vụ', STAFF_ATTITUDE: 'Thái độ nhân viên', WAIT_TIME: 'Thời gian chờ', PRICE: 'Giá cả'};
export const SENTIMENT_TITLES = {POS: 'Tích cực', NEU: 'Trung lập', NEG: 'Tiêu cực', NOT_MENTIONED: 'Không nhắc tới'};
export const STATUS_TITLES = {ANALYZED: 'Đã phân tích', PROCESSING: 'Đang xử lý phân tích', FAILED: 'AI chưa phân tích được', NOT_ANALYZED: 'Chưa phân tích', NO_TEXT: 'Chỉ chấm sao'};
export const LOW_SAMPLE = 5;
export const ATTENTION_SHARE = 0.2;
export const AI_NOTE = 'AI dự đoán, chưa được con người xác nhận';
const INVALID = 'Dữ liệu phân tích không hợp lệ. Vui lòng tải lại.';

const isCount = value => Number.isInteger(value) && value >= 0;
const fail = () => { throw new Error(INVALID); };
const ratio = value => value === null || (typeof value === 'number' && value >= 0 && value <= 1);

/** Validate GET /reviews/manage/absa/summary for the branch the Owner is looking at. */
export function validateAbsaSummary(value, {businessId, branchId}) {
  if (!value || typeof value !== 'object' || value.scope?.businessId !== businessId || value.scope?.branchId !== branchId) fail();
  const t = value.totals, s = value.sentimentReviews;
  if (!t || !['reviews', 'withText', 'noText', 'analyzed', 'processing', 'failed', 'notAnalyzed'].every(k => isCount(t[k]))) fail();
  if (t.withText + t.noText !== t.reviews || t.analyzed + t.processing + t.failed + t.notAnalyzed !== t.withText) fail();
  if (!s || !['withPositive', 'withNegative', 'noAspect', 'denominator'].every(k => isCount(s[k])) || s.denominator !== t.analyzed) fail();
  if (!Array.isArray(value.aspects) || value.aspects.map(a => a?.aspect).join() !== ABSA_ASPECTS.join()) fail();
  for (const a of value.aspects) {
    if (!a.counts || !ABSA_LABELS.every(l => isCount(a.counts[l]))) fail();
    if (ABSA_LABELS.reduce((sum, l) => sum + a.counts[l], 0) !== t.analyzed || a.mentioned !== a.counts.POS + a.counts.NEG + a.counts.NEU) fail();
    if (![a.positiveShare, a.negativeShare, a.neutralShare].every(ratio)) fail();
  }
  if (typeof value.store?.ready !== 'boolean') fail();
  return value;
}

/** Validate GET /reviews/manage/absa/reviews. */
export function validateAbsaReviews(value) {
  if (!value || !Array.isArray(value.items) || !isCount(value.total) || !Number.isInteger(value.page) || !Number.isInteger(value.limit)) fail();
  for (const item of value.items) {
    const absa = item?.absa;
    if (typeof item?.id !== 'string' || !absa || !ABSA_STATUSES.includes(absa.status)) fail();
    if (absa.status === 'ANALYZED') {
      if (!absa.labels || !ABSA_ASPECTS.every(a => ABSA_LABELS.includes(absa.labels[a]))) fail();
    } else if (absa.labels !== null) fail();
  }
  return value;
}

export const mentionedAspects = labels => labels ? ABSA_ASPECTS.filter(a => labels[a] !== 'NOT_MENTIONED').map(aspect => ({aspect, label: labels[aspect]})) : [];
export const percent = share => share === null || share === undefined ? '—' : `${Math.round(share * 100)}%`;
export const formatNumber = value => typeof value === 'number' ? value.toLocaleString('vi-VN') : '—';
export const formatAverage = value => value === null || value === undefined ? 'Chưa có' : new Intl.NumberFormat('vi-VN', {maximumFractionDigits: 1, minimumFractionDigits: 1}).format(value);
/** Width of each sentiment segment over the reviews that mention the aspect (never over all reviews). */
export const segments = counts => {
  const mentioned = counts.POS + counts.NEU + counts.NEG;
  return ABSA_SENTIMENTS.map(label => ({label, count: counts[label], width: mentioned ? counts[label] / mentioned * 100 : 0}));
};

/** The aspect with the highest complaint share, among aspects mentioned often enough to compare; null when none stands out. */
export function attentionAspect(aspects) {
  let best = null;
  for (const row of aspects) {
    if (row.mentioned < LOW_SAMPLE || !row.counts.NEG) continue;
    const share = row.counts.NEG / row.mentioned;
    if (share >= ATTENTION_SHARE && (!best || share > best.share || (share === best.share && row.counts.NEG > best.neg))) best = {aspect: row.aspect, share, neg: row.counts.NEG};
  }
  return best ? best.aspect : null;
}

// Review period presets. Dates are calendar days in Vietnam, matching the API's from/to (inclusive).
export const PERIODS = [
  {id: 'all', label: 'Toàn bộ thời gian'}, {id: '7d', label: '7 ngày qua', days: 7}, {id: '30d', label: '30 ngày qua', days: 30},
  {id: '90d', label: '90 ngày qua', days: 90}, {id: 'custom', label: 'Chọn khoảng ngày'},
];
export const todayInVietnam = (now = new Date()) => new Intl.DateTimeFormat('en-CA', {timeZone: 'Asia/Ho_Chi_Minh', year: 'numeric', month: '2-digit', day: '2-digit'}).format(now);
export const shiftDate = (date, days) => new Date(Date.parse(`${date}T00:00:00Z`) + days * 86400000).toISOString().slice(0, 10);
export function periodRange(id, now = new Date()) {
  const period = PERIODS.find(p => p.id === id);
  if (!period?.days) return {from: '', to: ''};
  const to = todayInVietnam(now);
  return {from: shiftDate(to, 1 - period.days), to};
}
export function periodOf({from, to}, now = new Date()) {
  if (!from && !to) return 'all';
  const match = PERIODS.find(p => { if (!p.days) return false; const range = periodRange(p.id, now); return range.from === from && range.to === to; });
  return match ? match.id : 'custom';
}
