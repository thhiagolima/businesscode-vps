/**
 * Formats a cents amount as a BRL string (e.g. 1234 → "R$ 12,34").
 *
 * Accepts null/undefined safely → "R$ 0,00".
 * Negative values are preserved (e.g. -500 → "R$ -5,00").
 */
export function brl(cents: number | null | undefined): string {
  if (cents === null || cents === undefined || Number.isNaN(cents)) {
    return 'R$ 0,00'
  }
  return 'R$ ' + (cents / 100).toFixed(2).replace('.', ',')
}
