import { activeScopes, canAt, roleAt } from './authScope.js';

// These controls follow actual API capabilities, not the union of all user roles.
export function operationsRights(user, { businessId, branchId } = {}) {
  const ctx = { tenantId: businessId, branchId };
  const owner = !!businessId && roleAt(user, 'BUSINESS_OWNER', businessId);
  const receptionist = !!businessId && !!branchId && roleAt(user, 'RECEPTIONIST', businessId, branchId);
  const ownerCan = (code) => owner && canAt(user, code, ctx);
  return {
    owner,
    operator: owner || receptionist,
    impact: ownerCan('booking:update:tenant'),
    ownership: ownerCan('business:update:tenant'),
  };
}

export function operationsContext(user, accessibleBranches, preferredBranchId = '') {
  const branches = (accessibleBranches || []).filter((branch) => branch.id && operationsRights(user, {
    businessId: branch.businessId || branch.business?.id, branchId: branch.id,
  }).operator);
  const selected = branches.find((branch) => branch.id === preferredBranchId) || branches[0];
  const businessId = selected?.businessId || selected?.business?.id
    || activeScopes(user).find((scope) => scope.code === 'BUSINESS_OWNER' && scope.businessId)?.businessId || '';
  return { branches, branchId: selected?.id || '', businessId };
}

export function operationsTab(requested, rights) {
  const allowed = ['impact', 'ownership'].filter((key) => rights[key]);
  return allowed.includes(requested) ? requested : allowed[0] || '';
}

export function operationRows(rows, context, kind = 'branch') {
  return (rows || []).filter((row) => kind === 'business'
    ? row.businessId === context.businessId
    : (row.branchId || row.branch?.id) === context.branchId);
}

export async function loadOperationsData(user, context, api) {
  const rights = operationsRights(user, context);
  const { businessId } = context;
  const [impacts, transfers, versions] = await Promise.all([
    rights.impact ? api.impactApi.list() : [],
    rights.ownership ? api.ownershipApi.list(businessId) : [],
    rights.ownership ? api.ownershipApi.versions(businessId) : null,
  ]);
  return {
    impacts: operationRows(impacts, context, 'business'), transfers, versions,
  };
}
