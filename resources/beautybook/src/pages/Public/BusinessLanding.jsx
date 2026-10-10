import {
  ArrowRight,
  BarChart3,
  CalendarDays,
  Check,
  Clock3,
  FileCheck2,
  Scissors,
  ShieldCheck,
  UsersRound,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { PublicShell } from '../../components/layout/PublicShell';
import { HomeMedia } from '../../components/public/HomeMedia';
import { getHomeMedia, homeMediaRatios } from '../../config/homeMedia';

const capabilities = [
  [CalendarDays, 'Lịch hẹn trực quan', 'Vận hành theo ngày, tuần hoặc tháng; theo dõi trạng thái và xử lý thay đổi tại đúng lịch hẹn.'],
  [Scissors, 'Dịch vụ và gói liệu trình', 'Quản lý giá, thời lượng, chuyên môn nhân viên và những liệu trình nhiều bước trong cùng một lịch hẹn.'],
  [UsersRound, 'Đội ngũ theo chi nhánh', 'Phân quyền quản lý, lễ tân và chuyên viên; năng lực dịch vụ luôn gắn với đúng cơ sở.'],
  [Clock3, 'Giữ chỗ an toàn', 'Kiểm tra giờ mở cửa và xung đột theo thời gian thực để tránh hai lịch dùng cùng một chuyên viên.'],
  [BarChart3, 'Báo cáo vận hành', 'Theo dõi lịch hẹn, doanh thu và hiệu suất từ dữ liệu phát sinh trong hệ thống.'],
  [ShieldCheck, 'Quyền truy cập rõ ràng', 'Mỗi tài khoản chỉ xem và xử lý dữ liệu trong phạm vi doanh nghiệp, chi nhánh được phân quyền.'],
];

const onboarding = [
  'Tạo tài khoản chủ doanh nghiệp',
  'Hoàn thiện hồ sơ theo 4 bước',
  'Xem trước trang hiển thị trên marketplace',
  'Gửi giấy tờ và chờ quản trị BeautyBook xét duyệt',
];

export default function BusinessLanding() {
  return (
    <PublicShell compact>
      <div className="bb-business">
        <section className="bb-business-hero">
          <div className="bb-business-hero__copy">
            <p className="bb-business-kicker">BeautyBook Business</p>
            <h1>Một nơi để vận hành cơ sở làm đẹp — từ lịch hẹn đầu tiên.</h1>
            <p className="bb-business-lead">
              Đưa dịch vụ lên marketplace, sắp lịch đội ngũ và theo dõi hoạt động
              theo từng chi nhánh trong một không gian làm việc thống nhất.
            </p>
            <div className="bb-business-actions">
              <Link className="bb-business-button bb-business-button--primary" to="/register/business">
                Đăng ký cơ sở của bạn <ArrowRight size={17} />
              </Link>
              <Link className="bb-business-button bb-business-button--text" to="/login?from=%2Fregister%2Fbusiness" state={{ from: '/register/business' }}>
                Đã có tài khoản? Đăng nhập
              </Link>
            </div>
            <p className="bb-business-assurance">
              <FileCheck2 size={17} /> Cơ sở chỉ được công khai sau khi hồ sơ được xét duyệt.
            </p>
          </div>

          <div className="bb-business-hero__visual" aria-label="Minh họa BeautyBook Business">
            <HomeMedia
              src={getHomeMedia('business', 'hero')}
              alt="Ảnh minh họa bàn làm việc vận hành cơ sở làm đẹp"
              ratio={homeMediaRatios.editorial}
              className="bb-business-hero__media"
            />
            <div className="bb-business-product">
              <div className="bb-business-product__bar">
                <span />
                <strong>Không gian cơ sở</strong>
                <small>Tuần này</small>
              </div>
              <div className="bb-business-product__body">
                <aside>
                  <b>Tổng quan</b>
                  <span className="is-active">Lịch hẹn</span>
                  <span>Dịch vụ</span>
                  <span>Đội ngũ</span>
                </aside>
                <div className="bb-business-product__calendar">
                  <div>
                    <p>Lịch vận hành</p>
                    <Clock3 size={18} />
                  </div>
                  <ol>
                    {['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5'].map((day) => <li key={day}>{day}</li>)}
                  </ol>
                  <div className="bb-business-product__grid" aria-hidden="true">
                    <i /><i /><i /><i /><i /><i />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section className="bb-business-proof" aria-label="Giá trị cốt lõi">
          <p>Nhận đặt lịch trực tuyến</p>
          <p>Quản lý nhiều chi nhánh</p>
          <p>Kiểm soát quyền truy cập</p>
          <p>Không công khai trước duyệt</p>
        </section>

        <section className="bb-business-section" id="business-capabilities">
          <header className="bb-business-section__heading">
            <p className="bb-business-kicker">Từ marketplace tới quầy lễ tân</p>
            <h2>Các công cụ cùng nhìn vào một nguồn dữ liệu.</h2>
            <p>Không cần ghép nhiều màn hình rời rạc để biết hôm nay cơ sở đang vận hành như thế nào.</p>
          </header>
          <div className="bb-business-capabilities">
            {capabilities.map(([Icon, title, description], index) => (
              <article key={title}>
                <span className="bb-business-capabilities__number">0{index + 1}</span>
                <Icon size={22} />
                <h3>{title}</h3>
                <p>{description}</p>
              </article>
            ))}
          </div>
        </section>

        <section className="bb-business-onboarding">
          <div>
            <p className="bb-business-kicker">Bắt đầu nhẹ nhàng</p>
            <h2>Tạo tài khoản trước. Hoàn thiện cơ sở theo nhịp của bạn.</h2>
            <p>
              Wizard tự lưu bản nháp để bạn có thể dừng và tiếp tục trên thiết
              bị khác. Hồ sơ, dịch vụ dự kiến và giấy tờ xác minh được tách rõ
              trước khi gửi duyệt.
            </p>
          </div>
          <ol>
            {onboarding.map((item, index) => (
              <li key={item}>
                <span>{index + 1}</span>
                <p>{item}</p>
                <Check size={17} />
              </li>
            ))}
          </ol>
        </section>

        <section className="bb-business-cta">
          <p className="bb-business-kicker">BeautyBook Business</p>
          <h2>Sẵn sàng xây không gian đặt lịch của riêng bạn?</h2>
          <p>Đăng ký miễn phí để bắt đầu hồ sơ. Việc tạo tài khoản không tự động đưa cơ sở lên marketplace.</p>
          <Link className="bb-business-button bb-business-button--light" to="/register/business">
            Bắt đầu với BeautyBook Business <ArrowRight size={17} />
          </Link>
        </section>
      </div>
    </PublicShell>
  );
}
