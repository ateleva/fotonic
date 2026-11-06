import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArchiveRestore, FileArchive } from 'lucide-react'
import { fetchRestoreBackups } from '../../api/restore'
import Button from '../../components/Button'
import { __ } from '../../utils/i18n'
import { useRestoreJob } from './restoreJob'
import { isFinishedJob, formatBytes, formatWhen } from './restoreUtils'

const SHOWN = 3

/**
 * The restore half of the Backups card in Settings. It sits outside the "backup is available" checks of the backup
 * half on purpose: restoring also works on a site with no Vault, and the server says if something is missing.
 * The big button opens the restore with every way of choosing a backup (a file, one saved here, Google Drive); a
 * button on a saved backup opens it with that backup already chosen.
 */
export default function RestoreLauncher() {
  const navigate = useNavigate()
  const { data: job } = useRestoreJob()
  const active = job && !isFinishedJob(job)
  const { data: saved } = useQuery({ queryKey: ['restore-backups'], queryFn: fetchRestoreBackups, staleTime: 0 })
  const backups = (saved?.backups ?? []).slice(0, SHOWN)

  return (
    <div className="ftnc-fields">
      <h3 className="text-sm font-semibold text-gray-800">{__('Restore from a backup', 'eleva-crm-for-photographers')}</h3>
      <p className="text-sm text-gray-500">
        {__('Put the data of a backup back into Eleva CRM: after a move to a new site, or to bring back something you lost. You choose what happens to anything that is already here, and nothing changes until you confirm.', 'eleva-crm-for-photographers')}
      </p>
      {active && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">
          {__('A restore is in progress or waiting for you to finish it.', 'eleva-crm-for-photographers')}
        </p>
      )}
      <Button variant={active ? 'primary' : 'secondary'} onClick={() => navigate('/restore')}>
        <ArchiveRestore size={14} />
        {active ? __('Open the restore', 'eleva-crm-for-photographers') : __('Restore from a backup', 'eleva-crm-for-photographers')}
      </Button>

      {!active && backups.length > 0 && (
        <div className="space-y-1.5">
          <p className="text-xs font-medium text-gray-600">{__('Backups saved on this site', 'eleva-crm-for-photographers')}</p>
          <ul className="divide-y divide-gray-100 rounded-md border border-gray-200">
            {backups.map((b) => (
              <li key={b.file} className="flex items-center gap-3 px-3 py-2">
                <FileArchive size={14} className="shrink-0 text-gray-400" aria-hidden="true" />
                <div className="min-w-0 flex-1">
                  <p className="text-xs text-gray-800">{formatWhen(b.modified)}</p>
                  <p className="text-xs text-gray-400">{formatBytes(b.size)}</p>
                </div>
                <Button size="sm" variant="secondary" onClick={() => navigate('/restore?backup=' + encodeURIComponent(b.file))}>
                  {__('Restore this backup', 'eleva-crm-for-photographers')}
                </Button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
