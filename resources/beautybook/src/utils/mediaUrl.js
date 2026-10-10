// Uploaded media paths belong to the API origin; generated assets stay on the frontend.
export function uploadedMediaUrl(value, apiBase = import.meta.env?.VITE_API_BASE || '/api/v1', browserOrigin = globalThis.location?.origin) {
  if (!value) return '';
  try {
    // Laravel deploys with a relative API base. Resolve it against the page
    // origin before using the API origin for uploaded media URLs.
    const url = new URL(value, new URL(apiBase, browserOrigin).origin);
    return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
  } catch { return ''; }
}
