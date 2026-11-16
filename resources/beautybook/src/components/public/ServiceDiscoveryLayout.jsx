import '../../styles/glowme/service-discovery-page.css';
import {Link} from 'react-router-dom';
import {HomeMedia} from './HomeMedia';
import {getHomeMedia} from '../../config/homeMedia';

// Presentation only: PublicHome still owns queries, pagination and resources.
export default function ServiceDiscoveryLayout({search, children}) {
  return <div className="gm-discovery bb-home bb-home--explore">
    <section className="gm-discovery-hero gm-discovery-container" aria-labelledby="service-search-title">
      <div className="gm-discovery-hero__copy">
        <nav className="gm-discovery-breadcrumb" aria-label="Đường dẫn trang">
          <Link to="/">Trang chủ</Link><span aria-hidden="true">/</span><span aria-current="page">Tìm dịch vụ</span>
        </nav>
        <p className="gm-discovery-eyebrow">BEAUTYBOOK / DÀNH THỜI GIAN CHO BẠN</p>
        <h1 id="service-search-title">Tìm dịch vụ<br/><em>dành cho bạn.</em></h1>
        <p className="gm-discovery-hero__intro">Một mái tóc mới, một làn da được chăm chút, hay một giờ thư giãn. Tìm trải nghiệm bạn thích và chọn cuộc hẹn phù hợp.</p>
      </div>
      <figure className="gm-discovery-hero__visual">
        <HomeMedia src={getHomeMedia('availability','editorial')} alt="Góc chăm sóc sắc đẹp minh họa với vật liệu tự nhiên và ánh nắng ấm" ratio="4 / 3" eager className="gm-discovery-hero__image"/>
        <figcaption>Một chút chăm sóc. Một khoảng thảnh thơi.</figcaption>
      </figure>
    </section>

    <div className="gm-discovery-container">
      <section className="gm-discovery-filter" aria-labelledby="service-search-filters">
        <header><span aria-hidden="true">01</span><div><h2 id="service-search-filters">Bạn muốn chăm sóc điều gì?</h2><p>Chọn dịch vụ và khu vực bạn muốn đến.</p></div></header>
        {search}
      </section>
      <div className="gm-discovery-results">{children}</div>
    </div>
  </div>;
}
