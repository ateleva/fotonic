import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { Download, CheckCircle, XCircle, AlertTriangle, Loader, Info } from 'lucide-react'
import { __ } from '../../utils/i18n'
import { fetchBackupStatus, createBackup, fetchBackupBlob } from '../../api/backup'
import Button from '../../components/Button'

function triggerDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}

export default function BackupSettingsSection({ onSetupVault }) {
  const queryClient = useQueryClient()
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [showDetails, setShowDetails] = useState(false)

  const { data: status, isLoading } = useQuery({
    queryKey: ['backup-status'],
    queryFn: fetchBackupStatus,
  })

  const mutation = useMutation({
    // Create and immediately fetch the archive as one action from the user's
    // point of view. The token is single-use, so there is no benefit to
    // splitting "create" and "download" into two separate button presses.
    mutationFn: async () => {
      const created = await createBackup()
      const blob = await fetchBackupBlob(created.token)
      return { created, blob }
    },
    onMutate: () => {
      setError('')
      setSuccess('')
    },
    onSuccess: ({ created, blob }) => {
      triggerDownload(blob, created.filename)
      setSuccess(__('Backup created and downloaded.', 'eleva-crm-for-photographers'))
      queryClient.invalidateQueries({ queryKey: ['backup-status'] })
    },
    onError: (err) => {
      setError(err.message || __('Failed to create backup.', 'eleva-crm-for-photographers'))
    },
  })

  if (isLoading) {
    return <p className="text-sm text-gray-400 py-2">{__('Loading…', 'eleva-crm-for-photographers')}</p>
  }

  if (!status) {
    return <p className="text-sm text-red-500 py-2">{__('Could not load backup status.', 'eleva-crm-for-photographers')}</p>
  }

  // Gate 1: no vault, no archive key hierarchy, so nothing here can work yet.
  if (!status.vault_setup) {
    return (
      <div className="space-y-3">
        <p className="text-sm text-gray-500">
          {__('Encrypted backups are protected by your Vault password, so the Vault must be set up first.', 'eleva-crm-for-photographers')}
        </p>
        <button
          type="button"
          onClick={onSetupVault}
          className="inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-800"
        >
          {__('Set up your vault to enable encrypted backups', 'eleva-crm-for-photographers')} →
        </button>
      </div>
    )
  }

  // Gate 2: the server itself can't do it, nothing the user can fix here.
  if (!status.sodium_available || !status.zip_available) {
    const missingBoth = !status.sodium_available && !status.zip_available
    return (
      <div className="rounded-md bg-amber-50 border border-amber-200 p-4 flex items-start gap-2">
        <AlertTriangle size={15} className="text-amber-500 shrink-0 mt-0.5" />
        <p className="text-sm text-amber-800">
          {missingBoth
            ? __('Encrypted backups need the PHP Sodium and Zip extensions, and this server has neither. Ask your host to enable them.', 'eleva-crm-for-photographers')
            : !status.sodium_available
              ? __('Encrypted backups need the PHP Sodium extension, which this server does not have. Ask your host to enable it.', 'eleva-crm-for-photographers')
              : __('Encrypted backups need the PHP Zip extension, which this server does not have. Ask your host to enable it.', 'eleva-crm-for-photographers')}
        </p>
      </div>
    )
  }

  // Gate 3: vault is set up but never unlocked since, so the backup keypair
  // (sealed-box, provisioned on unlock) does not exist yet.
  if (!status.keys_ready) {
    return (
      <p className="text-sm text-gray-500">
        {__('Backup keys have not been generated yet. Unlock the Vault once to enable encrypted backups.', 'eleva-crm-for-photographers')}
      </p>
    )
  }

  return (
    <div className="ftnc-fields">
      {/* Reading order: what this does (the one sentence that matters), the button, the result, then the small print. */}
      <p className="text-sm text-gray-900">
        {__('Download a single encrypted archive of all your CRM data: customers, works, services, memory cards, and every attached file.', 'eleva-crm-for-photographers')}
      </p>

      <div>
        <p className="text-xs text-gray-900 flex items-center gap-1.5">
          <span>{__('This backs up your CRM data, not your whole WordPress site.', 'eleva-crm-for-photographers')}</span>
          <button
            type="button"
            onClick={() => setShowDetails((v) => !v)}
            aria-expanded={showDetails}
            aria-controls="ftnc-backup-details"
            aria-label={__('How this backup is protected', 'eleva-crm-for-photographers')}
            title={__('How this backup is protected', 'eleva-crm-for-photographers')}
            className="inline-flex shrink-0 cursor-pointer items-center rounded-full border-0 bg-transparent p-0.5 text-gray-500 hover:text-fotonic-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-fotonic-primary"
          >
            <Info size={14} aria-hidden="true" />
          </button>
        </p>
        {/* Opens and closes smoothly: the row grows from 0 to its own height. */}
        <div
          id="ftnc-backup-details"
          aria-hidden={!showDetails}
          className={`grid transition-[grid-template-rows,opacity] duration-300 ease-out ${showDetails ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`}
        >
          <div className="overflow-hidden">
            <div className="mt-3 rounded-md bg-gray-50 border border-gray-200 p-3 space-y-2">
              <p className="text-xs text-gray-900">
                {__('This archive can only be opened with your vault password or your recovery code. A strong vault password (minimum 8 characters, longer is safer) is what keeps it safe if the file is ever copied, so keep the file private. Nobody else can read it.', 'eleva-crm-for-photographers')}
              </p>
              <p className="text-xs text-gray-900">
                {__('If you lose your vault password and your recovery code and your recovery phrase, this archive can never be opened.', 'eleva-crm-for-photographers')}
              </p>
            </div>
          </div>
        </div>
      </div>

      <Button
        type="button"
        variant="primary"
        onClick={() => mutation.mutate()}
        disabled={mutation.isPending}
      >
        {mutation.isPending ? <Loader size={14} className="animate-spin" /> : <Download size={14} />}
        {mutation.isPending
          ? __('Building archive…', 'eleva-crm-for-photographers')
          : __('Download encrypted backup', 'eleva-crm-for-photographers')}
      </Button>

      {error && (
        <p className="text-xs text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2 flex items-center gap-1.5">
          <XCircle size={12} className="shrink-0" />{error}
        </p>
      )}
      {success && (
        <p className="text-xs text-green-700 bg-green-50 border border-green-200 rounded px-3 py-2 flex items-center gap-1.5">
          <CheckCircle size={12} className="shrink-0" />{success}
        </p>
      )}

      {status.pro_data_left_out > 0 && (
        <p className="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 flex items-start gap-1.5">
          <AlertTriangle size={12} className="shrink-0 mt-0.5" />
          {__('Eleva CRM Pro is switched off, so a backup made now leaves out your Pro data (quotes, suppliers, collaborators, expenses and tasks). Switch Pro on before you make a backup.', 'eleva-crm-for-photographers')}
        </p>
      )}

      {status.drift_count > 0 && !(status.pro_data_left_out > 0) && (
        <p className="text-xs text-amber-600 flex items-start gap-1.5">
          <AlertTriangle size={12} className="shrink-0 mt-0.5" />
          {__('This backup may be missing recently added fields. Contact support if a backup ever looks incomplete.', 'eleva-crm-for-photographers')}
        </p>
      )}

      {window.FotonicApp?.isLocalWp && (
        <p className="text-xs text-gray-600">
          {__('This site runs on Local by Flywheel and exists only on this computer. Local\'s own Cloud Backups are manual.', 'eleva-crm-for-photographers')}
        </p>
      )}
    </div>
  )
}
