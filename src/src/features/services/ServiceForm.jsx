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
import { __ } from '../../utils/i18n'

export default function ServiceForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)
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
        setBlockReason(res.reason)
      }
    } catch {
      setBlockReason(__('Unable to check references. Please try again.'))
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
    <div className="p-6 max-w-2xl">
      <PageHeader
        title={isEdit ? __('Edit Service') : __('New Service')}
        backTo="/services"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
        <FormField
          label={__('Title')}
          htmlFor="title"
          required
          error={errors.title?.message}
        >
          <input
            id="title"
            type="text"
            placeholder={__('e.g. Wedding Photography')}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            {...register('title', { required: __('Title is required') })}
          />
        </FormField>

        <FormField
          label={__('Base Price (€)')}
          htmlFor="base_price"
          error={errors.base_price?.message}
        >
          <input
            id="base_price"
            type="number"
            min="0"
            step="0.01"
            placeholder="0.00"
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            {...register('base_price', {
              min: { value: 0, message: __('Price must be 0 or more') },
            })}
          />
        </FormField>

        <FormField label={__('Notes')} htmlFor="notes" error={errors.notes?.message}>
          <textarea
            id="notes"
            rows={4}
            placeholder={__('Optional notes about this service...')}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full resize-y"
            {...register('notes')}
          />
        </FormField>

        {mutation.error && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
            {mutation.error.message ?? __('An error occurred. Please try again.')}
          </p>
        )}

        <div className="flex items-center gap-3 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>
            {mutation.isPending
              ? __('Saving...')
              : isEdit
              ? __('Update Service')
              : __('Create Service')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/services')}
          >
            {__('Cancel')}
          </Button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteService.mutate(id, { onSuccess: () => navigate('/services') })}
      message={__('Delete this service? This action cannot be undone.')}
    />
    <Modal open={blockReason !== null} onClose={() => setBlockReason(null)} title={__('Cannot Delete')}>
      <p className="text-sm text-gray-600 mb-6">{blockReason}</p>
      <div className="flex justify-end">
        <Button onClick={() => setBlockReason(null)}>{__('OK')}</Button>
      </div>
    </Modal>
    </>
  )
}
