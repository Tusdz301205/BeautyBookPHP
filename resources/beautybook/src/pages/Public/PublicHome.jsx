import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  ArrowRight,
  BadgeCheck,
  CalendarCheck,
  ChevronDown,
  Clock3,
  Heart,
  MapPin,
  Search,
  ShieldCheck,
  Sparkles,
  Star,
  UserRoundCheck,
  X,
} from 'lucide-react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import toast from 'react-hot-toast';
import { branchesApi, savedServicesApi, servicesApi } from '../../api/apiClient';
import { PublicShell } from '../../components/layout/PublicShell';
import { HomeMedia } from '../../components/public/HomeMedia';
import { MarketplaceRail } from '../../components/public/MarketplaceRail';
import { Select } from '../../components/ui';
import { getBranchFallbackMedia, getCategoryMedia, getHomeMedia, getServiceFallbackMedia, homeMediaRatios } from '../../config/homeMedia';
import { useAuthStore } from '../../store/authStore';
import { CUSTOMER_ACCOUNT_REQUIRED } from '../../utils/authScope';

const filterKeys = ['serviceQuery', 'categoryId', 'area', 'minPrice', 'maxPrice', 'minRating', 'sort'];
const emptyFilters = Object.freeze({
  serviceQuery: '',
  categoryId: '',
  area: '',
  minPrice: '',
  maxPrice: '',
  minRating: '',
  sort: '',
});

const bookingSteps = [
  ['01', 'Chọn cơ sở và dịch vụ', 'So sánh thông tin, mức giá và những dịch vụ đang nhận lịch.'],
  ['02', 'Chọn chuyên viên và giờ', 'Hệ thống kiểm tra giờ mở cửa, năng lực dịch vụ và các lịch đã giữ chỗ.'],
  ['03', 'Xác nhận thông tin', 'Kiểm tra lại chi tiết trước khi gửi yêu cầu đặt lịch.'],
  ['04', 'Theo dõi lịch hẹn', 'Đăng nhập để xem trạng thái và quản lý các lịch hẹn của riêng bạn.'],
];

const benefits = [
  [BadgeCheck, 'Cơ sở đủ điều kiện nhận lịch', 'Danh sách công khai chỉ trả về chi nhánh đang hoạt động, có dịch vụ và nhân viên khả dụng.'],
  [Search, 'Tìm theo nhu cầu thật', 'Lọc theo tên cơ sở, dịch vụ, nhóm dịch vụ, khu vực và khoảng giá.'],
  [Clock3, 'Kiểm tra lịch trước khi xác nhận', 'Giờ trống được xác định sau khi bạn chọn dịch vụ và chuyên viên.'],
  [ShieldCheck, 'Đánh giá đã kiểm duyệt', 'Điểm đánh giá trên thẻ cơ sở chỉ tổng hợp từ các đánh giá đã được duyệt.'],
  [UserRoundCheck, 'Chọn người thực hiện', 'Luồng đặt lịch cho phép chọn chuyên viên phù hợp với dịch vụ đã chọn.'],
  [CalendarCheck, 'Một luồng đặt lịch xuyên suốt', 'Lựa chọn của bạn được giữ xuyên suốt các bước cho tới màn hình xác nhận.'],
];

function asList(response) {
  if (Array.isArray(response)) return response;
  if (Array.isArray(response?.data)) return response.data;
  return [];
}

function readFilters(searchParams) {
  return filterKeys.reduce((result, key) => ({ ...result, [key]: searchParams.get(key) || '' }), {});
}

function formatMoney(value) {
  return `${Number(value).toLocaleString('vi-VN')}₫`;
}

function SectionHeading({ kicker, title, description, action }) {
  return (
    <div className="bb-home-section-heading">
      <div>
        <p className="bb-home-kicker">{kicker}</p>
        <h2>{title}</h2>
        {description ? <p>{description}</p> : null}
      </div>
      {action}
    </div>
  );
}

