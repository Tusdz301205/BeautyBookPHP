import { branchMediaById, businessMediaById, serviceMediaById } from './entityMedia.generated';

const responsiveMedia = ({ folder, stem, widths, width, height, sizes, alt, illustration = true }) => Object.freeze({
  src: `/images/beautybook/${folder}/${stem}-${widths.at(-1)}.webp`,
  srcSet: widths.map((item) => `/images/beautybook/${folder}/${stem}-${item}.webp ${item}w`).join(', '),
  sizes,
  width,
  height,
  alt,
  illustration,
});

export const homeMedia = Object.freeze({
  hero: Object.freeze({
    primary: responsiveMedia({
      folder: 'home',
      stem: 'beautybook-home-hero',
      widths: [480, 768, 1024],
      width: 1122,
      height: 1402,
      sizes: '(max-width: 720px) 86vw, (max-width: 1100px) 36vw, 368px',
      alt: 'Bộ sản phẩm chăm sóc sắc đẹp và dụng cụ làm tóc',
    }),
  }),
  categories: Object.freeze({
    hair: responsiveMedia({ folder: 'categories', stem: 'hair-care-editorial', widths: [320, 640, 960], width: 1448, height: 1086, sizes: '(max-width: 640px) 72vw, (max-width: 1100px) 42vw, 272px', alt: 'Dụng cụ chăm sóc và tạo kiểu tóc' }),
    nails: responsiveMedia({ folder: 'categories', stem: 'nail-care-editorial', widths: [320, 640, 960], width: 1448, height: 1086, sizes: '(max-width: 640px) 72vw, (max-width: 1100px) 42vw, 272px', alt: 'Sơn móng và dụng cụ chăm sóc móng' }),
    skincare: responsiveMedia({ folder: 'categories', stem: 'skincare-editorial', widths: [320, 640, 960], width: 1448, height: 1086, sizes: '(max-width: 640px) 72vw, (max-width: 1100px) 42vw, 272px', alt: 'Sản phẩm chăm sóc da và dụng cụ massage mặt' }),
    spa: responsiveMedia({ folder: 'categories', stem: 'spa-wellness-editorial', widths: [320, 640, 960], width: 1448, height: 1086, sizes: '(max-width: 640px) 72vw, (max-width: 1100px) 42vw, 272px', alt: 'Khăn spa, đá massage và dầu chăm sóc cơ thể' }),
  }),
  salons: Object.freeze({}),
  availability: Object.freeze({
    editorial: responsiveMedia({ folder: 'home', stem: 'availability-planning-editorial', widths: [480, 768, 1200], width: 1672, height: 941, sizes: '(max-width: 900px) 100vw, 50vw', alt: 'Ảnh minh họa lịch hẹn làm đẹp với đồng hồ và vật dụng chăm sóc' }),
  }),
  finalCta: Object.freeze({
    editorial: responsiveMedia({ folder: 'home', stem: 'final-cta-editorial', widths: [480, 768, 1200], width: 1672, height: 941, sizes: '(max-width: 900px) 100vw, 50vw', alt: 'Ảnh minh họa vật dụng cho một buổi chăm sóc sắc đẹp' }),
  }),
  auth: Object.freeze({
    login: responsiveMedia({ folder: 'auth', stem: 'auth-login-editorial', widths: [360, 720, 1080], width: 1402, height: 1122, sizes: '(max-width: 900px) 100vw, 46vw', alt: '' }),
    register: responsiveMedia({ folder: 'auth', stem: 'auth-register-editorial', widths: [360, 720, 1080], width: 1402, height: 1122, sizes: '(max-width: 900px) 100vw, 46vw', alt: '' }),
    recovery: responsiveMedia({ folder: 'auth', stem: 'auth-recovery-editorial', widths: [360, 720, 1080], width: 1402, height: 1122, sizes: '(max-width: 900px) 100vw, 46vw', alt: '' }),
    invitation: responsiveMedia({ folder: 'auth', stem: 'auth-invitation-editorial', widths: [360, 720, 1080], width: 1402, height: 1122, sizes: '(max-width: 900px) 100vw, 46vw', alt: '' }),
  }),
  business: Object.freeze({
    hero: responsiveMedia({ folder: 'business', stem: 'business-hero-editorial', widths: [480, 768, 1200], width: 1672, height: 941, sizes: '(max-width: 900px) 78vw, 30rem', alt: 'Ảnh minh họa bàn làm việc vận hành cơ sở làm đẹp' }),
  }),
  details: Object.freeze({
    branch: null,
    service: null,
    staff: null,
  }),
});

export const homeMediaRatios = Object.freeze({
  hero: '4 / 5',
  category: '4 / 3',
  salon: '16 / 10',
  editorial: '16 / 9',
  auth: '5 / 4',
  detailHero: '4 / 3',
});

export function getHomeMedia(group, key) {
  return homeMedia[group]?.[key] ?? null;
}

function normalizeCategory(value = '') {
  return String(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd')
    .toLowerCase();
}

export function getCategoryMedia(category) {
  const value = normalizeCategory([category?.slug, category?.name, category?.code].filter(Boolean).join(' '));
  return mediaForText(value);
}

export function getServiceFallbackMedia(service) {
  return service?.generatedMedia || service?.entityMedia || serviceMediaById[service?.id] || null;
}

function mediaForText(value = '') {
  if (/\b(nail|nails|mong|manicure|pedicure|son gel)\b/.test(value)) return homeMedia.categories.nails;
  if (/cham soc da|da mat|skincare|facial|tri mun|mun|peel|cap am|tre hoa|serum|long may|noi mi|lash|phun xam|dieu khac|trang diem|makeup/.test(value)) return homeMedia.categories.skincare;
  if (/\b(toc|hair)\b|cat toc|nhuom|uon toc|duoi toc|tao kieu|phuc hoi toc|goi dau/.test(value)) return homeMedia.categories.hair;
  if (/\bspa\b|massage|body|thu gian|duong sinh|triet long|tay long|wax|tam trang/.test(value)) return homeMedia.categories.spa;
  return null;
}

export function getBranchFallbackMedia(branch) {
  return branch?.generatedMedia || branch?.entityMedia || branchMediaById[branch?.id] || null;
}

export function getBusinessFallbackMedia(business) {
  return business?.generatedMedia || business?.entityMedia || businessMediaById[business?.id] || null;
}
