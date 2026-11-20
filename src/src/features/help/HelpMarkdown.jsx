import { Link } from 'react-router-dom'
import { parseMarkdown } from './markdownParse'
import { highlightParts } from './helpSearch'
import { routeExists } from '../../router'

// Words that matched the search are marked with a tint of the theme colour (see .ftnc-help-mark in index.css).
export function Marked({ text, tokens }) {
  return highlightParts(text, tokens).map((p, i) => (p.hit ? <mark key={i} className="ftnc-help-mark">{p.text}</mark> : p.text))
}

function Inline({ nodes, tokens }) {
  return nodes.map((n, i) => {
    if (n.t === 'text') return <Marked key={i} text={n.text} tokens={tokens} />
    if (n.t === 'b') return <strong key={i} className="font-semibold text-gray-900"><Inline nodes={n.children} tokens={tokens} /></strong>
    if (n.t === 'i') return <em key={i}><Inline nodes={n.children} tokens={tokens} /></em>
    // A link: an app page (only when that page exists in this install, else plain text) or an https address.
    const inner = <Inline nodes={n.children} tokens={tokens} />
    if (n.href.startsWith('app:')) {
      const route = n.href.slice(4)
      return routeExists(route.split('?')[0])
        ? <Link key={i} to={'/' + route} className="font-medium text-fotonic-primary no-underline hover:opacity-80">{inner}</Link>
        : <span key={i}>{inner}</span>
    }
    if (/^https:\/\//.test(n.href)) {
      return <a key={i} href={n.href} target="_blank" rel="noopener noreferrer" className="font-medium text-fotonic-primary no-underline hover:opacity-80">{inner}</a>
    }
    return <span key={i}>{inner}</span>
  })
}

export default function HelpMarkdown({ text, tokens }) {
  return parseMarkdown(text).map((b, i) => {
    if (b.t === 'p') return <p key={i} className="mb-2 last:mb-0"><Inline nodes={b.inline} tokens={tokens} /></p>
    const Tag = b.t
    return (
      <Tag key={i} className={`mb-2 last:mb-0 pl-5 ${b.t === 'ul' ? 'list-disc' : 'list-decimal'}`}>
        {b.items.map((it, j) => <li key={j} className="mb-1"><Inline nodes={it} tokens={tokens} /></li>)}
      </Tag>
    )
  })
}
