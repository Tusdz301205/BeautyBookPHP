// Whether a customer can place a booking that holds a wallet deposit. The server is the authority (it re-checks the
// quote, the lab allow-list and the balance inside the booking transaction); this only explains the state up front.
// `walletMode` false means the deposit feature is off and bookings behave as before.

const toInt = (value) => { const number = Number(value); return Number.isFinite(number) ? Math.trunc(number) : 0; };

export function depositReadiness({ walletMode, directMode, terms, wallet, labAvailable }) {
  // Direct mode: the customer pays the business's own account after booking, so no wallet or balance is involved.
  if (directMode && !walletMode) {
    if (!terms) return { ok: false, required: true, direct: true, reason: 'TERMS_UNAVAILABLE', shortfall: 0 };
    return toInt(terms.depositVnd) === 0 ? { ok: true, required: false, reason: null, shortfall: 0 } : { ok: true, required: true, direct: true, reason: null, shortfall: 0 };
  }
  if (!walletMode) return { ok: true, required: false, reason: null, shortfall: 0 };
  if (!terms) return { ok: false, required: true, reason: 'TERMS_UNAVAILABLE', shortfall: 0 };
  if (toInt(terms.depositVnd) === 0) return { ok: true, required: false, reason: null, shortfall: 0 };
  if (!labAvailable) return { ok: false, required: true, reason: 'NOT_IN_LAB', shortfall: 0 };
  const shortfall = Math.max(0, toInt(terms.depositVnd) - toInt(wallet?.availableVnd));
  return shortfall > 0 ? { ok: false, required: true, reason: 'INSUFFICIENT_BALANCE', shortfall } : { ok: true, required: true, reason: null, shortfall: 0 };
}

/** The remaining amount is paid at the branch; the deposit is part of the service price, not a surcharge. */
export function depositSummary(terms, wallet) {
  const deposit = toInt(terms?.depositVnd);
  return { total: toInt(terms?.totalVnd), deposit, remaining: toInt(terms?.remainingVnd), availableAfter: Math.max(0, toInt(wallet?.availableVnd) - deposit), cancellationHours: toInt(terms?.cancellationHours) };
}

/**
 * A recurring series holds one deposit per bookable occurrence, all-or-nothing, so the wallet must cover their sum.
 * `quotes` is positional by occurrence index (null for an occurrence that cannot be booked): the server checks each
 * occurrence against its own quote.
 */
export function seriesTerms(preview) {
  const occurrences = preview?.occurrences ?? [];
  const rows = occurrences.map((row, position) => ({ row, position })).filter(({ row }) => row.available && row.walletDeposit).map(({ row, position }) => ({
    index: toInt(row.index ?? position), startAt: row.appointmentDate ?? null, totalVnd: toInt(row.walletDeposit.totalVnd), depositVnd: toInt(row.walletDeposit.depositVnd), remainingVnd: toInt(row.walletDeposit.remainingVnd),
  }));
  const sum = (key) => rows.reduce((total, row) => total + row[key], 0);
  // Every occurrence is priced on its own (its date, its staff member), so amounts may differ: totals are sums of the rows,
  // the same rows the server stores, and a single "per occurrence" figure exists only when they really are all equal.
  const uniform = rows.length > 0 && rows.every((row) => row.depositVnd === rows[0].depositVnd && row.totalVnd === rows[0].totalVnd);
  return {
    count: rows.length,
    depositVnd: sum('depositVnd'),
    perVnd: uniform ? rows[0].depositVnd : null,
    uniform,
    totalVnd: sum('totalVnd'),
    remainingVnd: sum('remainingVnd'),
    cancellationHours: toInt(occurrences.find((row) => row.available && row.walletDeposit)?.walletDeposit?.cancellationHours),
    rows,
    quotes: occurrences.map((row) => (row.available && row.walletDeposit ? row.walletDeposit.quote : null)),
  };
}
