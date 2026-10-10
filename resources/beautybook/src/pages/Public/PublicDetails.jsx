import { useEffect, useState } from 'react';
import { uploadedMediaUrl } from '../../utils/mediaUrl';
import { ArrowLeft, ArrowRight, CalendarCheck, Clock3, Heart, Image as ImageIcon, MapPin, Scissors, Star, UserRound } from 'lucide-react';
import toast from 'react-hot-toast';
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom';
import { branchesApi, reviewsApi, savedServicesApi, servicesApi, staffApi } from '../../api/apiClient';
import { useAuthStore } from '../../store/authStore';
import { PublicShell } from '../../components/layout/PublicShell';
import { HomeMedia } from '../../components/public/HomeMedia';
import { getBranchFallbackMedia, getHomeMedia, getServiceFallbackMedia, homeMediaRatios } from '../../config/homeMedia';
import { EmptyState, ErrorState, Skeleton } from '../../components/ui';
import { CUSTOMER_ACCOUNT_REQUIRED } from '../../utils/authScope';
import { applySeo, getCanonicalUrl, toSeoAbsoluteUrl } from '../../utils/seo';

const money = (value) => `${Number(value || 0).toLocaleString('vi-VN')}₫`;
const days = ['Chủ nhật', 'Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7'];

function breadcrumbStructuredData(name, path) {
  const homeUrl = getCanonicalUrl('/');
  const exploreUrl = getCanonicalUrl('/explore');
  const pageUrl = getCanonicalUrl(path);
  if (!homeUrl || !exploreUrl || !pageUrl || !name) return null;
  return {
    '@type': 'BreadcrumbList',
    itemListElement: [
      { '@type': 'ListItem', position: 1, name: 'BeautyBook', item: homeUrl },
      { '@type': 'ListItem', position: 2, name: 'Khám phá', item: exploreUrl },
      { '@type': 'ListItem', position: 3, name, item: pageUrl },
    ],
  };
}

function branchStructuredData(branch, image) {
  const url = getCanonicalUrl(`/explore/branches/${branch?.id || ''}`);
  if (!branch || !url) return [];
  const ratingValue = Number(branch.stats?.averageRating);
  const reviewCount = Number(branch.stats?.totalReviews);
  const address = {
    '@type': 'PostalAddress',
    streetAddress: branch.addressLine || undefined,
    addressLocality: branch.district?.name || undefined,
    addressRegion: branch.district?.province?.name || undefined,
  };
  const hasAddress = address.streetAddress || address.addressLocality || address.addressRegion;
  return [
    {
      '@type': 'BeautySalon',
      '@id': `${url}#business`,
      url,
      name: branch.name,
      description: branch.business?.description || undefined,
      image: toSeoAbsoluteUrl(typeof image === 'string' ? image : image?.src) || undefined,
      address: hasAddress ? address : undefined,
      parentOrganization: branch.business?.name ? { '@type': 'Organization', name: branch.business.name } : undefined,
      aggregateRating: Number.isFinite(ratingValue) && reviewCount > 0
        ? { '@type': 'AggregateRating', ratingValue, reviewCount }
        : undefined,
    },
    breadcrumbStructuredData(branch.name, `/explore/branches/${branch.id}`),
  ].filter(Boolean);
}

function serviceStructuredData(service, image, reviews) {
  const url = getCanonicalUrl(`/explore/services/${service?.id || ''}`);
  if (!service || !url) return [];
  const price = Number(service.price);
  const averageRating = Number(reviews?.summary?.averageRating ?? reviews?.averageRating);
  const reviewCount = Number(reviews?.summary?.totalReviews ?? reviews?.totalRatings);
  return [
    {
      '@type': 'Service',
      '@id': `${url}#service`,
      url,
      name: service.name,
      description: service.description || undefined,
      image: toSeoAbsoluteUrl(typeof image === 'string' ? image : image?.src) || undefined,
      provider: service.branch?.name
        ? { '@type': 'BeautySalon', name: service.branch.name }
        : undefined,
      offers: Number.isFinite(price) && price >= 0
        ? { '@type': 'Offer', price, priceCurrency: 'VND', url }
        : undefined,
      aggregateRating: Number.isFinite(averageRating) && reviewCount > 0
        ? { '@type': 'AggregateRating', ratingValue: averageRating, reviewCount }
        : undefined,
    },
    breadcrumbStructuredData(service.name, `/explore/services/${service.id}`),
  ].filter(Boolean);
}

