/** A copy of `rows` with the row at `index` moved one place up (dir -1) or down (dir 1). */
export function moveRow(rows, index, dir) {
  const target = index + dir
  if (target < 0 || target >= rows.length) return rows
  const next = [...rows]
  const [item] = next.splice(index, 1)
  next.splice(target, 0, item)
  return next
}
