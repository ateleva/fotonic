// Run: node tests/js/help-markdown.test.mjs
import { parseMarkdown, parseInline, inlineText } from '../../src/src/features/help/markdownParse.js'
let failures = 0
const eq = (label, e, a) => { if (JSON.stringify(e) !== JSON.stringify(a)) { failures++; console.log(`  FAIL ${label}\n   expected: ${JSON.stringify(e)}\n   actual:   ${JSON.stringify(a)}`) } }

eq('inline', [{ t: 'text', text: 'Vai su ' }, { t: 'a', href: 'app:customers', children: [{ t: 'text', text: 'Clienti' }] }, { t: 'text', text: ' e premi ' }, { t: 'b', children: [{ t: 'text', text: 'Nuovo' }] }, { t: 'text', text: ' ' }, { t: 'i', children: [{ t: 'text', text: 'ora' }] }],
  parseInline('Vai su [Clienti](app:customers) e premi **Nuovo** *ora*'))
const b = parseMarkdown('Uno\ndue\n\n- a\n- b\n\n1. x\n2. y\n\nFine')
eq('block types', ['p', 'ul', 'ol', 'p'], b.map((x) => x.t))
eq('soft line break joins', 'Uno due', inlineText(b[0].inline))
eq('list items', 2, b[1].items.length)
eq('html is just text', [{ t: 'text', text: '<script>x</script>' }], parseInline('<script>x</script>'))
eq('empty', [], parseMarkdown(''))
if (failures) { console.log(`${failures} failure(s)`); process.exit(1) }
console.log('all passed')