function staffStructuredData(staff, image, services) {
  const url = getCanonicalUrl(`/explore/staff/${staff?.id || ''}`);
  if (!staff || !url) return [];
  return [
    {
      '@type': 'Person',
      '@id': `${url}#person`,
      url,
      name: staff.fullName,
      jobTitle: staff.professionalTitle || undefined,
      description: staff.bio || undefined,
      image: toSeoAbsoluteUrl(typeof image === 'string' ? image : image?.src) || undefined,
      worksFor: staff.branch?.name
        ? { '@type': 'BeautySalon', name: staff.branch.name }
        : undefined,
      knowsAbout: services?.map((service) => service.name).filter(Boolean) || undefined,
    },
    breadcrumbStructuredData(staff.fullName, `/explore/staff/${staff.id}`),
  ].filter(Boolean);
}

function useDetailSeo(title, description, image, imageAlt, options = {}) {
  const imageSource = typeof image === 'string' ? image : image?.src;
  const structuredDataKey = JSON.stringify(options.structuredData || []);
  const indexable = options.indexable !== false;
  useEffect(() => {
    if (!title) return;
    applySeo({
      title: `${title} | BeautyBook`,
      description,
      image: imageSource,
      imageAlt,
      canonicalPath: window.location.pathname,
      indexable,
      structuredData: JSON.parse(structuredDataKey),
    });
  }, [title, description, imageSource, imageAlt, indexable, structuredDataKey]);
}

function clock(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? String(value).slice(0, 5) : date.toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
}

function firstMedia(images = [], fallback) {
  return images.map((item) => item?.media?.url || item?.url).find(Boolean) || fallback;
}

function DetailState({ loading, error, retry, children }) {
  if (loading) return <div className="bb-detail-loading" role="status" aria-live="polite"><span className="bb-sr-only">Đang tải nội dung…</span><Skeleton rows={9} /></div>;
  if (error) return <section className="bb-detail-error"><ErrorState message={error} onRetry={retry} /></section>;
  return children;
}

function BackLink() {
  const location = useLocation();
  const returnTo = location.state?.returnTo;
  const destination = typeof returnTo === 'string' && /^\/explore(?:\?|$)/.test(returnTo) ? returnTo : '/explore';
  return <Link to={destination} className="bb-detail-back"><ArrowLeft size={16} />Quay lại khám phá</Link>;
}

function DetailHero({ type, eyebrow, title, description, media, mediaAlt, fallbackMedia, fallbackAlt, facts }) {
  const showMedia = Boolean(media || fallbackMedia);
  return (
    <header className={`bb-detail-hero${showMedia ? '' : ' bb-detail-hero--without-media'}`}>
      <div className="bb-detail-hero__copy">
        <BackLink />
        <p className="bb-home-kicker">{eyebrow}</p>
        <h1>{title}</h1>
        {description ? <p className="bb-detail-hero__description">{description}</p> : null}
        {facts?.length ? <dl className="bb-detail-facts">{facts.filter((fact) => fact.value).map((fact) => <div key={fact.label}><dt>{fact.label}</dt><dd>{fact.value}</dd></div>)}</dl> : null}
      </div>
      {showMedia ? (
        <HomeMedia
          src={media}
          alt={mediaAlt}
          fallbackSrc={fallbackMedia}
          fallbackAlt={fallbackAlt}
          ratio={homeMediaRatios.detailHero}
          label={`Hình ảnh ${type}`}
          eager
          className="bb-detail-hero__media"
        />
      ) : null}
    </header>
  );
}

