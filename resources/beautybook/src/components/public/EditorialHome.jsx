import '../../styles/glowme/home-editorial-sections.css';
import {useCallback,useEffect,useState} from 'react';
import {Link} from 'react-router-dom';
import {ArrowRight,ArrowUpRight,Star,ShieldCheck,Clock3,MapPin,Quote,Search,CalendarDays,Sparkles,Store} from 'lucide-react';
import {HomeMedia} from './HomeMedia';
import {MarketplaceRail} from './MarketplaceRail';
import {getHomeMedia,homeMediaRatios} from '../../config/homeMedia';
import {promotionsApi,reviewsApi} from '../../api/apiClient';

export function EditorialHeading({index,eyebrow,title,children,link='/explore',linkText='Khám phá tất cả'}){return <div className="gm-section-heading"><div><p className="gm-eyebrow"><span>{index}</span>{eyebrow}</p><h2>{title}</h2>{children&&<p className="gm-section-intro">{children}</p>}</div>{link&&<Link className="gm-text-link" to={link}>{linkText}<ArrowUpRight size={17}/></Link>}</div>;}
const sanctuaryImage={src:'/images/glowme/wellness-sanctuary-v2-1600.webp',srcSet:'/images/glowme/wellness-sanctuary-v2-768.webp 768w, /images/glowme/wellness-sanctuary-v2-1600.webp 1600w',sizes:'100vw',width:1600,height:901,illustration:true};
export function EditorialHero({search}){return <><section className="gm-hero gm-wellness-hero"><HomeMedia src={sanctuaryImage} alt="Không gian spa minh họa với vòm kiến trúc, rèm vải lanh và ánh nắng tự nhiên" ratio="16 / 9" eager className="gm-wellness-hero__background"/><div className="gm-container"><div className="gm-hero__copy"><p className="gm-eyebrow">BEAUTYBOOK — CHẬM LẠI ĐỂ ĐẸP HƠN</p><h1>Đẹp hơn,<br/><span>tự tin <em>hơn.</em></span></h1><p className="gm-wellness-hero__manifesto">CHĂM CHÚT VẺ ĐẸP.<br/>DÀNH THỜI GIAN CHO RIÊNG MÌNH.</p><span className="gm-wellness-rule" aria-hidden="true"/><p className="gm-wellness-hero__description">Tìm nơi làm đẹp bạn yêu thích.<br/>Chọn cuộc hẹn, thư thả tận hưởng.</p><div className="gm-wellness-hero__actions"><Link className="gm-button" to="/explore">Tìm cuộc hẹn<ArrowRight size={18}/></Link><Link className="gm-text-link" to="/#services">Khám phá dịch vụ<ArrowRight size={17}/></Link></div></div></div><span className="gm-wellness-hero__footnote">LÀM ĐẸP · THƯ GIÃN · KHOẢNH KHẮC RIÊNG</span></section><section className="gm-search gm-container" aria-label="Tìm cuộc hẹn của bạn"><div className="gm-search__heading"><span>TÌM CUỘC HẸN TIẾP THEO</span><p>Bắt đầu từ điều bạn muốn chăm sóc.</p></div>{search}</section></>;}
export function WellnessApproach(){return <section className="gm-approach gm-container"><div className="gm-approach__copy"><p className="gm-eyebrow">BEAUTYBOOK / MỘT KHOẢNG RIÊNG</p><h2>Chăm chút vẻ đẹp.<br/><em>Nuôi dưỡng niềm vui.</em></h2><p>Không cần một dịp đặc biệt để dành thời gian cho mình. Tìm một nơi bạn yêu thích, chọn một trải nghiệm phù hợp và để những điều nhỏ làm ngày của bạn đẹp hơn.</p><Link className="gm-text-link" to="/#salons">Tìm nơi bạn muốn quay lại<ArrowUpRight size={18}/></Link><div className="gm-approach__values"><span><ShieldCheck size={17}/><strong>Cơ sở được xét duyệt</strong></span><span><Clock3 size={17}/><strong>Lịch hẹn theo nhịp của bạn</strong></span></div></div><div className="gm-approach__collage"><span className="gm-approach__halo" aria-hidden="true"/><HomeMedia src={{src:'/images/beautybook/auth/glowme-auth-portrait-v1-1200.webp',srcSet:'/images/beautybook/auth/glowme-auth-portrait-v1-480.webp 480w, /images/beautybook/auth/glowme-auth-portrait-v1-800.webp 800w, /images/beautybook/auth/glowme-auth-portrait-v1-1200.webp 1200w',sizes:'(max-width: 760px) 75vw, 36vw',width:1200,height:800}} alt="Một khoảnh khắc thư giãn trong ánh nắng ấm" ratio="4 / 5" className="gm-approach__portrait"/><div className="gm-approach__detail"><HomeMedia src={getHomeMedia('categories','spa')} alt="Khăn mềm, đá mát-xa và dầu chăm sóc cơ thể" ratio="1 / 1"/><span>Một chút nâng niu, mỗi ngày.</span></div><span className="gm-approach__signature">Dành cho riêng bạn.</span><span className="gm-approach__stamp" aria-hidden="true"><Sparkles size={24} strokeWidth={1}/><span>nâng niu<br/><em>từng điều nhỏ</em></span></span></div></section>;}
export function EditorialCampaign(){return <section className="gm-campaign gm-container"><div className="gm-campaign__image"><HomeMedia src={getHomeMedia('finalCta','editorial')} alt="Hoa và vật dụng chăm sóc trong ánh nắng nhẹ" ratio={homeMediaRatios.editorial}/><span className="gm-campaign__image-caption">NHỮNG ĐIỀU NHỎ, LÀM NÊN MỘT NGÀY ĐẸP.</span></div><div className="gm-campaign__copy"><p className="gm-eyebrow">CHĂM SÓC MÌNH, THEO CÁCH CỦA MÌNH</p><h2>Một cuộc hẹn.<br/><em>Một niềm vui mới.</em></h2><p>Một mái tóc mới. Một làn da được chăm chút. Hay đơn giản là một giờ thư giãn. Bạn xứng đáng với những điều nhỏ bé ấy.</p><Link className="gm-button gm-button--light" to="/explore">Tìm trải nghiệm của bạn<ArrowUpRight size={18}/></Link><span className="gm-campaign__signature">Chậm lại. Chăm mình. Tỏa sáng.</span></div></section>;}
// PHP public endpoints retain their existing integer IDs and response shapes.
function useEditorialFeed(services, kind) {
  const ids = services.slice(0, 6).map(service => service.id);
  const key = JSON.stringify(ids);
  const [revision, setRevision] = useState(0);
  const reload = useCallback(() => setRevision(value => value + 1), []);
  const [feed, setFeed] = useState({key: '', items: [], loading:true});
  useEffect(() => {
    let active = true;
    const serviceIds = JSON.parse(key);
    setFeed({key, items: [], loading:serviceIds.length>0});
    if (!serviceIds.length) return () => { active = false; };
    const read = kind === 'offers' ? promotionsApi.getActiveForService : reviewsApi.getByService;
    void Promise.allSettled(serviceIds.map(id => read(id))).then(results => {
      if (!results.some(result=>result.status==='fulfilled')) {
        if(active)setFeed({key,items:[],error:true});
        return;
      }
      const items = new Map();
      results.forEach((result, index) => {
        if (result.status !== 'fulfilled') return;
        const serviceId = serviceIds[index];
        const rows = kind === 'offers'
          ? (Array.isArray(result.value) ? result.value : [])
          : (Array.isArray(result.value?.ratings) ? result.value.ratings : []);
        rows.forEach(row => {
          if (kind === 'offers') {
            if (!row.id || !row.name || !['PERCENTAGE', 'FIXED_AMOUNT'].includes(row.discountType) || !(Number(row.discountValue) > 0)) return;
            if (row.discountType === 'PERCENTAGE' && Number(row.discountValue) > 100) return;
            if (!items.has(row.id)) items.set(row.id, {...row, serviceId});
          } else {
            const rating = Number(row.rating);
            if (!row.comment?.trim() || !Number.isInteger(rating) || rating < 1 || rating > 5) return;
            const reviewKey = JSON.stringify([serviceId, row.customerName, row.createdAt, row.comment, rating]);
            if (!items.has(reviewKey)) items.set(reviewKey, {...row, rating, serviceId, reviewKey});
          }
        });
      });
      if (active) setFeed({key, items: [...items.values()].slice(0, kind === 'offers' ? 3 : 6)});
    });
    return () => { active = false; };
  }, [key, kind, revision]);
  return {...(feed.key === key ? feed : {items:[],loading:true}),reload};
}
function FeedStatus({resource,empty}){
 return resource.loading?<div className="gm-resource-state" role="status"><span className="gm-loading-line"/>Đang tìm những trải nghiệm dành cho bạn…</div>:resource.error?<div className="gm-resource-state" role="alert"><p>Chưa tải được nội dung. Bạn thử lại nhé.</p><button onClick={resource.reload} type="button">Tải lại<ArrowRight size={15}/></button></div>:!resource.items.length?<div className="gm-resource-state"><p>{empty}</p><Link to="/explore">Khám phá dịch vụ<ArrowUpRight size={15}/></Link></div>:null;
}
export function EditorialOffers({services = []}) {
  const resource = useEditorialFeed(services, 'offers');
  const candidates = resource.items;
  const [clock, setClock] = useState(0);
  useEffect(() => {
    const now = Date.now();
    const boundary = Math.min(...candidates.flatMap(item => [Date.parse(item.startDate), Date.parse(item.endDate)]).filter(time => Number.isFinite(time) && time > now));
    if (!Number.isFinite(boundary)) return undefined;
    const timer = setTimeout(() => setClock(value => value + 1), Math.min(2147483647, Math.max(1, boundary - now + 1)));
    return () => clearTimeout(timer);
  }, [candidates, clock]);
  const now = Date.now();
  const offers = candidates.filter(item => Date.parse(item.startDate) <= now && now < Date.parse(item.endDate));
  return <section id="offers" className="gm-section gm-container gm-offers">
    <EditorialHeading index="04" eyebrow="THÊM MỘT CHÚT ƯU ĐÃI" title={<>Thêm một lý do<br/>để <em>chăm sóc bản thân.</em></>} link={null}>Ưu đãi đang có tại các cơ sở trên BeautyBook.</EditorialHeading>
    <FeedStatus resource={{...resource,items:offers}} empty="Chưa có ưu đãi đang hiệu lực. Những trải nghiệm làm đẹp vẫn đang chờ bạn."/>
    {offers.length>0&&<div className="gm-offers__grid">{offers.map((item, index) => {
      const service = services.find(row => row.id === item.serviceId);
      return <article key={item.id} className="gm-offer">
        <span className="gm-eyebrow">BEAUTYBOOK / ƯU ĐÃI CHỌN LỌC</span>
        <div><p>Giảm</p><strong>{item.discountType === 'PERCENTAGE' ? Number(item.discountValue) + '%' : Number(item.discountValue).toLocaleString('vi-VN') + '₫'}</strong><span className="gm-offer__index">0{index + 1}</span></div>
        <h3>{item.name}</h3><p><MapPin size={13}/>{service?.branchName || service?.businessName}</p>
        <small>{Number.isFinite(Date.parse(item.endDate)) ? `Đến ${new Date(item.endDate).toLocaleDateString('vi-VN', {timeZone: 'Asia/Ho_Chi_Minh'})} · ` : ''}Điều kiện được kiểm tra khi đặt lịch</small>
        <Link to={'/explore/services/' + item.serviceId}>Xem dịch vụ áp dụng<ArrowUpRight size={17}/></Link>
      </article>;
    })}</div>}
  </section>;
}
export function EditorialProcess(){const steps=[['01',Search,'Tìm điều bạn thích.','Khám phá dịch vụ và những cơ sở phù hợp với nhu cầu của bạn.'],['02',CalendarDays,'Chọn cuộc hẹn của bạn.','Chọn dịch vụ, nhân viên và khung giờ còn trống tại cơ sở.'],['03',Sparkles,'Sẵn sàng rạng rỡ.','Kiểm tra thông tin, gửi yêu cầu và theo dõi xác nhận từ cơ sở.']];return <section id="how-it-works" className="gm-process"><div className="gm-container"><EditorialHeading index="05" eyebrow="NHẸ NHÀNG TỪ BƯỚC ĐẦU TIÊN" title={<>Một cuộc hẹn đẹp.<br/><em>Ba bước thật đơn giản.</em></>} link={null}>Để việc chăm sóc bản thân trở thành điều bạn mong chờ.</EditorialHeading><ol>{steps.map(([n,Icon,title,text])=><li key={n}><span className="gm-process__number" aria-hidden="true">{n}</span><span className="gm-process__icon"><Icon size={25} strokeWidth={1.4}/></span><div><h3>{title}</h3><p>{text}</p></div><ArrowRight className="gm-process__arrow" size={22} strokeWidth={1}/></li>)}</ol><Link className="gm-text-link" to="/explore">Tìm cuộc hẹn đầu tiên<ArrowRight size={17}/></Link></div></section>;}
export function EditorialTestimonials({services = []}) {
  const resource = useEditorialFeed(services, 'reviews');
  const reviews = resource.items;
  return <section className="gm-section gm-container gm-testimonials">
    <EditorialHeading index="06" eyebrow="KHÁCH HÀNG THẬT, TRẢI NGHIỆM THẬT" title={<>Đẹp hơn từ những<br/><em>trải nghiệm thật.</em></>} link={null}>Những chia sẻ công khai từ khách hàng đã hoàn thành lịch hẹn.</EditorialHeading>
    <FeedStatus resource={resource} empty="Chưa có nhận xét công khai để giới thiệu. Bạn có thể xem đánh giá tại từng dịch vụ."/>
    {reviews.length>0&&<MarketplaceRail label="Trải nghiệm khách hàng" className="gm-review-rail">{reviews.map(row => {
      const service = services.find(item => item.id === row.serviceId);
      return <article key={row.reviewKey} className="gm-review">
        <div className="gm-review__top"><Quote size={30} strokeWidth={1}/><span aria-label={`${row.rating} trên 5 sao`}>{[1,2,3,4,5].map(n => <Star key={n} size={12} fill={n <= row.rating ? 'currentColor' : 'none'}/>)}</span></div>
        <blockquote>{row.comment}</blockquote>
        <div className="gm-review__person"><span>{(row.customerName || 'K').trim().slice(0,1).toUpperCase()}</span><div><strong>{row.customerName || 'Khách hàng'}</strong><small>Dịch vụ · {row.rating}/5</small></div><ShieldCheck size={16}/></div>
        <Link to={'/explore/services/' + row.serviceId+'#detail-section-6'}>{service?.displayName || service?.name}<ArrowUpRight size={14}/></Link>
      </article>;
    })}</MarketplaceRail>}
  </section>;
}
export function EditorialPartner(){return <section className="gm-partner"><div className="gm-container"><div className="gm-partner__copy"><p className="gm-eyebrow">BEAUTYBOOK / DÀNH CHO NGƯỜI LÀM ĐẸP</p><h2>Bạn chăm chút vẻ đẹp.<br/>Chúng tôi <em>kết nối cuộc hẹn.</em></h2><p>Đưa thương hiệu làm đẹp của bạn đến gần khách hàng hơn. Quản lý dịch vụ, đội ngũ và lịch hẹn trong một không gian.</p><Link className="gm-button" to="/for-business">Cùng BeautyBook phát triển<ArrowUpRight size={18}/></Link><div className="gm-partner__details"><span><Store size={14}/>Hồ sơ cơ sở</span><span><CalendarDays size={14}/>Quản lý lịch hẹn</span><span><ShieldCheck size={14}/>Xét duyệt minh bạch</span></div></div><div className="gm-partner__visual"><HomeMedia src={getHomeMedia('business','hero')} alt="Bàn làm việc và dụng cụ trong một tiệm tóc ấm áp" ratio="4 / 3"/><div className="gm-partner__note"><Store size={22} strokeWidth={1.3}/><div><strong>Không gian cho thương hiệu của bạn.</strong><span>Tập trung vào điều bạn làm tốt.</span></div><ArrowUpRight size={19}/></div></div></div></section>;}
export default function EditorialHome({search,categories,establishments,services,serviceRows}){return <div className="gm-home"><EditorialHero search={search}/><div className="gm-home-below"><WellnessApproach/><div className="gm-container gm-existing-sections">{categories}{establishments}</div><div className="gm-services-band"><div className="gm-container gm-existing-sections">{services}</div></div><EditorialCampaign/><EditorialOffers services={serviceRows}/><EditorialProcess/><EditorialTestimonials services={serviceRows}/><EditorialPartner/></div></div>;}
