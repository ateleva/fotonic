import { useEffect, useRef } from 'react'
import { __, sprintf } from '../utils/i18n'

/**
 * Shell and building blocks for the screens shown before the app (Vault choice, setup, unlock,
 * recovery kit, encryption of existing data).
 *
 * The SPA page switches the WordPress page scroll off (`#wpwrap { overflow: hidden }`) and Layout
 * sizes and scrolls its own shell. A bare `min-h-screen` block has no scroller, so a card taller than
 * the window was cut at the bottom and its buttons were out of reach. GateScreen takes the space left
 * under the WP admin bar, like Layout does; GateCard then keeps its header and its action bar in
 * place and scrolls only the body between them, so the buttons are always on screen.
 *
 * Colours: only the WP admin theme colour (`--fotonic-primary`), its tints (`.ftnc-tint*` in
 * index.css) and the app grays. No icon is an emoji: lucide icons, as everywhere else in the app.
 */
export default function GateScreen({ children }) {
  const ref = useRef(null)

  useEffect(() => {
    function fit() {
      const el = ref.current
      if (!el) return
      const top = el.getBoundingClientRect().top + window.scrollY
      el.style.height = `${Math.max(window.innerHeight - top, 240)}px`
    }
    fit()
    window.addEventListener('resize', fit)
    return () => window.removeEventListener('resize', fit)
  }, [])

  // `box-border` is explicit: Tailwind's preflight is off in this app, so padding would add to `h-full`.
  // First paint uses 100vh minus the 32px admin bar; the effect then measures the real offset.
  return (
    <div ref={ref} className="bg-gray-50" style={{ height: 'calc(100vh - 32px)', minHeight: '240px' }}>
      <div className="box-border h-full flex items-center justify-center p-4 sm:p-6">
        {children}
      </div>
    </div>
  )
}

const WIDTHS = {
  sm: 'max-w-md',
  md: 'max-w-xl',
  lg: 'max-w-3xl',
  xl: 'max-w-5xl',
  full: 'max-w-none',
}

/** Thin progress bar with the "Step x of y" label, in the theme colour. */
export function GateSteps({ current, total }) {
  return (
    <div className="mx-auto mt-4 max-w-xs">
      <div className="flex gap-1.5">
        {Array.from({ length: total }, (_, i) => (
          <span
            key={i}
            className={['h-1.5 flex-1 rounded-full', i < current ? 'bg-fotonic-primary' : 'bg-gray-200'].join(' ')}
          />
        ))}
      </div>
      <p className="mt-1.5 text-xs text-gray-500">
        {sprintf(__('Step %1$d of %2$d', 'eleva-crm-for-photographers'), current, total)}
      </p>
    </div>
  )
}

/**
 * White card: header (icon, title, subtitle, steps), scrolling body, pinned action bar.
 * `footer` holds the buttons; pass a submit Button with `form="<form id>"` to drive a form in the body.
 */
export function GateCard({ icon: Icon, title, subtitle, steps, size = 'md', footer, children }) {
  const hasHeader = Boolean(Icon || title)
  return (
    <div className={['box-border w-full max-h-full flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm', WIDTHS[size] ?? WIDTHS.md].join(' ')}>
      {hasHeader && (
        <header className="shrink-0 px-6 pt-5 pb-3 text-center sm:px-8">
          {Icon && (
            <div className="ftnc-tint ftnc-tint-border mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl border text-fotonic-primary">
              <Icon size={22} aria-hidden="true" />
            </div>
          )}
          {title && <h1 className="text-xl font-semibold text-gray-900">{title}</h1>}
          {subtitle && (
            // A list becomes one paragraph per item, so a long explanation reads one idea at a time.
            <div className={['mx-auto mt-2 space-y-2 text-sm leading-relaxed text-gray-600', size === 'full' ? '' : 'max-w-2xl'].join(' ')}>
              {(Array.isArray(subtitle) ? subtitle : [subtitle]).map((t, i) => <p key={i}>{t}</p>)}
            </div>
          )}
          {steps && <GateSteps current={steps.current} total={steps.total} />}
        </header>
      )}
      <div className={['min-h-0 flex-1 overflow-y-auto px-6 pb-5 sm:px-8', hasHeader ? '' : 'pt-7'].join(' ')}>
        {children}
      </div>
      {footer && (
        <footer className="shrink-0 flex flex-col-reverse gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-8">
          {footer}
        </footer>
      )}
    </div>
  )
}

/** Titled info box: icon and title on top, the text under them. `accent` tints it with the theme colour. */
export function GateNote({ icon: Icon, title, accent = false, children }) {
  return (
    <section className={['rounded-lg border px-4 py-3', accent ? 'ftnc-tint ftnc-tint-border' : 'border-gray-200 bg-gray-50'].join(' ')}>
      <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
        {Icon && <Icon size={16} aria-hidden="true" className={accent ? 'text-fotonic-primary' : 'text-gray-500'} />}
        {title}
      </h2>
      <div className="mt-1.5 text-sm leading-snug text-gray-600">{children}</div>
    </section>
  )
}

/** One-paragraph callout with an icon: theme tint by default, red for a failure. */
export function GateCallout({ icon: Icon, error = false, children }) {
  return (
    <div className={['flex items-start gap-3 rounded-lg border px-4 py-3', error ? 'border-red-200 bg-red-50' : 'ftnc-tint ftnc-tint-border'].join(' ')}>
      {Icon && <Icon size={18} aria-hidden="true" className={['mt-0.5 shrink-0', error ? 'text-red-500' : 'text-fotonic-primary'].join(' ')} />}
      <p className={['text-sm', error ? 'text-red-700' : 'text-gray-700'].join(' ')}>{children}</p>
    </div>
  )
}
