# Đối chiếu bản chính và hiệu năng local — 11/10/2026

Đã đối chiếu bản PHP với [BeautyBook main](https://github.com/Tusdz301205/BeautyBook/tree/6a2b43822b6444d9f041bf08f007ae2a75b7a46f), cắt các luồng phụ khỏi bản chạy mặc định và tối ưu Docker local. Giao diện chính vẫn là React nguồn tại `/beautybook`. Database hiện có được giữ nguyên.

Commit kiểm tra: `6a2b43822b6444d9f041bf08f007ae2a75b7a46f`. `git ls-remote` xác nhận HEAD/main của GitHub trùng HEAD của checkout sạch tại `../beauty-booking-api-main`. Backend gốc dùng NestJS, backend port dùng Laravel; không thể đánh giá chúng bằng so sánh byte PHP với TypeScript.

## Phạm vi và bằng chứng

Inventory bao gồm 398 file PHP của dự án trong app/bootstrap/config/database/routes/views/tests/public, không bao gồm thư viện vendor. Đối chiếu frontend bao gồm toàn bộ 190 file nguồn và 185 file port. Có 147 file giống byte, 4 file chỉ khác xuống dòng, 31 file khác nội dung, 3 file riêng của port và 8 file chỉ có ở nguồn.

[Inventory đầy đủ và SHA-256](evidence/2026-10-11/audit/source-files.json) ghi từng file PHP và từng khác biệt frontend. Tạo lại bằng:

```powershell
node scripts/audit-source-parity.mjs
node scripts/audit-source-api.mjs
```

Rà soát backend tập trung vào ánh xạ API, quyền, các luồng đặt lịch/vận hành/quản trị và phần mở rộng không thuộc giao diện nguồn. Inventory toàn bộ file không đồng nghĩa đã chứng minh mọi nhánh logic tương đương.

API audit hiện có 245 wrapper operations, 190 operations được tham chiếu tĩnh qua 132 file frontend reachable; tất cả 190 có route tương ứng. Ba operation không được tham chiếu và không có route mặc định là guest booking, tạo data request và tạo privacy export. Danh sách nằm trong `resources/beautybook/api-contract-audit.json`. Phân tích reachability không xét đầy đủ feature flags; sự hiện diện của route không chứng minh response, quyền hoặc hành vi tương đương.

## Các phần đã cắt hoặc đưa về nguồn

| Phần | Thay đổi |
| --- | --- |
| Giao diện Blade phụ | Tách khỏi `routes/web.php` sang `routes/legacy.php`, mặc định không đăng ký. Các luồng owner/workspace/bookings/finance/waitlist/loyalty/invoices/privacy cũ không còn là lối vào cạnh tranh với React. |
| Trang privacy riêng của PHP | Xóa `PrivacySettings.jsx` và `privacy-center.css` không được sử dụng. Giữ trạng thái unavailable theo giao diện nguồn. |
| Privacy API mở rộng | Tắt POST tạo data request/export mặc định. API đọc/preference/download dùng chung vẫn được giữ. |
| Admin nhật ký | Khôi phục `AdminAudit.jsx` đúng byte nguồn; bỏ dialog và dữ liệu thô thêm riêng ở PHP. |
| Tìm dịch vụ/chi nhánh | Khôi phục tìm kiếm không phân biệt dấu như nguồn. |
| Thông báo | Khôi phục allowlist URL và quyền; chặn URL ngoài hệ thống, route không tồn tại, finance chưa bật và các điểm đến đã bỏ. Nhân viên/lễ tân vẫn mở được lời mời nhận quyền sở hữu theo nguồn. |
| Owner | Giữ yêu cầu bỏ Nhật ký thao tác, Báo cáo doanh nghiệp, Lời mời chuyển chủ; chặn cả route/link thông báo và liên kết từ tổng quan. Đây là khác biệt có chủ đích so với main. |
| Admin | Giữ yêu cầu bỏ Chuyển quyền sở hữu, gồm menu, route và link thông báo. Đây là khác biệt có chủ đích so với main. |

`BEAUTYBOOK_LEGACY_UI=false` là mặc định. Mã Blade cũ, service nghiệp vụ và migration còn được giữ để bảo toàn các API/dữ liệu dùng chung và khả năng tương thích. Chúng chưa bị xóa vật lý toàn bộ. Có thể bật lại giao diện cũ bằng flag này; PHPUnit bật nó cho các test tương thích, còn `SourceSurfaceTest` kiểm tra riêng chế độ mặc định tắt.

Giữ nguyên xác thực, route verify-email có chữ ký, media được kiểm soát quyền, readiness và realtime. Không xóa finance API chỉ vì UI finance đang tắt: backend nguồn cũng có nhóm finance.

## Hiệu năng local

Điểm nghẽn lớn nhất quan sát được là đọc vendor PHP qua Windows bind mount. Thư viện Composer hiện nằm trong named volume `beautybook_vendor` trên filesystem Linux của Docker; app và scheduler dùng chung volume. Source code vẫn được gắn từ workspace để phát triển.

Image cài đúng Composer lock. Entrypoint cập nhật volume khi hash lock thay đổi, chạy hook Laravel để xóa manifest provider cũ trước package discovery, và chỉ ghi marker sau khi thành công. Scheduler đợi app healthy. Kiểm tra trong container dùng một lần đã xác nhận phục hồi provider cache cũ và retry sau hook thất bại; không sửa vendor hay database đang chạy.

Metadata ảnh tham chiếu và danh mục quận/huyện được ghi nhớ trong scope request/job, tránh đọc lại fixture hoặc query metadata nhiều lần trong cùng response. Không dùng cache xuyên request có thể giữ dữ liệu quyền cũ.

Đo cùng năm URL trên cùng máy bằng ba lượt HTTP tuần tự trước/sau. Giá trị dưới đây là trung vị thời gian nhận response, không phải thời gian render hoàn chỉnh trong trình duyệt.

| Request | Trước | Sau | Nhanh hơn |
| --- | ---: | ---: | ---: |
| `/beautybook` | 3.305 ms | 208 ms | 15,9 lần |
| `/api/v1/platform-settings/public` | 2.336 ms | 410 ms | 5,7 lần |
| `/api/v1/services/categories` | 3.655 ms | 210 ms | 17,4 lần |
| `/api/v1/branches?sort=rating&limit=4` | 3.616 ms | 360 ms | 10,0 lần |
| `/api/v1/services/search?limit=10` | 5.320 ms | 518 ms | 10,3 lần |

Các response sau tối ưu trong mẫu đo nằm trong 168–940 ms. [Mẫu trước](evidence/2026-10-11/audit/performance-before.json) và [mẫu sau](evidence/2026-10-11/audit/performance-after.json) được lưu để kiểm tra. Mẫu nhỏ, chịu ảnh hưởng tải máy, cache và Docker; không dùng làm cam kết SLA.

## Kiểm tra đã hoàn tất

- Full suite PHP: **291 tests, 3.041 assertions đạt**, 257,75 giây. Có test chế độ source mặc định, memoization và nhân viên demo.
- Lint app/routes/migrations/seeders/tests: **316 file PHP đạt**.
- Test policy frontend: **5 tests đạt**, gồm Owner bị chặn và nhân viên nhận lời mời vẫn mở được.
- Build frontend Vite: thành công. Có cảnh báo font build-time; các font được phục vụ ở runtime.
- Browser audit: **67 trang**, gồm guest/customer/owner/receptionist/staff/admin và trang chi tiết; response trang đều 200, không lỗi JavaScript, ảnh hay redirect ngoài dự kiến. Có một API 429 khi đăng nhập customer, được chờ Retry-After và retry thành công; không ghi nhận là lỗi chưa xử lý.
- Review độc lập phát hiện hai vấn đề về cập nhật dependency và link thông báo; cả hai đã được sửa, có kiểm tra phục hồi container và policy frontend.
- Docker app/scheduler/realtime/web chạy thành công sau khi áp dụng image mới.

Hai assertion cũ được điều chỉnh theo hành vi đã xác minh: tự đình chỉ tài khoản trả 403 giống Nest nguồn; Cache-Control được so sánh đầy đủ directive nhưng không phụ thuộc thứ tự Symfony chuẩn hóa. Các kiểm tra phạm vi tenant/quyền/private media vẫn giữ nguyên.

Log chi tiết và ảnh browser của phiên nằm trong `tmp/final-php-tests.txt`, `tmp/php-lint.txt` và `tmp/beautybook-audit/browser/`. Đây là chứng cứ local được Git ignore. Tổng hợp gọn được lưu trong [validation-summary.json](evidence/2026-10-11/audit/validation-summary.json).

## Các khác biệt còn lại

Chưa thể gọi bản port giống main 100%. Các điểm đáng chú ý từ đối chiếu:

- Nguồn có `PdfPreview`, state/helper thông báo, hook refresh booking customer và session generation; port chưa đưa đủ các thay đổi này vào. Điều này ảnh hưởng preview PDF và đồng bộ/state sau đổi phiên, không được giải quyết bằng việc chỉ có đủ API route.
- Onboarding mới của nguồn có lựa chọn chủ thể pháp lý và loại giấy tờ đại diện; form/validation/adapter port vẫn cần đồng bộ thêm.
- Một số contract về direct-user permissions, staff my-work-items, mobile context và review chi nhánh cần đối chiếu tiếp về payload/hành vi, ngoài sự tồn tại route.
- Quyền delegated actual-time và quyền services/manage chưa trùng hoàn toàn giữa source và port. Không mở rộng quyền để đạt route parity khi chưa có kiểm chứng scope tương ứng.
- Những sửa đổi hạ tầng cần thiết cho Laravel gồm session/CSRF, base URL, Socket.IO qua proxy, media và idempotency. Dữ liệu seed tham chiếu là dữ liệu demo, không phải database gốc.

Danh sách file khác nội dung được lưu đầy đủ trong inventory; số file giống byte không được quy đổi thành phần trăm tương đương nghiệp vụ. Các báo cáo 07/10 là lịch sử, không thay thế kết quả phiên này.

## Chạy bản đã cập nhật

```powershell
docker compose -f docker-compose.laravel.yml up -d --build --wait
npm run build
```

Mở `http://localhost:8000/beautybook`; refresh trang để dùng bundle mới. Không cần reset database hoặc seed lại toàn bộ.
