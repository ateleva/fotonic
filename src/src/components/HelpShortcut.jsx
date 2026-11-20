import { Link } from 'react-router-dom'
import { CircleHelp } from 'lucide-react'
import { __ } from '../utils/i18n'

/**
 * "See the guides ?" next to a page title: opens the Help already filtered to the section of that page
 * (`#/help?section=<key>`). Quiet on purpose (white, thin gray outline, gray text); on hover or focus it takes a tint of
 * the theme colour, and the theme colour as text and outline (see `.ftnc-help-btn` in index.css). On a narrow screen
 * only the icon stays. `compact`: the smaller one, for the cards of Settings. The Pro plugin uses the same classes.
 * `label` is the tooltip: what the Help will show ("this page" by default).
 */
export default function HelpShortcut({ section, label, compact = false }) {
  const title = label ?? __('Help for this page', 'eleva-crm-for-photographers')
  return (
    <Link to={`/help?section=${encodeURIComponent(section)}`} className={`ftnc-help-btn${compact ? ' ftnc-help-btn--compact' : ''}`} title={title}>
      <span className="ftnc-help-btn__label">{__('See the guides', 'eleva-crm-for-photographers')}</span>
      <CircleHelp size={compact ? 11 : 13} aria-hidden="true" />
    </Link>
  )
}
