// Pure decisions behind WpEditor (no React, no window). Same logic as Eleva CRM Pro's editorSync.js (a separate bundle, so a separate copy).

// How many recent texts of the editor are remembered (see shouldPushValue).
export const KNOWN_HISTORY = 64

/**
 * Should the `value` prop be written into the TinyMCE editor with setContent()?
 *
 * setContent() replaces the whole document and leaves the caret at the very start of the field,
 * so it must never run on text the admin is typing. The answer is no when:
 *  - the editor has the focus (the admin is working in it right now);
 *  - the editor already holds that HTML;
 *  - `value` is only an echo of a text the editor already knows: the one it was given at start-up,
 *    one it was given later, or one it reported through onChange. The render that carries a value
 *    can be committed (or its effects run) after the editor moved on by a few characters: the
 *    value is then older than the text in the editor, and pushing it back would revert those
 *    characters and reset the caret. Not just the latest text counts: with several keystrokes in
 *    flight the echo that arrives can be any of the recent ones, so the editor keeps a short
 *    history (see pushKnown / ackKnown).
 * Anything else is a real change made from outside (hydration, a reset): push it.
 *
 * @param {object}   args
 * @param {*}        args.value    The `value` prop.
 * @param {string}   args.current  What the editor holds now (editor.getContent()).
 * @param {string[]} args.known    Recent texts the editor was given or reported, oldest first.
 * @param {boolean}  args.focused  The editor has the focus.
 * @returns {boolean}
 */
export function shouldPushValue({ value, current, known, focused }) {
  const next = typeof value === 'string' ? value : ''
  if (focused) return false
  if (next === current) return false
  if (Array.isArray(known) && known.includes(next)) return false
  return true
}

/** The history after the editor is given or reports `html`: appended (not twice in a row) and capped. */
export function pushKnown(known, html, max = KNOWN_HISTORY) {
  const list = Array.isArray(known) ? known : []
  if (list.length > 0 && list[list.length - 1] === html) return list
  const next = [...list, html]
  return next.length > max ? next.slice(next.length - max) : next
}

/**
 * The history once `value` was committed: when it is the editor's latest text, every older echo
 * has been delivered (renders are committed in order), so only that one is kept.
 */
export function ackKnown(known, value) {
  const list = Array.isArray(known) ? known : []
  if (list.length > 1 && list[list.length - 1] === value) return [value]
  return list
}

/** The last text the editor reported or received: what the parent was last told (undefined when none). */
export function lastKnown(known) {
  return Array.isArray(known) && known.length > 0 ? known[known.length - 1] : undefined
}

/**
 * Should the editor's `html` be reported through onChange?
 *
 * Compared with the last text the editor reported or received, NOT with the `value` prop: that one
 * lags behind (the render carrying a report is committed later). Type "c" then Backspace before the
 * parent committed "c": the editor is back to the older text, but the parent was already told "c",
 * so the older text must be reported again or the two stay different until the next event.
 *
 * @param {object}   args
 * @param {string}   args.html   What the editor holds now (editor.getContent()).
 * @param {string[]} args.known  Recent texts the editor was given or reported, oldest first.
 * @returns {boolean}
 */
export function shouldReport({ html, known }) {
  return html !== lastKnown(known)
}

/**
 * The history once the parent handed the editor `value` and the editor already holds exactly that
 * (`current`): the parent and the editor agree, so it is the latest known text. Any other value
 * leaves the history as it is (it is either an echo of an older text or a push, see shouldPushValue).
 */
export function receiveKnown(known, value, current) {
  const list = Array.isArray(known) ? known : []
  return value === current ? pushKnown(list, value) : list
}
