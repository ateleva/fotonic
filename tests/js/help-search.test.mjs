// Run: node tests/js/help-search.test.mjs
import { normalize, queryTokens, searchCards, highlightParts, sanitizeQuery } from '../../src/src/features/help/helpSearch.js'

let failures = 0
const eq = (label, expected, actual) => {
  const e = JSON.stringify(expected), a = JSON.stringify(actual)
  if (e !== a) { failures++; console.log(`  FAIL ${label}\n       expected: ${e}\n       actual:   ${a}`) }
}

eq('accents and case', 'citta di roma', normalize('  Città di  ROMA! '))
eq('stopwords dropped', ['creo', 'cliente'], queryTokens('Come creo un cliente?'))
eq('only stopwords still search', ['come', 'dove'], queryTokens('come dove'))
eq('empty', [], queryTokens('  '))

const cards = [
  { id: 'customers-create', order: 10, question: 'Come creo un cliente?', answer: 'Vai su [Clienti](app:customers) e premi **Nuovo**.', keywords: ['aggiungere un cliente', 'nuova anagrafica', 'dati del cliente', 'creare cliente'] },
  { id: 'works-create', order: 20, question: 'Come registro un lavoro?', answer: 'Scegli il cliente, poi il servizio.', keywords: ['nuovo lavoro', 'servizio e cliente', 'prezzo del lavoro', 'registrare lavoro'] },
  { id: 'vault-lock', order: 30, question: 'Come blocco il Vault?', answer: 'Usa il pulsante in basso a sinistra.', keywords: ['chiudere vault', 'bloccare i dati', 'lucchetto', 'sicurezza dati'] },
]

let r = searchCards(cards, 'cliente')
eq('whole word: both cards with the word, question first', ['customers-create', 'works-create'], r.results.map((x) => x.card.id))
r = searchCards(cards, 'creare cliente')
eq('best card first', 'customers-create', r.results[0].card.id)
eq('strong match opens the top', true, r.strong)
r = searchCards(cards, 'clien')
eq('prefix "clien" matches', true, r.results.length > 0)
r = searchCards(cards, 'cli')
eq('3 letters are a prefix', true, r.results.length > 0)
r = searchCards(cards, 'cl')
eq('under 3 characters: no search at all', [0, false], [r.results.length, r.strong])
r = searchCards(cards, ' c ')
eq('spaces do not count', 0, r.results.length)
r = searchCards(cards, 'lucchetto')
eq('keyword-only match is found', 'vault-lock', r.results[0].card.id)
eq('... and says which keyword', 'lucchetto', r.results[0].via)
r = searchCards(cards, 'blocco vault')
eq('question match has no "found by"', '', r.results[0].via)
r = searchCards(cards, 'citta')
eq('nothing found is empty (and never strong)', [0, false], [r.results.length, r.strong])
r = searchCards(cards, 'nuovo')
eq('answer text is searched, link target is not', ['customers-create', 'works-create'].sort(), r.results.map((x) => x.card.id).sort())
r = searchCards(cards, 'customers')
eq('route token in a link is not searchable', 0, r.results.length)
r = searchCards(cards, 'cliente servizio')
eq('two words: card with both ranks first', 'works-create', r.results[0].card.id)

eq('highlight marks whole words, accents ignored', [{ text: 'Il ', hit: false }, { text: 'Clienti', hit: true }, { text: ' sono qui', hit: false }], highlightParts('Il Clienti sono qui', ['client']))
eq('highlight: 2-letter token is not a prefix', [{ text: 'cliente', hit: false }], highlightParts('cliente', ['cl']))
eq('highlight: no tokens', [{ text: 'abc', hit: false }], highlightParts('abc', []))

eq('sanitize: script tag and its content are dropped', 'ciao', sanitizeQuery('<script>alert(1)</script>ciao'))
eq('sanitize: html keeps only the text (a trailing space stays, the person may be typing)', 'cliente nuovo ', sanitizeQuery('<b>cliente</b> <i>nuovo</i>'))
eq('sanitize: unclosed tag', 'x ', sanitizeQuery('x<img src=x onerror=alert(1)'))
eq('sanitize: stray brackets', 'a b', sanitizeQuery('a < b >'.replace(' < ', ' ').replace(' >', '')))
eq('sanitize: control and zero-width characters', 'ab', sanitizeQuery('a\u0000\u200bb'))
eq('sanitize: plain text and accents untouched', 'città di roma', sanitizeQuery('città di roma'))
eq('sanitize: capped at 80', 80, sanitizeQuery('a'.repeat(200)).length)
eq('sanitize: non-string', '', sanitizeQuery(null))
if (failures) { console.log(`${failures} failure(s)`); process.exit(1) }
console.log('all passed')
