import { useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { useMemoryCard, useCreateMemoryCard, useUpdateMemoryCard, useDeleteMemoryCard } from '../../api/memory-cards'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import Modal from '../../components/Modal'
import { useState } from 'react'
import { __ } from '../../utils/i18n'

const STATUS_OPTIONS = [
  { value: 'free',      label: () => __('Ready', 'eleva-crm-for-photographers') },
  { value: 'in_use',    label: () => __('In Use', 'eleva-crm-for-photographers') },
  { value: 'backed_up', label: () => __('Backed Up', 'eleva-crm-for-photographers') },
  { value: 'damaged',   label: () => __('Damaged', 'eleva-crm-for-photographers') },
]

export default function MemoryCardForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)

  const { data: card, isLoading } = useMemoryCard(id)
  const createCard  = useCreateMemoryCard()
  const updateCard  = useUpdateMemoryCard()
  const deleteCard  = useDeleteMemoryCard()

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm({
    defaultValues: { title: '', status: 'free' },
  })

  useEffect(() => {
    if (card) {
      reset({ title: card.title ?? '', status: card.status ?? 'free' })
    }
  }, [card, reset])

  function handleDeleteClick() {
    if (card?.status === 'in_use') {
      setBlockReason(__('Card is currently in use and cannot be deleted.', 'eleva-crm-for-photographers'))
      return
    }
    setShowConfirm(true)
  }

  const mutation = isEdit ? updateCard : createCard

  async function onSubmit(data) {
    try {
      if (isEdit) {
        await updateCard.mutateAsync({ id, ...data })
      } else {
        await createCard.mutateAsync(data)
      }
      navigate('/memory-cards')
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
        title={isEdit ? __('Edit Card', 'eleva-crm-for-photographers') : __('New Card', 'eleva-crm-for-photographers')}
        backTo="/memory-cards"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
        <FormField
          label={__('Card Name', 'eleva-crm-for-photographers')}
          htmlFor="title"
          required
          error={errors.title?.message}
        >
          <input
            id="title"
            type="text"
            placeholder={__('e.g. SD-A 128GB SanDisk', 'eleva-crm-for-photographers')}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            {...register('title', { required: __('Card name is required', 'eleva-crm-for-photographers') })}
          />
        </FormField>

        <FormField
          label={__('Status', 'eleva-crm-for-photographers')}
          htmlFor="status"
        >
          <select
            id="status"
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            style={{ width: 'auto', minWidth: '220px' }}
            {...register('status')}
          >
            {STATUS_OPTIONS.map(({ value, label }) => (
              <option key={value} value={value}>{label()}</option>
            ))}
          </select>
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
              ? __('Update Card', 'eleva-crm-for-photographers')
              : __('Create Card', 'eleva-crm-for-photographers')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/memory-cards')}
          >
            {__('Cancel', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteCard.mutate(id, { onSuccess: () => navigate('/memory-cards') })}
      message={__('Delete this card? This action cannot be undone.', 'eleva-crm-for-photographers')}
    />
    <Modal open={blockReason !== null} onClose={() => setBlockReason(null)} title={__('Cannot Delete', 'eleva-crm-for-photographers')}>
      <p className="text-sm text-gray-600 mb-6">{blockReason}</p>
      <div className="flex justify-end">
        <Button onClick={() => setBlockReason(null)}>{__('OK', 'eleva-crm-for-photographers')}</Button>
      </div>
    </Modal>
    </>
  )
}
