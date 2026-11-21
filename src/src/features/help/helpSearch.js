// Search of the Help cards: client side, no dependency, pure functions.
// Lowercase, accents stripped, stopwords dropped, prefix match for words of 3+ letters.
// A search starts at 3 typed characters: one or two letters would find nothing useful while the person is still typing.
// Weight: question > keywords > answer.

const STOP = new Set((
  // Italian
  'il lo la i gli le un uno una di a da in con su per tra fra e o ma se che chi cui non si mi ti ci vi ' +
  'del dello della dei degli delle al allo alla ai agli alle dal dallo dalla dai dagli dalle nel nello nella nei negli nelle ' +
  'sul sullo sulla sui sugli sulle come dove quando cosa cose quale quali quanto posso devo voglio fare faccio ' +
  'ho ha hanno sono e era anche piu mio mia miei mie suo sua questo questa questi queste quello quella ' +
  // English
  'the an of to in on at for and or but if is are was be been do does did how what where when which who can could ' +
  'should would i my me we you your it its this that these those with from by as into about so not no'
).split(/\s+/))

export const MIN_PREFIX = 3
export const MIN_QUERY = 3
export const MAX_QUERY = 80

// "Città" and "citta" are the same word, "Clienti!" and "clienti" too.
export function normalize(text) {
  return String(text ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .trim()
}

const words = (text) => { const n = normalize(text); return n ? n.split(' ') : [] }

// The words of a query that mean something. A query made only of stopwords still searches (as typed).
export function queryTokens(query) {
  const all = words(query)
  const kept = all.filter((w) => !STOP.has(w))
  return [...new Set(kept.length ? kept : all)]
}

// 0 = no match, 1 = prefix match (3+ letters), 2 = whole word.
function wordMatch(token, ws) {
  let best = 0
  for (const w of ws) {
    if (w === token) return 2
    if (token.length >= MIN_PREFIX && w.startsWith(token)) best = 1
  }
  return best
}

const W = { question: 10, keyword: 5, answer: 1 }

// Plain text of an answer: markdown marks and link targets removed, so a route token never matches.
function plainAnswer(md) {
  return String(md ?? '').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/[*_]/g, '')
}

function prepare(card) {
  if (!card._p) {
    Object.defineProperty(card, '_p', {
      enumerable: false,
      value: {
        q: words(card.question),
        k: (card.keywords ?? []).map((k) => ({ phrase: k, ws: words(k) })),
        a: words(plainAnswer(card.answer)),
      },
    })
  }
  return card._p
}

// Exact words count in full, a prefix match 70%.
const part = (m, weight) => (m === 2 ? weight : m === 1 ? weight * 0.7 : 0)

function scoreCard(card, tokens) {
  const p = prepare(card)
  let sum = 0
  let matched = 0
  let inQuestion = 0
  let inQuestionOrKeywords = 0
  const viaVotes = new Map() // keyword phrase -> how well it matches the query
  for (const t of tokens) {
    const q = wordMatch(t, p.q)
    let k = 0
    for (const kw of p.k) {
      const m = wordMatch(t, kw.ws)
      if (m > k) k = m
      if (m) viaVotes.set(kw.phrase, (viaVotes.get(kw.phrase) ?? 0) + m)
    }
    const a = wordMatch(t, p.a)
    const s = Math.max(part(q, W.question), part(k, W.keyword), part(a, W.answer))
    if (!s) continue
    sum += s
    matched++
    if (q) inQuestion++
    if (q || k) inQuestionOrKeywords++
  }
  // "Found by: ..." only when the question alone does not explain the match.
  const via = inQuestion < tokens.length && viaVotes.size ? [...viaVotes.entries()].sort((x, y) => y[1] - x[1])[0][0] : ''
  return { matched, sum, inQuestionOrKeywords, via }
}

// Cards for a query, best first: [{ card, score, via }]. `strong` says the top card is clearly the one asked for
// (every word is in its question or keywords and it is well ahead of the next), so the page may open it.
export function searchCards(cards, query) {
  if (String(query ?? '').trim().length < MIN_QUERY) return { tokens: [], results: [], strong: false }
  const tokens = queryTokens(query)
  if (!tokens.length) return { tokens, results: [], strong: false }
  const need = Math.max(1, Math.ceil(tokens.length * 0.5))
  const results = []
  for (const card of cards) {
    const s = scoreCard(card, tokens)
    if (s.matched < need) continue
    results.push({ card, score: s.sum * (s.matched / tokens.length), via: s.via, covered: s.inQuestionOrKeywords === tokens.length })
  }
  results.sort((a, b) => b.score - a.score || (a.card.order ?? 0) - (b.card.order ?? 0))
  const top = results[0]
  const strong = !!top && top.covered && (results.length === 1 || top.score >= results[1].score * 1.5)
  return { tokens, results: results.map(({ card, score, via }) => ({ card, score, via })), strong }
}

// Split a text into [{ text, hit }] so the words that matched can be marked. Whole words are marked.
export function highlightParts(text, tokens) {
  const str = String(text ?? '')
  if (!tokens?.length) return [{ text: str, hit: false }]
  const parts = []
  const re = /[\p{L}\p{N}]+/gu
  let last = 0
  let m
  while ((m = re.exec(str))) {
    const n = normalize(m[0])
    const hit = tokens.some((t) => n === t || (t.length >= MIN_PREFIX && n.startsWith(t)))
    if (!hit) continue
    if (m.index > last) parts.push({ text: str.slice(last, m.index), hit: false })
    parts.push({ text: m[0], hit: true })
    last = m.index + m[0].length
  }
  if (last < str.length) parts.push({ text: str.slice(last), hit: false })
  return parts.length ? parts : [{ text: str, hit: false }]
}

// What the search box accepts: plain text only. Tags (a script, any HTML) are dropped and only their text stays;
// stray angle brackets, control characters and zero-width characters are removed; the length is capped.
// (Nothing typed here is ever rendered as HTML, this is on top of that.)
export function sanitizeQuery(input) {
  return String(input ?? '')
    .replace(/<(script|style)[^>]*>[\s\S]*?(<\/\1\s*>|$)/gi, ' ')
    .replace(/<\/?[a-z!][^>]*>?/gi, ' ')
    .replace(/[<>]/g, '')
    // eslint-disable-next-line no-control-regex -- removing control characters is the point
    .replace(/[\u0000-\u001f\u007f\u200b-\u200f\u2028-\u202e\ufeff]/g, '')
    .replace(/\s{2,}/g, ' ')
    .replace(/^\s+/, '')
    .slice(0, MAX_QUERY)
}
