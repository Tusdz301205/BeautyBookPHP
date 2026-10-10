/* ============================= SMALL HELPERS ============================= */
export const formatVND = (n) => {
  if (n == null || n === "") return "0đ";
  return Number(n).toLocaleString("vi-VN") + "đ";
};
