// The message copied for the client (utils/deliveryMessage.js). Run: node tests/js/delivery-message-check.mjs
globalThis.window = { FotonicApp: { locale: 'it_IT', dateFormat: 'd/m/Y' } }
const { buildDeliveryMessage, mainContactName } = await import('../../src/src/utils/deliveryMessage.js')
let fail = 0
const eq = (a, b, label) => { const ok = JSON.stringify(a) === JSON.stringify(b); if (!ok) { fail++; console.log('FAIL', label, JSON.stringify(a), '!=', JSON.stringify(b)) } else console.log('  ok  ', label) }

const full = buildDeliveryMessage({ name: 'Anna', url: 'gallery.example.com/x', password: 'abc123', expires: '2026-06-30' })
eq(full, 'Hi Anna, you can find the gallery at this link: https://gallery.example.com/x\n\nTo unlock the gallery use this password: abc123\n\nThe link expires on 30/06/2026.', 'link, password and expiry: three paragraphs, link gets https://')
eq(buildDeliveryMessage({ name: 'Anna', url: 'https://a.it/g', password: '', expires: '' }), 'Hi Anna, you can find the gallery at this link: https://a.it/g', 'no password and no expiry: only the link line')
eq(buildDeliveryMessage({ name: 'Anna', url: 'https://a.it/g', password: '   ', expires: '2026-06-30' }).includes('password'), false, 'a blank password is left out')
eq(buildDeliveryMessage({ name: 'Anna', url: 'https://a.it/g', password: 'pw', expires: '' }).includes('expires'), false, 'no expiry date: no expiry line')
eq(buildDeliveryMessage({ name: 'Anna', url: 'https://a.it/g', password: '', expires: '2026-06-30' }).includes('password'), false, 'expiry without password: no password line')
eq(buildDeliveryMessage({ name: '', url: 'https://a.it/g' }), 'Hi, you can find the gallery at this link: https://a.it/g', 'no contact name: a plain greeting')
eq(buildDeliveryMessage({ name: 'Anna', url: '' }), '', 'no link: nothing to copy (a USB stick)')
eq(buildDeliveryMessage({ name: 'Anna', url: 'apple' }), '', 'a link that is not a web address: nothing to copy')

eq(mainContactName({ people: [{ first_name: 'Luca' }, { first_name: 'Anna', is_main: true }] }), 'Anna', 'the main contact is chosen')
eq(mainContactName({ people: [{ first_name: 'Luca' }] }), 'Luca', 'no main contact: the first person')
eq(mainContactName({ people: [{ first_name: null, is_main: true }] }), '', 'an unreadable name gives no name')
eq(mainContactName(undefined), '', 'no customer yet')
console.log(fail ? `${fail} failure(s)` : 'all passed')
process.exit(fail ? 1 : 0)
