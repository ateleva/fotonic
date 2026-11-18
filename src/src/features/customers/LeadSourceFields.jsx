import FormField from '../../components/FormField'
import { __ } from '../../utils/i18n'
import { leadSourceOptions, takesLeadDetail } from '../../utils/leadSources'

const FIELD_CLASS =
  'rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full'

// The label of the text next to the select, for the sources that take one.
const DETAIL_LABELS = () => ({
  supplier_referral: __('Supplier', 'eleva-crm-for-photographers'),
  word_of_mouth: __('Referred by', 'eleva-crm-for-photographers'),
  other: __('Which source', 'eleva-crm-for-photographers'),
})

const DETAIL_MAX = 120

// Two fields that sit on the same row as the title: the select of where the customer came from and,
// only for word of mouth, supplier and other, a short text. The parent row never wraps.
export default function LeadSourceFields({ register, source }) {
  const sources = leadSourceOptions()
  const detailLabel = takesLeadDetail(source) ? DETAIL_LABELS()[source] : null

  return (
    <>
      <div style={{ flex: '0 1 12rem', minWidth: 0 }}>
        <FormField label={__('Source', 'eleva-crm-for-photographers')} htmlFor="lead_source">
          <select id="lead_source" className={FIELD_CLASS} {...register('lead_source')}>
            <option value="">{__('Not specified', 'eleva-crm-for-photographers')}</option>
            {sources.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </FormField>
      </div>
      {detailLabel && (
        <div style={{ flex: '0 1 14rem', minWidth: 0 }}>
          <FormField label={detailLabel} htmlFor="lead_source_detail">
            <input
              id="lead_source_detail"
              type="text"
              maxLength={DETAIL_MAX}
              placeholder={__('Optional', 'eleva-crm-for-photographers')}
              className={FIELD_CLASS}
              {...register('lead_source_detail')}
            />
          </FormField>
        </div>
      )}
    </>
  )
}
