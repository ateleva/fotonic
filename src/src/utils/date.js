import { parseISO, parse, format } from 'date-fns'

// PHP date format chars → date-fns equivalents
const PHP_MAP = {
  d: 'dd', j: 'd', D: 'EEE', l: 'EEEE',
  m: 'MM', n: 'M', F: 'MMMM', M: 'MMM',
  Y: 'yyyy', y: 'yy',
  H: 'HH', G: 'H', h: 'hh', g: 'h',
  i: 'mm', s: 'ss',
  A: 'aa', a: 'aaa',
}

// date-fns letter tokens — need single-quote escaping when used as literals
const DFN_TOKENS = new Set('yYQqMwWdDEehuHmsScnaAbBpPxXzZtToO')

function phpToDateFns(phpFmt) {
  let out = ''
  let esc = false
  for (const ch of phpFmt) {
    if (esc) {
      out += DFN_TOKENS.has(ch) ? `'${ch}'` : ch
      esc = false
      continue
    }
    if (ch === '\\') { esc = true; continue }
    if (ch in PHP_MAP) { out += PHP_MAP[ch]; continue }
    out += DFN_TOKENS.has(ch) ? `'${ch}'` : ch
  }
  return out
}

function getDateFmt() {
  const app = window.FotonicApp
  if (app?.dateFormat) return phpToDateFns(app.dateFormat)
  const locale = app?.locale ?? 'en_US'
  if (locale.startsWith('it')) return 'dd/MM/yyyy'
  if (locale.startsWith('en_GB')) return 'dd/MM/yyyy'
  return 'MM/dd/yyyy'
}

function getTimeFmt() {
  const app = window.FotonicApp
  if (app?.timeFormat) return phpToDateFns(app.timeFormat)
  const locale = app?.locale ?? 'en_US'
  if (locale.startsWith('it')) return 'HH:mm'
  return 'h:mm aa'
}

// iso = 'YYYY-MM-DD'
export function formatDate(iso) {
  if (!iso) return ''
  try { return format(parseISO(iso), getDateFmt()) } catch { return iso }
}

// hhmm = 'HH:mm' or 'HH:mm:ss' from DB
export function formatTime(hhmm) {
  if (!hhmm) return ''
  try {
    return format(parse(hhmm.slice(0, 5), 'HH:mm', new Date()), getTimeFmt())
  } catch { return hhmm }
}

// dtStr = MySQL datetime 'YYYY-MM-DD HH:mm:ss' or ISO
export function formatDateTime(dtStr) {
  if (!dtStr) return ''
  try {
    return format(new Date(dtStr), getDateFmt() + ' ' + getTimeFmt())
  } catch { return dtStr }
}
