import { useEffect, useState } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import {
  useBackupLocation, useCreateBackupLocation, useUpdateBackupLocation, useDeleteBackupLocation,
} from '../../api/backup-locations'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import { formatDate } from '../../utils/date'
import { __ } from '../../utils/i18n'
import { titleOr } from '../../utils/titles'

const STATUS_OPTIONS = [
  { value: 'active',  label: () => __('Active', 'eleva-crm-for-photographers') },
  { value: 'full',    label: () => __('Full', 'eleva-crm-for-photographers') },
  { value: 'retired', label: () => __('Retired', 'eleva-crm-for-photographers') },
]

const INPUT = 'rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full'

function LocationWorks({ works }) {
  return (
    <div style={{ marginTop: 32 }}>
      <h3 className="mb-3 text-base font-bold text-gray-900">{__('Works', 'eleva-crm-for-photographers')}</h3>
      <div className="ftnc-table-scroll text-sm">
        <table className="w-full border-collapse">
          <thead>
            <tr className="bg-gray-50 border-b border-gray-200">
              {[__('Title', 'eleva-crm-for-photographers'), __('Date', 'eleva-crm-for-photographers'), __('Customer', 'eleva-crm-for-photographers')].map((h) => (
                <th key={h} className="px-3.5 py-2.5 text-left font-semibold text-gray-700 whitespace-nowrap">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {works.length === 0 ? (
              <tr>
                <td colSpan={3} className="p-8 text-center text-gray-400">
                  {__('No works saved in this location yet.', 'eleva-crm-for-photographers')}
                </td>
              </tr>
            ) : (
              works.map((w) => (
                <tr key={w.id} className="border-b border-gray-100">
                  <td className="px-3.5 py-2.5 font-medium">
                    <Link to={`/works/${w.id}`} className="text-fotonic-primary underline hover:opacity-80">
                      {titleOr(w.title, 'work', w.id)}
                    </Link>
                  </td>
                  <td className="px-3.5 py-2.5 text-gray-500 whitespace-nowrap">{formatDate(w.event_date)}</td>
                  <td className="px-3.5 py-2.5 text-gray-700">{w.customer_title === null ? titleOr(null, 'customer', w.customer_id) : w.customer_title || '-'}</td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}

export default function BackupLocationForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()
  const [showConfirm, setShowConfirm] = useState(false)

  const { data: location, isLoading } = useBackupLocation(id)
  const createLocation = useCreateBackupLocation()
  const updateLocation = useUpdateBackupLocation()
  const deleteLocation = useDeleteBackupLocation()

  const { register, handleSubmit, reset, control, formState: { errors, isSubmitting } } = useForm({
    defaultValues: { title: '', type: '', capacity: '', status: 'active', notes: '' },
  })

  useEffect(() => {
    if (location) {
      reset({
        title: location.title ?? '',
        type: location.type ?? '',
        capacity: location.capacity ?? '',
        status: location.status ?? 'active',
        // null = the Vault cannot read the stored note: it is sent back as null so it is not overwritten.
        notes: location.notes ?? null,
      })
    }
  }, [location, reset])

  const mutation = isEdit ? updateLocation : createLocation

  async function onSubmit(data) {
    try {
      if (isEdit) {
        await updateLocation.mutateAsync({ id, ...data })
      } else {
        await createLocation.mutateAsync(data)
      }
      navigate('/storage/backups')
    } catch {
      // Error shown below
    }
  }

  if (isEdit && isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  return (
    <>
      <div className="p-6">
        <PageHeader
          title={isEdit ? __('Edit Backup Location', 'eleva-crm-for-photographers') : __('New Backup Location', 'eleva-crm-for-photographers')}
          backTo="/storage/backups"
          onDelete={isEdit ? () => setShowConfirm(true) : undefined}
        />

        <form onSubmit={handleSubmit(onSubmit)} className="ftnc-fields">
          <FormField label={__('Name', 'eleva-crm-for-photographers')} htmlFor="title" required error={errors.title?.message}>
            <input
              id="title"
              type="text"
              placeholder={__('e.g. Synology NAS, office', 'eleva-crm-for-photographers')}
              className={INPUT}
              {...register('title', { required: __('Name is required', 'eleva-crm-for-photographers') })}
            />
          </FormField>

          <div className="ftnc-grid-3">
            <FormField label={__('Type', 'eleva-crm-for-photographers')} htmlFor="type">
              <input
                id="type"
                type="text"
                placeholder={__('e.g. NAS, SSD, Google Drive', 'eleva-crm-for-photographers')}
                className={INPUT}
                {...register('type')}
              />
            </FormField>
            <FormField label={__('Capacity', 'eleva-crm-for-photographers')} htmlFor="capacity">
              <input
                id="capacity"
                type="text"
                placeholder={__('e.g. 4 TB', 'eleva-crm-for-photographers')}
                className={INPUT}
                {...register('capacity')}
              />
            </FormField>
            <FormField label={__('Status', 'eleva-crm-for-photographers')} htmlFor="status">
              <select id="status" className={`${INPUT} py-1.5`} {...register('status')}>
                {STATUS_OPTIONS.map(({ value, label }) => (
                  <option key={value} value={value}>{label()}</option>
                ))}
              </select>
            </FormField>
          </div>

          <FormField label={__('Notes', 'eleva-crm-for-photographers')} htmlFor="notes">
            <Controller
              name="notes"
              control={control}
              render={({ field }) => (
                <textarea
                  id="notes"
                  rows={3}
                  value={field.value ?? ''}
                  onChange={(e) => field.onChange(e.target.value)}
                  onBlur={field.onBlur}
                  className={INPUT}
                />
              )}
            />
          </FormField>

          {mutation.error && (
            <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
              {mutation.error.message ?? __('An error occurred. Please try again.', 'eleva-crm-for-photographers')}
            </p>
          )}

          <div className="flex items-center gap-3 pt-2">
            <Button type="submit" disabled={isSubmitting || mutation.isPending}>
              {mutation.isPending
                ? __('Saving...', 'eleva-crm-for-photographers')
                : isEdit
                ? __('Update Backup Location', 'eleva-crm-for-photographers')
                : __('Create Backup Location', 'eleva-crm-for-photographers')}
            </Button>
            <Button type="button" variant="secondary" onClick={() => navigate('/storage/backups')}>
              {__('Cancel', 'eleva-crm-for-photographers')}
            </Button>
          </div>
        </form>

        {isEdit && <LocationWorks works={location?.works ?? []} />}
      </div>

      <ConfirmDialog
        open={showConfirm}
        onClose={() => setShowConfirm(false)}
        onConfirm={() => deleteLocation.mutate(id, { onSuccess: () => navigate('/storage/backups') })}
        message={__('Delete this backup location? The works that use it will show a warning, so you can choose another location for them.', 'eleva-crm-for-photographers')}
      />
    </>
  )
}
