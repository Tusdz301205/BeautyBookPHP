export const BUSINESS_REGISTRATION_PATH = '/register/business';

export function registrationReturnPath(location) {
  const query = new URLSearchParams(location.search || '').get('from');
  const candidate = query || location.state?.from;
  return typeof candidate === 'string' && /^\/(?!\/)/.test(candidate)
    && !/[\\\u0000-\u001f]/.test(candidate) ? candidate : null;
}

export function businessRegistrationTarget(sessionType) {
  return sessionType === 'salon' ? '/salon/onboarding' : null;
}

export function documentChecklist(documents, requiredTypes) {
  return requiredTypes.map((type) => ({
    type,
    complete: documents.some((item) => item.documentType === type && item.mediaId && item.documentName?.trim()),
  }));
}

export function currentLegalVersion(document) {
  return (document.versions || []).find((version) => version.version === document.currentVersion) || null;
}
