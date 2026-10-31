import { useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { resumeEncrypt } from '../../api/vault'
import { mergeEncrypt } from '../../utils/vaultFlow'
import FloatingProgress from '../../components/FloatingProgress'
import { __ } from '../../utils/i18n'

/**
 * The floating bar over the app while the encryption is paused. Reading works, saving is refused by the
 * server until the job finishes. Resume brings the full screen back, and this tab drives the job again.
 */
export default function EncryptPausedBar({ encrypt }) {
  const queryClient = useQueryClient()
  const [working, setWorking] = useState(false)

  async function handleResume() {
    setWorking(true)
    try {
      const res = await resumeEncrypt()
      queryClient.setQueryData(['vault-status'], (old) => mergeEncrypt(old, res))
    } catch {
      // Another tab may have resumed it already: read the real state.
      queryClient.invalidateQueries({ queryKey: ['vault-status'] })
    } finally {
      setWorking(false)
    }
  }

  return (
    <FloatingProgress
      title={__('Encryption paused', 'eleva-crm-for-photographers')}
      detail={__('You can read your data. Saving is disabled until the encryption has finished.', 'eleva-crm-for-photographers')}
      percent={encrypt?.percent ?? 0}
      actionLabel={__('Resume', 'eleva-crm-for-photographers')}
      onAction={handleResume}
      busy={working}
    />
  )
}
