/**
 * The year a list opens on: the current year when some work is in it, otherwise the nearest year that has
 * works (a tie goes to the earlier year). No year at all: the current one.
 *
 * @param {string[]} years       Years that have at least one work, as strings.
 * @param {number}   currentYear Today's year.
 * @returns {string}
 */
export function pickDefaultYear(years, currentYear) {
  const now = String(currentYear)
  const list = (years ?? []).map(String).filter((y) => /^\d{4}$/.test(y))
  if (list.length === 0 || list.includes(now)) return now
  let best = list[0]
  for (const y of list) {
    const d = Math.abs(Number(y) - currentYear)
    const bd = Math.abs(Number(best) - currentYear)
    if (d < bd || (d === bd && Number(y) < Number(best))) best = y
  }
  return best
}
