import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { useService, useCreateService, useUpdateService, useDeleteService } from '../../api/services'
import { apiFetch } from '../../api/client'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import Modal from '../../components/Modal'
import BlockedReason from '../../components/BlockedReason'
import { __ } from '../../utils/i18n'

export default function ServiceForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)
  const [blockGroups, setBlockGroups] = useState(null)
  const [checkingDelete, setCheckingDelete] = useState(false)

  const { data: service, isLoading } = useService(id)
  const createService = useCreateService()
  const updateService = useUpdateService()
  const deleteService = useDeleteService()

  async function handleDeleteClick() {
    if (checkingDelete) return
    setCheckingDelete(true)
    try {
      const res = await apiFetch(`services/${id}/can-delete`)
      if (res.can_delete) {
        setShowConfirm(true)
      } else {
        setBlockGroups(res.groups ?? null)
        setBlockReason(res.reason)
      }
    } catch {
      setBlockReason(__('Unable to check references. Please try again.', 'eleva-crm-for-photographers'))
    } finally {
      setCheckingDelete(false)
    }
  }

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({
    defaultValues: { title: '', base_price: '', notes: '' },
  })

  useEffect(() => {
    if (service) {
      reset({
        title: service.title ?? '',
        base_price: service.base_price ?? '',
        notes: service.notes ?? '',
      })
    }
  }, [service, reset])

  const mutation = isEdit ? updateService : createService

  async function onSubmit(data) {
    const payload = {
      ...data,
      base_price: data.base_price !== '' ? parseFloat(data.base_price) : null,
    }
    try {
      if (isEdit) {
        await updateService.mutateAsync({ id, ...payload })
      } else {
        await createService.mutateAsync(payload)
      }
      navigate('/services')
    } catch {
      // Error displayed below
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
        title={isEdit ? __('Edit Service', 'eleva-crm-for-photographers') : __('New Service', 'eleva-crm-for-photographers')}
        backTo="/services"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      <form onSubmit={handleSubmit(onSubmit)} className="ftnc-fields">
        {/* One row: title and base price; notes full width below. */}
        <div className="flex flex-wrap items-start gap-x-6 gap-y-4">
          <div className="flex-1 min-w-[16rem]">
            <FormField
              label={__('Title', 'eleva-crm-for-photographers')}
              htmlFor="title"
              required
              error={errors.title?.message}
            >
              <input
                id="title"
                type="text"
                placeholder={__('e.g. Wedding Photography', 'eleva-crm-for-photographers')}
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                {...register('title', { required: __('Title is required', 'eleva-crm-for-photographers') })}
              />
            </FormField>
          </div>

          <div className="w-44">
            <FormField
              label={__('Base Price (€)', 'eleva-crm-for-photographers')}
              htmlFor="base_price"
              error={errors.base_price?.message}
            >
              <input
                id="base_price"
                type="number"
                min="0"
                step="any"
                placeholder="0.00"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                {...register('base_price', {
                  min: { value: 0, message: __('Price must be 0 or more', 'eleva-crm-for-photographers') },
                })}
              />
            </FormField>
          </div>
        </div>

        <FormField label={__('Notes', 'eleva-crm-for-photographers')} htmlFor="notes" error={errors.notes?.message}>
          <textarea
            id="notes"
            rows={4}
            placeholder={__('Optional notes about this service...', 'eleva-crm-for-photographers')}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full resize-y"
            {...register('notes')}
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
              ? __('Update Service', 'eleva-crm-for-photographers')
              : __('Create Service', 'eleva-crm-for-photographers')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/services')}
          >
            {__('Cancel', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteService.mutate(id, { onSuccess: () => navigate('/services') })}
      message={__('Delete this service? This action cannot be undone.', 'eleva-crm-for-photographers')}
    />
    <Modal open={blockReason !== null} onClose={() => { setBlockReason(null); setBlockGroups(null) }} title={__('Cannot Delete', 'eleva-crm-for-photographers')}>
      <BlockedReason reason={blockReason} groups={blockGroups} onGo={(route) => { setBlockReason(null); navigate(route) }} />
      <div className="flex justify-end">
        <Button onClick={() => { setBlockReason(null); setBlockGroups(null) }}>{__('OK', 'eleva-crm-for-photographers')}</Button>
      </div>
    </Modal>
    </>
  )
}