function BookingDock({ to, label, title, meta }) {
  return <aside className="bb-detail-booking-dock" aria-label="Đặt lịch"><div><CalendarCheck size={20} aria-hidden="true" /><span><small>Đặt lịch trực tuyến</small><strong>{title}</strong></span></div>{meta ? <p>{meta}</p> : null}<Link className="bb-home-button bb-home-button--primary" to={to}>{label}<ArrowRight size={16} /></Link><small>Thời gian trống được kiểm tra sau khi bạn chọn đủ dịch vụ và chuyên viên.</small></aside>;
}

function Section({ index, eyebrow, title, intro, children, className = '' }) {
  return (
    <section className={`bb-detail-section${className ? ` ${className}` : ''}`}>
      <div className="bb-detail-section__heading">
        <span aria-hidden="true">{String(index).padStart(2, '0')}</span>
        <div><p className="bb-home-kicker">{eyebrow}</p><h2>{title}</h2>{intro ? <p>{intro}</p> : null}</div>
      </div>
      <div className="bb-detail-section__body">{children}</div>
    </section>
  );
}

function Gallery({ images = [], label, fallbackMedia = null, fallbackAlt = '' }) {
  const urls = images.map((item) => item?.media?.url || item?.url).filter(Boolean);
  if (!urls.length && fallbackMedia) {
    return <div className="bb-detail-gallery bb-detail-gallery--illustration"><HomeMedia src={fallbackMedia} alt={fallbackAlt || fallbackMedia.alt} ratio="4 / 3" label={label} /></div>;
  }
  if (!urls.length) return <div className="bb-detail-empty"><ImageIcon size={21} /><p>Chưa có hình ảnh do cơ sở tải lên.</p></div>;
  return <div className="bb-detail-gallery">{urls.slice(0, 6).map((url, index) => <img key={`${url}-${index}`} src={uploadedMediaUrl(url)} alt={`${label} ${index + 1}`} loading="lazy" decoding="async" />)}</div>;
}

function ReviewList({ data }) {
  const rows = data?.data || data?.ratings || [];
  const average = data?.summary?.averageRating ?? data?.averageRating;
  const total = data?.summary?.totalReviews ?? data?.totalRatings ?? rows.length;
  if (!rows.length) return <div className="bb-detail-empty"><Star size={21} /><p>Chưa có đánh giá đã được duyệt.</p></div>;
  return (
    <div>
      <div className="bb-detail-rating"><Star size={22} className="fill-current" /><strong>{Number(average || 0).toFixed(1)}</strong><span>{total} lượt đánh giá</span></div>
      <div className="bb-detail-review-list">{rows.slice(0, 8).map((row, index) => <article key={row.id || index}><div><strong>{row.customerName || 'Khách hàng ẩn danh'}</strong><span>{row.overallRating || row.rating}/5</span></div><p>{row.comment || 'Khách hàng không để lại nhận xét.'}</p></article>)}</div>
    </div>
  );
}

function ServiceDirectory({ services = [], savedIds = new Set(), onToggleSaved, preview = false }) {
  if (!services.length) return <div className="bb-detail-empty"><Scissors size={21} /><p>Chưa có dịch vụ công khai đang nhận lịch.</p></div>;
  return <div className="bb-detail-directory bb-detail-directory--services">{services.map((service) => <div className="bb-detail-directory__service" key={service.id}><Link to={`/explore/services/${service.id}`} aria-disabled={preview} onClick={preview ? (event) => event.preventDefault() : undefined}><div><span>{service.category?.name || 'Dịch vụ'}</span><h3>{service.name}</h3><p>{service.durationMinutes ? `${service.durationMinutes} phút` : 'Thời lượng đang cập nhật'}</p></div><strong>{money(service.price)}</strong><ArrowRight size={17} aria-hidden="true" /></Link><button type="button" disabled={preview || !onToggleSaved} aria-label={savedIds.has(service.id) ? `Bỏ lưu ${service.name}` : `Lưu ${service.name}`} onClick={() => onToggleSaved?.(service.id)}><Heart size={17} fill={savedIds.has(service.id) ? 'currentColor' : 'none'} /></button></div>)}</div>;
}

