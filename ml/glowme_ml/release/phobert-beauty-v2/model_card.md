# Thẻ mô hình phobert-beauty-v2

[ĐO] Một PhoBERT-base dùng chung cho bốn khía cạnh, dropout 0,1 và Linear(768,16).
Revision nền: `01daacda68afe13d83023d16ec647239e344a1e6`. Không huấn luyện trung gian trên miền VLSP khác.

[ĐO] Seed 42 được chọn trên Dev: Macro F1 end-to-end 0.898657.
Test CPU fp32: F1 0.874048, Accuracy 0.914667,
Exact Match 0.738667. Nguồn: `manifest.json` và
`../../runs/TECH-ML-REBUILD-20261104/e1_seed42/test_metrics.json`.

[ĐO] SHA-256 checkpoint: `6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc`. Dữ liệu: lọc miền `lam_dep` của CSV
`dataset/nguon/vlsp2018_mo_rong.csv`, SHA-256 `82b152dd285845ae99ed96d7d65b6ef922acfda55e123692a63833b8202b9f9d`.
Train 2.462, Dev 385, Test 375. Mã băm từng tập, nguồn và đối chiếu dòng:
`../../runs/TECH-ML-REBUILD-20261104/data_card.json`.

[ĐO] Nhãn theo thứ tự NOT_MENTIONED/POS/NEG/NEU = 0/1/2/3; khía cạnh
SERVICE_QUALITY, STAFF_ATTITUDE, WAIT_TIME, PRICE. Các siêu tham số và cấu hình
AdamW/lịch học/bf16 được ghi đầy đủ trong `manifest.json`.

[ĐO] Tiền xử lý prep-v1, VnCoreNLP 1.2; giới hạn 256 token. Cổng M1 và M2:
`../../runs/TECH-ML-REBUILD-20261104/verification/equivalence.json`.

[CHƯA CÓ DỮ LIỆU] Mô hình chưa đánh giá trên dữ liệu thật. Beauty Services
là dữ liệu tổng hợp của phần mở rộng do nhóm xây dựng, không phải miền chính
thức của VLSP 2018. Tập Test đã từng được dùng trong thực nghiệm trước.
Xác suất chưa hiệu chỉnh; không biểu diễn nhãn hỗn hợp vừa khen vừa chê trên
một khía cạnh. Kết quả chỉ hỗ trợ đọc đánh giá và cần con người xác nhận.

[ĐO] CPU release khớp 1.500/1.500 nhãn, max probability difference 3,9935112e-6; service 40/40 kiểm thử qua. Nguồn: ../../reports/rebuild/release_verification.json và thư mục verification của nhiệm vụ.

[ĐO] API ABSA 12/12 và các luồng tạo đánh giá, gián đoạn/phục hồi service,
phân quyền Owner, backfill trên DB bản sao đều qua. 381 đánh giá cũ được
backfill; tổng 383/383 gồm hai đánh giá kỹ thuật. Kiểm tra bản build cuối
với service thật qua. CPU p50/p95 một câu: 41,02/75,22 ms theo workload cũ.
Nguồn: ../../reports/rebuild/{e2e_result.json,e2e_final_smoke.json,performance_report.json,api_tests_final/results.json}.

[THẤT BẠI] Cổng I2 tổng thể còn 7 lỗi ngoài ML trong 510 kiểm thử Web.
[ĐO] Đã kích hoạt trên DB cục bộ ứng dụng theo yêu cầu chạy thật: 381/381
đánh giá có nội dung đã phân tích; nguồn ../../reports/rebuild/demo_activation.json.
[CHƯA CHẠY] Chưa cách ly checkpoint cũ.
Xem ../../reports/rebuild/ML_REBUILD_REPORT.md.