function HomeSearch({ categories, initialValues, compact = false }) {
  const navigate = useNavigate();
  const [draft, setDraft] = useState(() => ({ ...emptyFilters, ...initialValues }));
  const advancedCount = ['minRating', 'minPrice', 'maxPrice', 'sort'].filter((key) => String(draft[key] || '').trim()).length;

  useEffect(() => {
    setDraft({ ...emptyFilters, ...initialValues });
  }, [initialValues]);

  const setField = (key, value) => setDraft((current) => ({ ...current, [key]: value }));

  const submit = (event) => {
    event.preventDefault();
    const query = new URLSearchParams();
    filterKeys.forEach((key) => {
      const value = String(draft[key] || '').trim();
      if (value) query.set(key, value);
    });
    navigate(`/explore${query.size ? `?${query.toString()}` : ''}`);
  };

  const reset = () => {
    setDraft({ ...emptyFilters });
    if (compact) navigate('/explore');
  };

  return (
    <form className={`bb-home-search ${compact ? 'bb-home-search--compact' : ''}`} onSubmit={submit} aria-label="Tìm dịch vụ làm đẹp">
      <div className="bb-home-search__lead">
        <div className="bb-home-field bb-home-field--search">
          <label htmlFor={compact ? 'explore-salon-search' : 'home-salon-search'}>Bạn muốn làm dịch vụ gì?</label>
          <div className="bb-home-field__control">
            <Search size={18} aria-hidden="true" />
            <input
              id={compact ? 'explore-salon-search' : 'home-salon-search'}
              value={draft.serviceQuery}
              onChange={(event) => setField('serviceQuery', event.target.value)}
              placeholder="Ví dụ: chăm sóc da, sơn gel, cắt tóc"
              autoComplete="off"
            />
            {draft.serviceQuery ? (
              <button type="button" aria-label="Xóa tên dịch vụ" onClick={() => setField('serviceQuery', '')}><X size={16} /></button>
            ) : null}
          </div>
        </div>
        <div className="bb-home-field">
          <label htmlFor={compact ? 'explore-category' : 'home-category'}>Nhóm nhu cầu</label>
          <div className="bb-home-field__control">
            <Sparkles size={18} aria-hidden="true" />
            <Select id={compact ? 'explore-category' : 'home-category'} value={draft.categoryId} onChange={(event) => setField('categoryId', event.target.value)} searchable searchPlaceholder="Tìm loại dịch vụ" aria-label="Loại dịch vụ trên BeautyBook">
              <option value="">Tất cả dịch vụ</option>
              {categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}
            </Select>
          </div>
        </div>
        <div className="bb-home-field">
          <label htmlFor={compact ? 'explore-area' : 'home-area'}>Khu vực</label>
          <div className="bb-home-field__control">
            <MapPin size={18} aria-hidden="true" />
            <input
              id={compact ? 'explore-area' : 'home-area'}
              value={draft.area}
              onChange={(event) => setField('area', event.target.value)}
              placeholder="Quận, tỉnh hoặc thành phố"
              autoComplete="address-level1"
            />
            {draft.area ? (
              <button type="button" aria-label="Xóa khu vực" onClick={() => setField('area', '')}><X size={16} /></button>
            ) : null}
          </div>
        </div>
        <button className="bb-home-search__submit" type="submit">
          <Search size={18} aria-hidden="true" />
          <span>Tìm kiếm</span>
        </button>
      </div>

      <details className="bb-home-search__advanced">
        <summary>Bộ lọc chi tiết{advancedCount ? ` (${advancedCount})` : ''} <ChevronDown size={15} aria-hidden="true" /></summary>
        <div className="bb-home-search__advanced-grid">
          <label>
            <span>Đánh giá tối thiểu</span>
            <Select value={draft.minRating || ''} onChange={(event) => setField('minRating', event.target.value)}><option value="">Tất cả</option><option value="4">Từ 4 sao</option><option value="4.5">Từ 4,5 sao</option></Select>
          </label>
          <label>
            <span>Giá từ</span>
            <input type="number" min="0" step="1000" inputMode="numeric" value={draft.minPrice} onChange={(event) => setField('minPrice', event.target.value)} placeholder="0" />
          </label>
          <label>
            <span>Giá đến</span>
            <input type="number" min="0" step="1000" inputMode="numeric" value={draft.maxPrice} onChange={(event) => setField('maxPrice', event.target.value)} placeholder="2.000.000" />
          </label>
          <label>
            <span>Sắp xếp</span>
            <Select aria-label="Sắp xếp kết quả" value={draft.sort} onChange={(event) => setField('sort', event.target.value)}>
              <option value="">Mới cập nhật</option>
              <option value="rating">Đánh giá cao</option>
              <option value="popular">Nhiều lượt đặt</option>
              <option value="price_asc">Giá thấp đến cao</option>
              <option value="price_desc">Giá cao đến thấp</option>
            </Select>
          </label>
          <button type="button" className="bb-home-search__reset" onClick={reset}>Xóa bộ lọc</button>
        </div>
      </details>
    </form>
  );
}

