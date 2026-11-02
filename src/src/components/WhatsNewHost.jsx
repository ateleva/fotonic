import { useEffect, useState } from 'react'
import { useWhatsNew } from '../api/whatsNew'
import { WhatsNewSectionModal, WhatsNewOverview } from './WhatsNew'
import { groupBySection, releasePageLockSoon } from '../utils/whatsNew'

// The windows of the "What's new" feature: the overview shown once after an update, and the window of one
// section (opened from a star in the sidebar, which sets `openSection`). Kept apart from the Layout so it can be
// tried on its own.
//
// Nothing is forced on the admin: the overview has no links, closing it (the X, Escape, a click outside) only
// ends it, and every section keeps its star and its banner until its own changes have been seen.
export default function WhatsNewHost({ openSection, setOpenSection }) {
  const { data } = useWhatsNew()
  const [overviewClosed, setOverviewClosed] = useState(false)

  const bySection = groupBySection(data?.entries ?? [])
  const showOverview = Boolean(data?.overview?.show) && !overviewClosed && !openSection
  const idle = !showOverview && !openSection

  // Whenever no window of this feature is open the page must be clickable.
  useEffect(() => {
    if (idle) releasePageLockSoon()
  }, [idle])

  return (
    <>
      {showOverview && (
        <WhatsNewOverview
          entries={data.entries}
          version={data.overview.version}
          onClose={() => setOverviewClosed(true)}
        />
      )}
      {openSection && bySection[openSection] && (
        <WhatsNewSectionModal section={openSection} entries={bySection[openSection]} onClose={() => setOpenSection(null)} />
      )}
    </>
  )
}
