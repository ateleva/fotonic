// The markdown subset the Help answers use, parsed to plain data (rendered by HelpMarkdown.jsx, never as HTML):
// paragraphs, "- " and "1. " lists, **bold**, *italic*, [text](app:route) and [text](https://...) links.

// Inline: [{ t:'text', text } | { t:'b'|'i', children } | { t:'a', href, children }]
export function parseInline(src) {
  const out = []
  let rest = String(src ?? '')
  const re = /\[([^\]]+)\]\(([^)\s]+)\)|\*\*([^*]+)\*\*|\*([^*]+)\*/
  while (rest) {
    const m = re.exec(rest)
    if (!m) { out.push({ t: 'text', text: rest }); break }
    if (m.index > 0) out.push({ t: 'text', text: rest.slice(0, m.index) })
    if (m[1] !== undefined) out.push({ t: 'a', href: m[2], children: parseInline(m[1]) })
    else if (m[3] !== undefined) out.push({ t: 'b', children: parseInline(m[3]) })
    else out.push({ t: 'i', children: parseInline(m[4]) })
    rest = rest.slice(m.index + m[0].length)
  }
  return out
}

// Blocks: [{ t:'p', inline } | { t:'ul'|'ol', items: [inline] }]
export function parseMarkdown(src) {
  const blocks = []
  let list = null
  let para = []
  const flushPara = () => { if (para.length) { blocks.push({ t: 'p', inline: parseInline(para.join(' ')) }); para = [] } }
  const flushList = () => { if (list) { blocks.push(list); list = null } }
  for (const raw of String(src ?? '').replace(/\r\n/g, '\n').split('\n')) {
    const line = raw.trim()
    const ul = /^[-*]\s+(.*)$/.exec(line)
    const ol = /^\d+\.\s+(.*)$/.exec(line)
    if (!line) { flushPara(); flushList(); continue }
    if (ul || ol) {
      flushPara()
      const t = ul ? 'ul' : 'ol'
      if (!list || list.t !== t) { flushList(); list = { t, items: [] } }
      list.items.push(parseInline((ul ?? ol)[1]))
      continue
    }
    flushList()
    para.push(line)
  }
  flushPara(); flushList()
  return blocks
}

// The plain text of inline nodes (used for the card's text, never shown with a link target).
export function inlineText(nodes) {
  return nodes.map((n) => (n.t === 'text' ? n.text : inlineText(n.children))).join('')
}