function LoadingTiles({ count = 4, className = '' }) {
  return (
    <div className={className} aria-busy="true" aria-label="Đang tải dữ liệu">
      {Array.from({ length: count }, (_, index) => (
        <div className="bb-home-skeleton" key={index} aria-hidden="true">
          <span /><span /><span />
        </div>
      ))}
    </div>
  );
}

function InlineState({ title, description, onRetry }) {
  return (
    <div className="bb-home-inline-state" role={onRetry ? 'alert' : 'status'}>
      <Sparkles size={22} aria-hidden="true" />
      <h3>{title}</h3>
      <p>{description}</p>
      {onRetry ? <button type="button" onClick={onRetry}>Thử tải lại</button> : null}
    </div>
  );
}

function CategorySection({ categories, loading, error, retry }) {
  return (
    <section id="services" className="bb-home-section bb-home-categories">
      <SectionHeading
        kicker="Tìm theo nhu cầu"
        title="Bắt đầu từ dịch vụ bạn muốn"
        description="Các nhóm bên dưới được tải trực tiếp từ danh mục dịch vụ đang có trong hệ thống."
        action={<Link className="bb-home-text-link" to="/explore">Xem tất cả <ArrowRight size={16} /></Link>}
      />
      {loading ? <LoadingTiles count={4} className="bb-home-category-grid" /> : error ? (
        <InlineState title="Chưa tải được nhóm dịch vụ" description={error} onRetry={retry} />
      ) : categories.length ? (
        <MarketplaceRail label="Nhóm dịch vụ làm đẹp" className="bb-home-category-grid">
          {categories.slice(0, 8).map((category, index) => {
            const categoryMedia = getCategoryMedia(category);
            return (
            <Link className="bb-home-category" key={category.id} to={`/explore?categoryId=${encodeURIComponent(category.id)}`}>
              <HomeMedia
                src={categoryMedia}
                alt={categoryMedia?.alt || `Minh họa nhóm dịch vụ ${category.name}`}
                ratio={homeMediaRatios.category}
                label={category.name}
              />
              <span className="bb-home-category__meta">0{index + 1}</span>
              <h3>{category.name}</h3>
              <span className="bb-home-category__action">Khám phá <ArrowRight size={15} aria-hidden="true" /></span>
            </Link>
          );})}
        </MarketplaceRail>
      ) : (
        <InlineState title="Chưa có nhóm dịch vụ" description="Danh mục sẽ hiển thị khi có nhóm dịch vụ công khai." />
      )}
    </section>
  );
}

