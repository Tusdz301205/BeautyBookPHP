export function schedulerSocketUrl({ wsUrl = import.meta.env?.VITE_WS_URL, apiBase = import.meta.env?.VITE_API_BASE || '/api/v1', browserOrigin = globalThis.location?.origin } = {}) {
  if (wsUrl?.trim()) return wsUrl.trim();
  try { return new URL(apiBase, browserOrigin).origin; }
  catch { return browserOrigin; }
}
