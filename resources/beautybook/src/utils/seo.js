const rawSiteUrl = String(import.meta.env.VITE_SITE_URL || '').trim();

const normalizeSiteUrl = (value) => {
  if (!value) return '';
  try {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol)) return '';
    return url.href.replace(/\/$/, '');
  } catch {
    return '';
  }
};

const siteUrl = normalizeSiteUrl(rawSiteUrl);

export function getSiteUrl() {
  return siteUrl;
}

export function getCanonicalUrl(pathname = '/') {
  if (!siteUrl) return '';
  try {
    return new URL(pathname || '/', `${siteUrl}/`).href;
  } catch {
    return '';
  }
}

export function toSeoAbsoluteUrl(value) {
  if (!value) return '';
  try {
    const url = new URL(value);
    if (['http:', 'https:'].includes(url.protocol)) return url.href;
  } catch {
    if (!siteUrl) return value;
  }
  if (!siteUrl) return value;
  try {
    return new URL(value, `${siteUrl}/`).href;
  } catch {
    return value;
  }
}

function ensureMeta(attribute, key) {
  let meta = document.head.querySelector(`meta[${attribute}="${key}"]`);
  if (!meta) {
    meta = document.createElement('meta');
    meta.setAttribute(attribute, key);
    document.head.appendChild(meta);
  }
  return meta;
}

function setMeta(attribute, key, value) {
  const meta = ensureMeta(attribute, key);
  if (value) meta.setAttribute('content', value);
  else meta.removeAttribute('content');
}

function setCanonical(href) {
  let link = document.head.querySelector('link[rel="canonical"]');
  if (!href) {
    link?.remove();
    return;
  }
  if (!link) {
    link = document.createElement('link');
    link.setAttribute('rel', 'canonical');
    document.head.appendChild(link);
  }
  link.setAttribute('href', href);
}

function setStructuredData(items) {
  const id = 'beautybook-structured-data';
  let script = document.getElementById(id);
  const rows = (Array.isArray(items) ? items : [items]).filter(Boolean);
  if (!rows.length) {
    script?.remove();
    return;
  }
  if (!script) {
    script = document.createElement('script');
    script.id = id;
    script.type = 'application/ld+json';
    document.head.appendChild(script);
  }
  script.textContent = JSON.stringify({
    '@context': 'https://schema.org',
    '@graph': rows,
  });
}

export function applySeo({
  title,
  description,
  image,
  imageAlt = 'Hình ảnh BeautyBook',
  canonicalPath,
  indexable = true,
  ogType = 'website',
  structuredData = [],
}) {
  const canonical = indexable ? getCanonicalUrl(canonicalPath) : '';
  const imageSource = typeof image === 'string' ? image : image?.src;
  const resolvedImage = toSeoAbsoluteUrl(imageSource);
  const robots = indexable
    ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
    : 'noindex, nofollow';

  if (title) document.title = title;
  setMeta('name', 'description', description || '');
  setMeta('name', 'robots', robots);
  setMeta('property', 'og:type', ogType);
  setMeta('property', 'og:site_name', 'BeautyBook');
  setMeta('property', 'og:title', title || 'BeautyBook');
  setMeta('property', 'og:description', description || '');
  setMeta('property', 'og:url', canonical);
  setMeta('property', 'og:image', resolvedImage);
  setMeta('property', 'og:image:alt', resolvedImage ? imageAlt : '');
  setMeta('name', 'twitter:card', 'summary_large_image');
  setMeta('name', 'twitter:title', title || 'BeautyBook');
  setMeta('name', 'twitter:description', description || '');
  setMeta('name', 'twitter:image', resolvedImage);
  setMeta('name', 'twitter:image:alt', resolvedImage ? imageAlt : '');
  setCanonical(canonical);
  setStructuredData(indexable && canonical ? structuredData : []);
}