function EstablishmentCard({ branch }) {
  const title = branch.name || branch.branch_name || 'Cơ sở BeautyBook';
  const branchName = branch.branch_name && branch.branch_name !== title ? branch.branch_name : '';
  const address = [branch.address, branch.district].filter(Boolean).join(', ');
  const categories = Array.isArray(branch.categories) ? branch.categories.slice(0, 3) : [];
  const detailPath = `/explore/branches/${branch.id}`;

  return (
    <article className="bb-home-establishment-card">
      <Link className="bb-home-establishment-card__link" to={detailPath} aria-label={`Xem cơ sở ${title}`}>
        <div className="bb-home-establishment-card__media">
          <HomeMedia
            src={branch.coverImage || null}
            fallbackSrc={getBranchFallbackMedia(branch)}
            fallbackAlt={'Ảnh minh họa nhóm dịch vụ tại ' + (branch.name || branch.business?.name || 'cơ sở làm đẹp')}
            alt={`Hình ảnh cơ sở ${title}`}
            ratio={homeMediaRatios.salon}
            label={title}
          />
          {Number(branch.rating) > 0 ? (
            <span className="bb-home-establishment-card__rating">
              <Star size={14} fill="currentColor" aria-hidden="true" />
              {Number(branch.rating).toFixed(1)}
            </span>
          ) : null}
        </div>

        <div className="bb-home-establishment-card__body">
          <div className="bb-home-establishment-card__heading">
            <span>
              <strong>{title}</strong>
              {branchName ? <small>{branchName}</small> : null}
            </span>
            <ArrowRight size={18} aria-hidden="true" />
          </div>

          <p className="bb-home-establishment-card__address">
            <MapPin size={15} aria-hidden="true" />
            {address || 'Địa chỉ đang được cập nhật'}
          </p>

          {categories.length ? (
            <div className="bb-home-establishment-card__categories">
              {categories.map((category) => <span key={category.id}>{category.name}</span>)}
            </div>
          ) : null}

          <div className="bb-home-establishment-card__facts">
            {Number(branch.minPrice) > 0 ? <span>Giá từ <strong>{formatMoney(branch.minPrice)}</strong></span> : null}
            {Number(branch.services) > 0 ? <span>{branch.services} dịch vụ</span> : null}
            {branch.workingHours?.summary ? <span>{branch.workingHours.summary}</span> : null}
          </div>
        </div>
      </Link>
    </article>
  );
}

function EstablishmentSection({ branches, loading, error, retry }) {
  return (
    <section id="salons" className="bb-home-section bb-home-establishments">
      <SectionHeading
        kicker="Cơ sở nổi bật"
        title="Khám phá địa điểm làm đẹp phù hợp với bạn"
        description="Các cơ sở bên dưới đã được duyệt, đang hoạt động và đủ điều kiện nhận lịch trong dữ liệu công khai hiện tại."
        action={<Link className="bb-home-text-link" to="/explore">Xem tất cả cơ sở <ArrowRight size={16} /></Link>}
      />
      {loading ? (
        <LoadingTiles count={4} className="bb-home-establishment-grid" />
      ) : error ? (
        <InlineState title="Chưa tải được danh sách cơ sở." description="Vui lòng thử lại sau ít phút." onRetry={retry} />
      ) : branches.length ? (
        <div className="bb-home-establishment-grid">
          {branches.map((branch) => <EstablishmentCard key={branch.id} branch={branch} />)}
        </div>
      ) : (
        <InlineState title="Chưa có cơ sở phù hợp để hiển thị." description="Các cơ sở đủ điều kiện nhận lịch sẽ xuất hiện tại đây." />
      )}
    </section>
  );
}

function SalonCard({ branch: service, saved, onToggleSaved, returnTo }) {
  const title = service.displayName || service.name;
  const branchName = service.branchName || 'Chi nhánh';
  const businessName = service.businessName || '';
  const category = service.canonicalServiceName || service.categoryName || 'Dịch vụ làm đẹp';
  const address = [service.address, service.districtName, service.provinceName].filter(Boolean).join(', ');
  const serviceImage = service.images?.[0]?.media?.url || service.images?.[0]?.url || service.imageUrl || service.coverImage || null;
  const fallbackMedia = getServiceFallbackMedia(service);
  const detailState = returnTo ? { returnTo } : undefined;

  return (
    <article className="bb-home-salon-card">
      <Link className="bb-home-salon-card__media-link" to={`/explore/services/${service.id}`} state={detailState} aria-label={`Xem ${title}`}>
        <HomeMedia
          src={serviceImage}
          fallbackSrc={fallbackMedia}
          alt={`Hình ảnh dịch vụ ${title}`}
          fallbackAlt={`Minh họa dịch vụ ${title}`}
          ratio={homeMediaRatios.salon}
          label={title}
        />
        {service.rating > 0 ? <span className="bb-home-salon-card__rating"><Star size={14} fill="currentColor" /> {Number(service.rating).toFixed(1)}</span> : null}
      </Link>
      <div className="bb-home-salon-card__body">
        <div className="bb-home-salon-card__eyebrow">
          <span>{category}</span>
          <strong>{formatMoney(service.price)}</strong>
        </div>
        <h3><Link to={`/explore/services/${service.id}`} state={detailState}>{title}</Link></h3>
        <p className="bb-home-salon-card__business">{branchName}{businessName ? ` · ${businessName}` : ''}</p>
        <p className="bb-home-salon-card__address"><MapPin size={15} aria-hidden="true" />{address || 'Địa chỉ đang được cập nhật'}</p>
        <p className="bb-home-salon-card__facts">
          <span>{service.durationMinutes || 0} phút</span>
          <span aria-hidden="true">·</span>
          <span>{service.availabilitySummary || `${service.availableStaffCount || 0} chuyên viên`}</span>
        </p>
        <div className="bb-home-salon-card__actions">
          {onToggleSaved ? <button type="button" onClick={() => onToggleSaved(service.id)} aria-label={saved ? `Bỏ lưu ${title}` : `Lưu ${title}`}><Heart size={15} fill={saved ? 'currentColor' : 'none'} />{saved ? 'Đã lưu' : 'Lưu'}</button> : <Link to="/login" state={{ from: `/explore/services/${service.id}` }}><Heart size={15} />Lưu</Link>}
          <Link to={`/explore/services/${service.id}`} state={detailState}>Xem chi tiết</Link>
          <Link to={`/book?branchId=${encodeURIComponent(service.branchId)}&serviceId=${encodeURIComponent(service.id)}`}>Đặt dịch vụ <ArrowRight size={15} /></Link>
        </div>
      </div>
    </article>
  );
}

