import { useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { useService, useCreateService, useUpdateService } from '../../api/services'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'

export default function ServiceForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()
  const { data: service, isLoading } = useService(id)
  const createService = useCreateService()
  const updateService = useUpdateService()
  const { register, handleSubmit, reset, formState: { errors, isSubmitting } } = useForm({ defaultValues: { title: '', base_price: '', notes: '' } })

  useEffect(() => {
    if (service) reset({ title: service.title ?? '', base_price: service.base_price ?? '', notes: service.notes ?? '' })
  }, [service, reset])

  const mutation = isEdit ? updateService : createService

  async function onSubmit(data) {
    const payload = { ...data, base_price: data.base_price !== '' ? parseFloat(data.base_price) : null }
    try {
      if (isEdit) { await updateService.mutateAsync({ id, ...payload }) } else { await createService.mutateAsync(payload) }
      navigate('/services')
    } catch {}
  }

  if (isEdit && isLoading) return <div className="flex justify-center py-12"><Spinner /></div>

  return (
    <div className="p-6 max-w-lg">
      <PageHeader title={isEdit ? 'Edit Service' : 'New Service'} />
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-5">
        <FormField label="Title" htmlFor="title" required error={errors.title?.message}>
          <input id="title" type="text" placeholder="e.g. Wedding Photography" className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full" {...register('title', { required: 'Title is required' })} />
        </FormField>
        <FormField label="Base Price (€)" htmlFor="base_price" error={errors.base_price?.message}>
          <input id="base_price" type="number" min="0" step="0.01" placeholder="0.00" className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full" {...register('base_price', { min: { value: 0, message: 'Price must be 0 or more' } })} />
        </FormField>
        <FormField label="Notes" htmlFor="notes" error={errors.notes?.message}>
          <textarea id="notes" rows={4} placeholder="Optional notes about this service..." className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full resize-y" {...register('notes')} />
        </FormField>
        {mutation.error && <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">{mutation.error.message ?? 'An error occurred. Please try again.'}</p>}
        <div className="flex items-center gap-3 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>{mutation.isPending ? 'Saving...' : isEdit ? 'Update Service' : 'Create Service'}</Button>
          <Button type="button" variant="secondary" onClick={() => navigate('/services')}>Cancel</Button>
        </div>
      </form>
    </div>
  )
}
