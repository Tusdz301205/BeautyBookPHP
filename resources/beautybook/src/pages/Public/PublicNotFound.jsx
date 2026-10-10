import { ArrowLeft, Search } from 'lucide-react';
import { Link } from 'react-router-dom';
import { PublicShell } from '../../components/layout/PublicShell';

export function PublicNotFound() {
  return (
    <PublicShell compact>
      <section className="bb-not-found" aria-labelledby="not-found-title">
        <div className="bb-not-found__number" aria-hidden="true">404</div>
        <div className="bb-not-found__copy">
          <p className="bb-home-kicker">Không tìm thấy trang</p>
          <h1 id="not-found-title">Đường dẫn này không còn ở đây.</h1>
          <p>Kiểm tra lại địa chỉ, trở về trang chủ hoặc tiếp tục tìm cơ sở và dịch vụ đang công khai.</p>
          <div>
            <Link className="bb-home-button bb-home-button--primary" to="/"><ArrowLeft size={16} /> Về trang chủ</Link>
            <Link className="bb-home-text-link" to="/explore"><Search size={16} /> Khám phá</Link>
          </div>
        </div>
      </section>
    </PublicShell>
  );
}