function SalonSection({ branches, loading, error, retry, exploreMode = false, kicker, title, description, savedIds, onToggleSaved, returnTo }) {
  return (
    <section id={exploreMode ? 'results' : undefined} className={`bb-home-section bb-home-salons ${exploreMode ? 'bb-home-salons--explore' : ''}`}>
      <SectionHeading
        kicker={kicker || (exploreMode ? 'Kết quả theo dịch vụ' : 'Dịch vụ được quan tâm trên BeautyBook')}
        title={title || (exploreMode ? 'Dịch vụ phù hợp với nhu cầu của bạn' : 'Bắt đầu từ điều bạn muốn làm')}
        description={description || (exploreMode
          ? (loading ? 'Đang tìm trong các dịch vụ thực sự có thể đặt.' : `${branches.length} lựa chọn dịch vụ phù hợp trong dữ liệu hiện tại.`)
          : 'Mỗi kết quả là một dịch vụ có giá, thời lượng, chi nhánh và nhân sự đang nhận lịch.')}
        action={!exploreMode ? <Link className="bb-home-text-link" to="/explore">Khám phá thêm <ArrowRight size={16} /></Link> : null}
      />
      {loading ? <LoadingTiles count={exploreMode ? 6 : 3} className="bb-home-salon-grid" /> : error ? (
        <InlineState title="Chưa tải được danh sách cơ sở" description={error} onRetry={retry} />
      ) : branches.length ? (
        exploreMode
          ? <div className="bb-home-salon-grid">{branches.map((branch) => <SalonCard branch={branch} saved={savedIds?.has(branch.id)} onToggleSaved={onToggleSaved} returnTo={returnTo} key={branch.id} />)}</div>
          : <MarketplaceRail label={title || 'Cơ sở được đề xuất'} className="bb-home-salon-grid">{branches.map((branch) => <SalonCard branch={branch} saved={savedIds?.has(branch.id)} onToggleSaved={onToggleSaved} key={branch.id} />)}</MarketplaceRail>
      ) : (
        <div className="bb-home-empty-results">
          <p className="bb-home-kicker">Không có kết quả</p>
          <h3>Thử mở rộng lựa chọn của bạn</h3>
          <p>Bỏ bớt điều kiện về khu vực, mức giá hoặc nhóm dịch vụ để xem thêm lựa chọn.</p>
          <Link className="bb-home-button bb-home-button--secondary" to="/explore">Xóa toàn bộ bộ lọc</Link>
        </div>
      )}
    </section>
  );
}

