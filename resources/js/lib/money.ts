/**
 * Amounts arrive from the server as integer nano-dollars (1e-9 USD).
 * Pixelforge costs are fractions of a cent, so small amounts keep more decimals.
 */
const NANOS_PER_DOLLAR = 1_000_000_000;

export function formatMoney(nanos: number | null | undefined, { signed = false }: { signed?: boolean } = {}): string {
    if (nanos === null || nanos === undefined) return '—';

    const dollars = nanos / NANOS_PER_DOLLAR;
    const abs = Math.abs(dollars);
    const digits = abs === 0 || abs >= 1 ? 2 : abs >= 0.01 ? 4 : 6;

    const formatted = new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: digits,
        maximumFractionDigits: digits,
    }).format(abs);

    if (dollars < 0) return `−${formatted}`;
    return signed && dollars > 0 ? `+${formatted}` : formatted;
}

/** Balances can carry fractions of a cent; show enough to make every charge visible. */
export function formatBalance(nanos: number): string {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(
        nanos / NANOS_PER_DOLLAR,
    );
}

/** The exact stored value, e.g. "$0.169080000", for tooltips. */
export function formatExact(nanos: number | null | undefined): string {
    if (nanos === null || nanos === undefined) return '';
    const sign = nanos < 0 ? '-' : '';
    const abs = Math.abs(nanos);
    return `${sign}$${Math.floor(abs / NANOS_PER_DOLLAR)}.${String(abs % NANOS_PER_DOLLAR).padStart(9, '0')}`;
}