function StaffDirectory({ staff = [] }) {
  if (!staff.length) return <div className="bb-detail-empty"><UserRound size={21} /><p>Chưa có chuyên viên công khai cho lựa chọn này.</p></div>;
  return <div className="bb-detail-staff-grid">{staff.map((item) => { const specialties = item.specialties?.length ? item.specialties : item.staffServices?.map((entry) => entry.service?.name).filter(Boolean); return <Link key={item.id} to={`/explore/staff/${item.id}`} className="bb-detail-staff-card"><span className="bb-detail-staff-card__avatar">{item.avatarUrl ? <img src={item.avatarUrl} alt="" loading="lazy" /> : <UserRound size={22} />}</span><span className="bb-detail-staff-card__copy"><small>{item.professionalTitle || 'Chuyên viên làm đẹp'}</small><strong>{item.fullName || 'Chuyên viên'}</strong><span>{specialties?.join(' · ') || 'Chuyên môn đang cập nhật'}</span>{item.ratingCount ? <span className="bb-detail-staff-card__rating"><Star size={14} fill="currentColor" /> {item.rating} · {item.ratingCount} đánh giá</span> : null}</span><ArrowRight size={17} aria-hidden="true" /></Link>; })}</div>;
}

export function PublicBranchDetail({ preview = false }) {
  const { id } = useParams();
  const navigate = useNavigate();
  const authenticated = useAuthStore((state) => state.isAuthenticated());
  const customer = useAuthStore((state) => state.isCustomer());
  const [branch, setBranch] = useState(null);
  const [reviews, setReviews] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [savedIds, setSavedIds] = useState(new Set());
  const load = async () => {
    setLoading(true); setError('');
    try {
      const item = await (preview ? branchesApi.getPreview(id) : branchesApi.getById(id));
      if (!item) throw new Error('Không tìm thấy cơ sở đang hoạt động.');
      setBranch(item);
      setReviews({ data: item.recentReviews || [], summary: { averageRating: item.stats?.averageRating, totalReviews: item.stats?.totalReviews } });
      if (!preview && authenticated && customer) {
        try { setSavedIds(new Set((await savedServicesApi.list()).map((saved) => saved.branchServiceOfferingId))); }
        catch { setSavedIds(new Set()); }
      }
    } catch (requestError) { setError(requestError.message || 'Không thể tải chi tiết cơ sở.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [id, preview, authenticated, customer]);
  const toggleSaved = async (serviceId) => {
    if (preview) return;
    if (!authenticated) { navigate('/login', { state: { from: `/explore/branches/${id}` } }); return; }
    if (!customer) { toast.error(CUSTOMER_ACCOUNT_REQUIRED); return; }
    const wasSaved = savedIds.has(serviceId);
    setSavedIds((current) => { const next = new Set(current); if (wasSaved) next.delete(serviceId); else next.add(serviceId); return next; });
    try { if (wasSaved) await savedServicesApi.remove(serviceId); else await savedServicesApi.save(serviceId); toast.success(wasSaved ? 'Đã bỏ lưu dịch vụ' : 'Đã lưu dịch vụ'); }
    catch (requestError) { setSavedIds((current) => { const next = new Set(current); if (wasSaved) next.add(serviceId); else next.delete(serviceId); return next; }); toast.error(requestError.message); }
  };
  const address = branch ? [branch.addressLine, branch.district?.name, branch.district?.province?.name].filter(Boolean).join(', ') : '';
  const branchSeoImage = branch ? firstMedia(branch.images, getBranchFallbackMedia(branch)) : null;
  useDetailSeo(
    branch?.name,
    branch ? `${branch.business?.name ? `${branch.business.name} · ` : ''}${address || 'Cơ sở làm đẹp đang nhận lịch trên BeautyBook.'}` : '',
    branchSeoImage,
    branch ? `Hình ảnh ${branch.name}` : '',
    {
      indexable: !preview,
      structuredData: branch && !preview ? branchStructuredData(branch, branchSeoImage) : [],
    },
  );
  return <PublicShell preview={preview}><DetailState loading={loading} error={error} retry={load}>{branch ? <article className="bb-detail-document">
    <DetailHero type="cơ sở" eyebrow="Cơ sở làm đẹp" title={branch.name} description={branch.business?.description || 'Thông tin giới thiệu đang được cơ sở cập nhật.'} media={firstMedia(branch.images)} mediaAlt={`Không gian tại ${branch.name}`} fallbackMedia={getBranchFallbackMedia(branch)} fallbackAlt={`Ảnh minh họa nhóm dịch vụ tại ${branch.name}`} facts={[{ label: 'Thương hiệu', value: branch.business?.name }, { label: 'Địa chỉ', value: address }]} bookingTo={`/book?branchId=${branch.id}`} bookingLabel="Đặt lịch tại cơ sở" />
    <BookingDock to={`/book?branchId=${branch.id}`} label="Chọn dịch vụ" title={branch.name} meta={address} />
    <Section index={1} eyebrow="Thông tin ghé thăm" title="Một địa chỉ, mọi thông tin cần thiết" intro="Giờ hoạt động được lấy trực tiếp từ hồ sơ cơ sở."><div className="bb-detail-address"><MapPin size={20} /><div><strong>{address || 'Địa chỉ đang cập nhật'}</strong><span>{branch.business?.name}</span></div></div><div className="bb-detail-hours">{branch.workingHours?.length ? branch.workingHours.map((row) => <div key={row.id || row.dayOfWeek}><strong>{days[row.dayOfWeek]}</strong><span>{row.isClosed ? 'Đóng cửa' : `${clock(row.openTime)} – ${clock(row.closeTime)}`}</span></div>) : <div className="bb-detail-empty"><Clock3 size={21} /><p>Giờ mở cửa đang được cập nhật.</p></div>}</div></Section>
    <Section index={2} eyebrow="Danh mục" title={preview ? 'Dịch vụ trong bản xem trước' : 'Dịch vụ đang nhận lịch'}><ServiceDirectory services={branch.services} savedIds={savedIds} onToggleSaved={toggleSaved} preview={preview} /></Section>
    <Section index={3} eyebrow="Đội ngũ" title="Chọn người phù hợp" className="bb-detail-section--staff"><StaffDirectory staff={branch.staff} /></Section>
    <Section index={4} eyebrow="Không gian" title="Hình ảnh do cơ sở cung cấp"><Gallery images={branch.images} label={`Hình ảnh ${branch.name}`} /></Section>
    <Section index={5} eyebrow="Trải nghiệm thật" title="Đánh giá đã được duyệt"><ReviewList data={reviews} /></Section>
  </article> : null}</DetailState></PublicShell>;
}

export function PublicServiceDetail() {
  const { id } = useParams();
  const [service, setService] = useState(null);
  const [reviews, setReviews] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const authenticated = useAuthStore((state) => state.isAuthenticated());
  const customer = useAuthStore((state) => state.isCustomer());
  const load = async () => {
    setLoading(true); setError('');
    try {
      const item = await servicesApi.getById(id);
      if (!item) throw new Error('Dịch vụ không còn nhận lịch.');
      setService(item);
      try { setReviews(await reviewsApi.getByService(id)); } catch { setReviews(null); }
      if (authenticated && customer) {
        try {
          const savedItems = await savedServicesApi.list();
          setSaved(savedItems.some((savedItem) => savedItem.branchServiceOfferingId === item.id));
        } catch { setSaved(false); }
      }
    } catch (requestError) { setError(requestError.message || 'Không thể tải dịch vụ.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [id, authenticated, customer]);
  const team = service?.staffServices?.map((entry) => entry.staff).filter(Boolean) || [];
  const onlineBookable = !service?.variants?.length || service.variants.some((variant) => variant.priceType !== 'QUOTE');
  const serviceSeoImage = service ? firstMedia(service.images, getServiceFallbackMedia(service)) : null;
  useDetailSeo(
    service?.name,
    service ? `${service.category?.name || 'Dịch vụ làm đẹp'}${service.branch?.name ? ` tại ${service.branch.name}` : ''} trên BeautyBook.` : '',
    serviceSeoImage,
    service ? `Hình ảnh dịch vụ ${service.name}` : '',
    { structuredData: service ? serviceStructuredData(service, serviceSeoImage, reviews) : [] },
  );
  return <PublicShell><DetailState loading={loading} error={error} retry={load}>{service ? <article className="bb-detail-document">
    <DetailHero type="dịch vụ" eyebrow={service.category?.name || 'Dịch vụ làm đẹp'} title={service.name} description={service.description || 'Thông tin mô tả đang được cơ sở cập nhật.'} media={firstMedia(service.images)} mediaAlt={`Dịch vụ ${service.name}`} fallbackMedia={getServiceFallbackMedia(service)} fallbackAlt={`Ảnh minh họa cho dịch vụ ${service.name}`} facts={[{ label: 'Giá', value: service.priceDisplay || money(service.price) }, { label: 'Thời lượng', value: service.durationMinutes ? `${service.durationMinutes} phút` : null }, { label: 'Địa điểm', value: [service.branch?.business?.name, service.branch?.name].filter(Boolean).join(' · ') }]} />
    {onlineBookable ? <BookingDock to={`/book?branchId=${service.branchId}&serviceId=${service.id}`} label="Đặt dịch vụ này" title={service.name} meta={`${service.priceDisplay || money(service.price)}${service.durationMinutes ? ` · ${service.durationMinutes} phút` : ''}`} /> : <aside className="bb-detail-booking-dock"><div><CalendarCheck size={20} /><span><small>Cần tư vấn trước</small><strong>{service.name}</strong></span></div><p>Cơ sở sẽ xác nhận điều kiện, thời lượng và báo giá trước khi tạo lịch.</p></aside>}
    <div className="flex flex-wrap gap-3">
      {authenticated ? <button type="button" className="bb-home-button bb-home-button--secondary" disabled={saving} onClick={async () => { if (!customer) { toast.error(CUSTOMER_ACCOUNT_REQUIRED); return; } const previous = saved; setSaved(!previous); setSaving(true); try { if (previous) await savedServicesApi.remove(service.id); else await savedServicesApi.save(service.id); toast.success(previous ? 'Đã bỏ lưu dịch vụ' : 'Đã lưu dịch vụ'); } catch (requestError) { setSaved(previous); toast.error(requestError.message); } finally { setSaving(false); } }}><Heart size={16} fill={saved ? 'currentColor' : 'none'} />{saving ? 'Đang cập nhật…' : saved ? 'Đã lưu' : 'Lưu dịch vụ'}</button> : <Link className="bb-home-button bb-home-button--secondary" to="/login" state={{ from: `/explore/services/${service.id}` }}><Heart size={16} />Đăng nhập để lưu</Link>}
    </div>
    {service.variants?.length ? <Section index={1} eyebrow="Lựa chọn" title="Giá và thời lượng theo nhu cầu"><div className="bb-detail-directory">{service.variants.map((variant) => <article key={variant.id} className="rounded-2xl border border-[var(--bb-border)] p-4"><span className="text-xs font-bold uppercase tracking-wider text-[var(--bb-brand-strong)]">{variant.priceType === 'QUOTE' ? 'Cần báo giá' : 'Có thể đặt lịch'}</span><h3 className="mt-2 font-bold">{variant.name}</h3><p className="mt-1 text-sm text-[var(--bb-muted)]">{variant.priceDisplay} · {variant.durationMinutes || 0}{variant.maxDurationMinutes ? `–${variant.maxDurationMinutes}` : ''} phút</p>{variant.eligibilityRules && <p className="mt-2 text-xs text-amber-700">Cơ sở sẽ xác nhận điều kiện trước khi thực hiện.</p>}</article>)}</div></Section> : null}
    <Section index={service.variants?.length ? 2 : 1} eyebrow="Thực hiện bởi" title="Chuyên viên phù hợp" className="bb-detail-section--staff"><StaffDirectory staff={team} /></Section>
    <Section index={service.variants?.length ? 3 : 2} eyebrow="Hình ảnh" title="Hình ảnh dịch vụ"><Gallery images={service.images} label={`Hình ảnh ${service.name}`} fallbackMedia={getServiceFallbackMedia(service)} fallbackAlt={`Ảnh minh họa cho dịch vụ ${service.name}`} /></Section>
    {service.promotionLinks?.length ? <Section index={3} eyebrow="Ưu đãi" title="Khuyến mãi đang áp dụng"><div className="bb-detail-offers">{service.promotionLinks.map(({ promotion }) => <article key={promotion.id}><span>Ưu đãi hiện hành</span><h3>{promotion.name}</h3><p>{promotion.description || 'Chi tiết ưu đãi hiển thị khi xác nhận lịch.'}</p></article>)}</div></Section> : null}
    <Section index={service.promotionLinks?.length ? 4 : 3} eyebrow="Trải nghiệm thật" title="Đánh giá dịch vụ"><ReviewList data={reviews} /></Section>
  </article> : null}</DetailState></PublicShell>;
}

export function PublicStaffDetail() {
  const { id } = useParams();
  const [staff, setStaff] = useState(null);
  const [reviews, setReviews] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const load = async () => {
    setLoading(true); setError('');
    try {
      const item = await staffApi.getPublicById(id);
      if (!item) throw new Error('Không tìm thấy chuyên viên đang hoạt động.');
      setStaff(item);
      try { setReviews(await reviewsApi.getPublicByStaff(id)); } catch { setReviews(null); }
    } catch (requestError) { setError(requestError.message || 'Không thể tải thông tin chuyên viên.'); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, [id]);
  const services = staff?.staffServices?.map((entry) => entry.service).filter(Boolean) || [];
  const staffSeoImage = staff ? (staff.avatarUrl || firstMedia(staff.images)) : null;
  const staffSocialImage = staffSeoImage || getHomeMedia('hero', 'primary');
  useDetailSeo(
    staff?.fullName,
    staff ? `${staff.professionalTitle || 'Chuyên viên làm đẹp'}${staff.branch?.name ? ` tại ${staff.branch.name}` : ''} trên BeautyBook.` : '',
    staffSocialImage,
    staffSeoImage && staff ? `Chuyên viên ${staff.fullName}` : (staffSocialImage?.alt || 'Hình ảnh BeautyBook'),
    { structuredData: staff ? staffStructuredData(staff, staffSeoImage, services) : [] },
  );
  return <PublicShell><DetailState loading={loading} error={error} retry={load}>{staff ? <article className="bb-detail-document">
    <DetailHero type="chuyên viên" eyebrow={staff.professionalTitle || 'Chuyên viên làm đẹp'} title={staff.fullName} description={staff.bio || 'Thông tin giới thiệu đang được chuyên viên cập nhật.'} media={staff.avatarUrl || firstMedia(staff.images)} mediaAlt={`Chuyên viên ${staff.fullName}`} facts={[{ label: 'Cơ sở', value: [staff.branch?.business?.name, staff.branch?.name].filter(Boolean).join(' · ') }, { label: 'Chuyên môn', value: services.map((service) => service.name).join(', ') }, ...(staff.ratingCount ? [{ label: 'Đánh giá', value: `${staff.rating}/5 · ${staff.ratingCount} lượt` }] : [])]} bookingTo={`/book?branchId=${staff.branch?.id}&staffId=${staff.id}`} bookingLabel="Đặt lịch với chuyên viên" />
    <BookingDock to={`/book?branchId=${staff.branch?.id}&staffId=${staff.id}`} label="Đặt lịch với chuyên viên" title={staff.fullName} meta={staff.professionalTitle || 'Chuyên viên làm đẹp'} />
    <Section index={1} eyebrow="Chuyên môn" title="Dịch vụ phụ trách"><ServiceDirectory services={services} /></Section>
    <Section index={2} eyebrow="Hồ sơ hình ảnh" title="Hình ảnh do cơ sở cung cấp"><Gallery images={staff.images} label={`Hình ảnh ${staff.fullName || 'chuyên viên'}`} /></Section>
    <Section index={3} eyebrow="Trải nghiệm thật" title="Đánh giá chuyên viên"><ReviewList data={reviews} /></Section>
  </article> : null}</DetailState></PublicShell>;
}