function AvailabilitySection() {
  return (
    <section className="bb-home-availability">
      <div className="bb-home-availability__copy">
        <p className="bb-home-kicker">Lịch phù hợp với bạn</p>
        <h2>Giờ trống chỉ xuất hiện sau những lựa chọn đúng.</h2>
        <p>BeautyBook kiểm tra giờ mở cửa và xung đột lịch sau khi bạn chọn cơ sở, dịch vụ và chuyên viên. Vì vậy trang chủ không hiển thị giờ trống ước lượng.</p>
        <Link className="bb-home-text-link" to="/explore">Chọn cơ sở trước <ArrowRight size={16} /></Link>
      </div>
      <HomeMedia
        src={getHomeMedia('availability', 'editorial')}
        alt="Ảnh minh họa lịch hẹn làm đẹp với đồng hồ và vật dụng chăm sóc"
        ratio={homeMediaRatios.editorial}
        label="Your time, beautifully planned"
      />
    </section>
  );
}

function HowItWorks() {
  return (
    <section id="how-it-works" className="bb-home-section bb-home-process">
      <SectionHeading
        kicker="Một hành trình rõ ràng"
        title="Từ ý định đến lịch hẹn, trong bốn nhịp"
        description="Mỗi bước dẫn tới đúng màn hình trong quy trình đặt lịch hiện có."
      />
      <ol className="bb-home-process__list">
        {bookingSteps.map(([number, title, text]) => (
          <li key={number}>
            <span>{number}</span>
            <div><h3>{title}</h3><p>{text}</p></div>
          </li>
        ))}
      </ol>
    </section>
  );
}

function BenefitsSection() {
  return (
    <section className="bb-home-section bb-home-benefits">
      <SectionHeading
        kicker="Đặt lịch với sự an tâm"
        title="Thông tin vừa đủ để bạn quyết định"
        description="Không thêm con số quảng cáo; chỉ trình bày những gì hệ thống đang thực sự kiểm tra và hỗ trợ."
      />
      <div className="bb-home-benefits__grid">
        {benefits.map(([Icon, title, text], index) => (
          <article key={title}>
            <span className="bb-home-benefits__index">0{index + 1}</span>
            <Icon size={21} strokeWidth={1.7} aria-hidden="true" />
            <div><h3>{title}</h3><p>{text}</p></div>
          </article>
        ))}
      </div>
    </section>
  );
}

function FinalCta() {
  return (
    <section className="bb-home-final-cta">
      <HomeMedia
        src={getHomeMedia('finalCta', 'editorial')}
        alt="Ảnh minh họa vật dụng cho một buổi chăm sóc sắc đẹp"
        ratio={homeMediaRatios.editorial}
        label="A little time for yourself"
      />
      <div>
        <p className="bb-home-kicker">Khi bạn đã sẵn sàng</p>
        <h2>Lịch làm đẹp tiếp theo có thể bắt đầu ngay đây.</h2>
        <p>Chọn cơ sở trước, rồi đi tiếp qua dịch vụ, chuyên viên và thời gian phù hợp.</p>
        <div className="bb-home-final-cta__actions">
          <Link className="bb-home-button bb-home-button--primary" to="/book">Đặt lịch ngay <ArrowRight size={16} /></Link>
          <Link className="bb-home-text-link" to="/register">Tạo tài khoản</Link>
        </div>
      </div>
    </section>
  );
}

