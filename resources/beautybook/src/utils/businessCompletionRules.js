export function ownershipVersionsReady(transfer) {
  return transfer?.legalEntityVersion?.verificationStatus === 'VERIFIED'
    && transfer?.payoutAccountVersion?.verificationStatus === 'VERIFIED';
}

export function savedServiceBookingPath(item) {
  if (!item?.available || !item?.offering?.id || !item?.offering?.branchId) return null;
  return `/book?branchId=${encodeURIComponent(item.offering.branchId)}&serviceId=${encodeURIComponent(item.offering.id)}`;
}
