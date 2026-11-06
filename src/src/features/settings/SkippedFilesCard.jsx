import { useQuery } from '@tanstack/react-query'
import { FileWarning } from 'lucide-react'
import { fetchSkippedWorkFiles } from '../../api/files'
import { __ } from '../../utils/i18n'

function reasonText(why) {
  switch (why) {
    case 'kept_shared':
      return __('Also used somewhere else on your site (for example a quote logo or a page image), so it was left where it is.', 'eleva-crm-for-photographers')
    case 'too_big':
      return __('Too big to be protected.', 'eleva-crm-for-photographers')
    case 'missing':
      return __('The file is no longer on the server.', 'eleva-crm-for-photographers')
    case 'damaged':
      return __('The file could not be copied safely, so it was left where it is.', 'eleva-crm-for-photographers')
    default:
      return __('Not a PDF, a Word document or an image.', 'eleva-crm-for-photographers')
  }
}

/**
 * The documents the Vault could not move to the private folder when it protected your existing data.
 * Shown only while there are some; they stay in the WordPress media library, open to anyone who knows their address.
 */
export default function SkippedFilesCard() {
  const { data } = useQuery({ queryKey: ['work-files-skipped'], queryFn: fetchSkippedWorkFiles })
  if (!data || data.length === 0) return null

  return (
    <div className="rounded-md border border-amber-200 bg-amber-50 p-4 space-y-2">
      <p className="text-sm font-medium text-amber-900 inline-flex items-center gap-2">
        <FileWarning size={16} />
        {__('Documents left in the media library', 'eleva-crm-for-photographers')}
      </p>
      <p className="ftnc-prose text-sm text-amber-800">
        {__('These documents are attached to your works, but they were not moved to the private folder. They are still in the WordPress media library, where anyone who knows their web address can open them.', 'eleva-crm-for-photographers')}
      </p>
      <ul className="text-sm text-amber-900 space-y-1 list-disc list-inside">
        {data.map((file) => (
          <li key={file.id}>
            <span className="font-medium">{file.filename}</span>
            {': '}
            {reasonText(file.why)}
          </li>
        ))}
      </ul>
    </div>
  )
}