export default function PublicHome({ exploreMode = false }) {
  const authenticated = useAuthStore((state) => state.isAuthenticated());
  const customer = useAuthStore((state) => state.isCustomer());
  const [searchParams] = useSearchParams();
  const queryKey = searchParams.toString();
  const filters = useMemo(() => readFilters(searchParams), [queryKey]);
  const [branches, setBranches] = useState([]);
  const [newBranches, setNewBranches] = useState([]);
  const [categories, setCategories] = useState([]);
  const [branchesLoading, setBranchesLoading] = useState(true);
  const [categoriesLoading, setCategoriesLoading] = useState(true);
  const [branchesError, setBranchesError] = useState('');
  const [branchPage, setBranchPage] = useState(1);
  const [hasMoreBranches, setHasMoreBranches] = useState(false);
  const [loadingMoreBranches, setLoadingMoreBranches] = useState(false);
  const [newBranchesError, setNewBranchesError] = useState('');
  const [newBranchesLoading, setNewBranchesLoading] = useState(true);
  const [categoriesError, setCategoriesError] = useState('');
  const [establishments, setEstablishments] = useState([]);
  const [establishmentsLoading, setEstablishmentsLoading] = useState(true);
  const [establishmentsError, setEstablishmentsError] = useState(false);
  const [savedIds, setSavedIds] = useState(new Set());

  useEffect(() => {
    if (!authenticated || !customer) { setSavedIds(new Set()); return; }
    void savedServicesApi.list().then((items) => setSavedIds(new Set(items.map((item) => item.branchServiceOfferingId)))).catch(() => setSavedIds(new Set()));
  }, [authenticated, customer]);

  const toggleSaved = authenticated ? async (serviceId) => {
    if (!customer) { toast.error(CUSTOMER_ACCOUNT_REQUIRED); return; }
    const wasSaved = savedIds.has(serviceId);
    setSavedIds((current) => { const next = new Set(current); if (wasSaved) next.delete(serviceId); else next.add(serviceId); return next; });
    try {
      if (wasSaved) await savedServicesApi.remove(serviceId); else await savedServicesApi.save(serviceId);
      toast.success(wasSaved ? 'Đã bỏ lưu dịch vụ' : 'Đã lưu dịch vụ');
    } catch (requestError) {
      setSavedIds((current) => { const next = new Set(current); if (wasSaved) next.add(serviceId); else next.delete(serviceId); return next; });
      toast.error(requestError.message);
    }
  } : null;

  const loadBranches = useCallback(async (nextPage = 1, append = false) => {
    const pageToLoad = typeof nextPage === 'number' ? nextPage : 1;
    if (append) setLoadingMoreBranches(true);
    else setBranchesLoading(true);
    setBranchesError('');
    try {
      const current = readFilters(new URLSearchParams(queryKey));
      const params = exploreMode ? {
        query: current.serviceQuery,
        location: current.area,
        canonicalServiceId: current.categoryId,
        minPrice: current.minPrice,
        maxPrice: current.maxPrice,
        minRating: current.minRating,
        sort: current.sort,
        page: pageToLoad,
        limit: 12,
      } : { sort: 'rating', limit: 10 };
      const response = await servicesApi.search(params);
      const rows = asList(response);
      setBranches((current) => append ? [...current, ...rows.filter((row) => !current.some((item) => item.id === row.id))] : rows);
      setBranchPage(pageToLoad);
      setHasMoreBranches(Boolean(exploreMode && response?.hasMore));
    } catch (error) {
      setBranchesError(error.message || 'Không thể tải danh sách dịch vụ.');
    } finally {
      if (append) setLoadingMoreBranches(false);
      else setBranchesLoading(false);
    }
  }, [exploreMode, queryKey]);

  const loadNewBranches = useCallback(async () => {
    if (exploreMode) return;
    setNewBranchesLoading(true);
    setNewBranchesError('');
    try {
      const response = await servicesApi.search({ limit: 10 });
      setNewBranches(asList(response));
    } catch (error) {
      setNewBranchesError(error.message || 'Không thể tải dịch vụ mới cập nhật.');
    } finally {
      setNewBranchesLoading(false);
    }
  }, [exploreMode]);

  const loadCategories = useCallback(async () => {
    setCategoriesLoading(true);
    setCategoriesError('');
    try {
      const response = await servicesApi.getCategories();
      setCategories(asList(response));
    } catch (error) {
      setCategoriesError(error.message || 'Không thể tải nhóm dịch vụ.');
    } finally {
      setCategoriesLoading(false);
    }
  }, []);

  const loadEstablishments = useCallback(async () => {
    if (exploreMode) return;
    setEstablishmentsLoading(true);
    setEstablishmentsError(false);
    try {
      const response = await branchesApi.getAll({ sort: 'rating', limit: 4 });
      setEstablishments(asList(response).slice(0, 4));
    } catch (error) {
      if (import.meta.env.DEV) console.error('[PublicHome] Không thể tải danh sách cơ sở:', error);
      setEstablishments([]);
      setEstablishmentsError(true);
    } finally {
      setEstablishmentsLoading(false);
    }
  }, [exploreMode]);

  useEffect(() => { loadBranches(); }, [loadBranches]);
  useEffect(() => { loadNewBranches(); }, [loadNewBranches]);
  useEffect(() => { loadCategories(); }, [loadCategories]);
  useEffect(() => { loadEstablishments(); }, [loadEstablishments]);

  if (exploreMode) {
    return (
      <PublicShell compact>
        <div className="bb-home bb-home--explore">
          <section className="bb-explore-hero">
            <div className="bb-home-boundary">
              <p className="bb-home-kicker">BeautyBook Explore</p>
              <h1>Tìm đúng dịch vụ, rồi chọn nơi phù hợp.</h1>
              <p>Kết quả chỉ gồm dịch vụ tại chi nhánh đã được duyệt, đang hoạt động và có chuyên viên nhận lịch.</p>
            </div>
          </section>
          <div className="bb-home-boundary bb-explore-search-wrap">
            <HomeSearch categories={categories} initialValues={filters} compact />
          </div>
          <div className="bb-home-boundary">
            <SalonSection branches={branches} loading={branchesLoading} error={branchesError} retry={loadBranches} exploreMode savedIds={savedIds} onToggleSaved={toggleSaved} returnTo={`/explore${queryKey ? `?${queryKey}` : ''}`} />
            {hasMoreBranches && !branchesError ? <div className="bb-explore-load-more"><button type="button" className="bb-home-button bb-home-button--secondary" disabled={loadingMoreBranches} onClick={() => loadBranches(branchPage + 1, true)}>{loadingMoreBranches ? 'Đang tải…' : 'Xem thêm dịch vụ'}</button></div> : null}
          </div>
        </div>
      </PublicShell>
    );
  }

  return (
    <PublicShell compact>
      <div className="bb-home">
        <section className="bb-home-hero">
          <div className="bb-home-boundary bb-home-hero__grid">
            <div className="bb-home-hero__copy">
              <p className="bb-home-kicker">Salon · Spa · Beauty services</p>
              <h1>Tìm dịch vụ làm đẹp hợp với bạn.</h1>
              <p className="bb-home-hero__lede">Bắt đầu từ điều bạn muốn làm, rồi so sánh giá, thời lượng, chi nhánh và chuyên viên đang nhận lịch.</p>
              <HomeSearch categories={categories} initialValues={emptyFilters} />
              <div className="bb-home-hero__actions">
                <Link className="bb-home-button bb-home-button--primary" to="/explore">Khám phá dịch vụ <ArrowRight size={16} /></Link>
                <Link className="bb-home-text-link" to="/book">Bắt đầu đặt lịch</Link>
              </div>
              <p className="bb-home-hero__note"><ShieldCheck size={16} aria-hidden="true" /> Chỉ hiển thị cơ sở đang đủ điều kiện nhận đặt lịch.</p>
            </div>
            <div className="bb-home-hero__visual">
              <HomeMedia
                src={getHomeMedia('hero', 'primary')}
                alt="Bộ sản phẩm chăm sóc sắc đẹp và dụng cụ làm tóc"
                ratio={homeMediaRatios.hero}
                label="Beauty, on your time"
                eager
              />
              <p className="bb-home-hero__caption"><span>01</span> Một khoảng dành cho vẻ đẹp của riêng bạn.</p>
            </div>
          </div>
        </section>

        <div className="bb-home-boundary">
          <CategorySection categories={categories} loading={categoriesLoading} error={categoriesError} retry={loadCategories} />
          <EstablishmentSection branches={establishments} loading={establishmentsLoading} error={establishmentsError} retry={loadEstablishments} />
          <SalonSection branches={branches} loading={branchesLoading} error={branchesError} retry={loadBranches} savedIds={savedIds} onToggleSaved={toggleSaved} />
          <SalonSection branches={newBranches} loading={newBranchesLoading} error={newBranchesError} retry={loadNewBranches} kicker="Thêm lựa chọn" title="Dịch vụ cho lần hẹn tiếp theo" description="Các lựa chọn khác đang đủ điều kiện nhận lịch trong dữ liệu công khai hiện tại." savedIds={savedIds} onToggleSaved={toggleSaved} />
          <AvailabilitySection />
          <HowItWorks />
          <BenefitsSection />
          <FinalCta />
        </div>
      </div>
    </PublicShell>
  );
}
