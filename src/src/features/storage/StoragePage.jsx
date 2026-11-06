import { NavLink, Navigate, useNavigate, useParams } from 'react-router-dom'
import { PlusCircle, MemoryStick, HardDrive, Aperture } from 'lucide-react'
import PageHeader from '../../components/PageHeader'
import Button from '../../components/Button'
import MemoryCardList from '../memory-cards/MemoryCardList'
import BackupLocationList from './BackupLocationList'
import ShotsHistory from './ShotsHistory'
import { __ } from '../../utils/i18n'

// The tab is part of the address (#/storage/cards, #/storage/backups, #/storage/shots), so a tab can be
// bookmarked and the Back button of a detail page lands on the same tab.
const TABS = [
  { key: 'cards',   label: () => __('Memory Cards', 'eleva-crm-for-photographers'),     Icon: MemoryStick, Panel: MemoryCardList },
  { key: 'backups', label: () => __('Backup Locations', 'eleva-crm-for-photographers'), Icon: HardDrive,  Panel: BackupLocationList },
  { key: 'shots',   label: () => __('Shots History', 'eleva-crm-for-photographers'),    Icon: Aperture,   Panel: ShotsHistory },
]

export default function StoragePage() {
  const { tab } = useParams()
  const navigate = useNavigate()
  const current = TABS.find((t) => t.key === tab)

  if (!current) return <Navigate to="/storage/cards" replace />

  const { Panel } = current

  const addButton = {
    cards: (
      <Button onClick={() => navigate('/storage/cards/new')}>
        <PlusCircle size={15} />
        {__('Add Card', 'eleva-crm-for-photographers')}
      </Button>
    ),
    backups: (
      <Button onClick={() => navigate('/storage/backups/new')}>
        <PlusCircle size={15} />
        {__('Add Backup Location', 'eleva-crm-for-photographers')}
      </Button>
    ),
  }[current.key]

  return (
    <div className="p-6">
      <PageHeader title={__('Storage', 'eleva-crm-for-photographers')} action={addButton} separator={false} trashType={{ cards: 'ftnc_memory_card', backups: 'ftnc_backup_loc' }[current.key]} />

      {/* Same buttons as the rest of the app: the chosen one is the primary button, the others the secondary. */}
      <nav aria-label={__('Storage sections', 'eleva-crm-for-photographers')} className="flex flex-wrap gap-2 mb-4">
        {TABS.map(({ key, label, Icon }) => (
          <NavLink
            key={key}
            to={`/storage/${key}`}
            className={({ isActive }) => [
              'inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium cursor-pointer transition-colors no-underline',
              isActive
                ? 'bg-fotonic-primary text-white hover:opacity-90'
                : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-200 hover:bg-gray-50',
            ].join(' ')}
          >
            <Icon size={15} />
            {label()}
          </NavLink>
        ))}
      </nav>

      {/* Full-width rule: the three tabs above are the page, the filters and the list below are the content. */}
      <div role="separator" className="h-px w-full bg-gray-200 mb-4" />

      <Panel />
    </div>
  )
}
