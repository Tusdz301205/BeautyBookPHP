import { parseISO, differenceInMinutes, addMinutes, startOfDay, format } from 'date-fns';

export const PIXELS_PER_MINUTE = 2; // e.g. 1 min = 2px -> 1 hour = 120px
export const TIMELINE_START_HOUR = 8; // 8:00 AM
export const TIMELINE_END_HOUR = 21; // 9:00 PM

/**
 * Tính offset left (px) dựa vào startTime
 */
export function getLeftPositionFromTime(timeString, currentDate) {
  if (!timeString) return 0;
  const time = parseISO(timeString);
  const startOfTimeline = addMinutes(startOfDay(currentDate), TIMELINE_START_HOUR * 60);
  
  const diffMins = differenceInMinutes(time, startOfTimeline);
  return Math.max(0, diffMins * PIXELS_PER_MINUTE);
}

/**
 * Tính width (px) dựa vào thời lượng
 */
export function getWidthFromDuration(startTimeStr, endTimeStr) {
  if (!startTimeStr || !endTimeStr) return 0;
  const diffMins = differenceInMinutes(parseISO(endTimeStr), parseISO(startTimeStr));
  return Math.max(0, diffMins * PIXELS_PER_MINUTE);
}

/**
 * Từ số px, quy ra số phút (để tính toán khi drag/resize)
 */
export function getMinutesFromPixels(pixels) {
  return Math.round(pixels / PIXELS_PER_MINUTE);
}

/**
 * Tính thời gian chính xác (ISO string) từ tọa độ X và ngày hiện tại
 */
export function getTimeFromLeftPosition(leftPx, currentDate) {
  const mins = getMinutesFromPixels(leftPx);
  const startOfTimeline = addMinutes(startOfDay(currentDate), TIMELINE_START_HOUR * 60);
  return addMinutes(startOfTimeline, mins).toISOString();
}

/**
 * Grid snapping: snap theo mỗi 15 phút
 */
export function snapPixels(pixels, snapMinutes = 15) {
  const snapPx = snapMinutes * PIXELS_PER_MINUTE;
  return Math.round(pixels / snapPx) * snapPx;
}
