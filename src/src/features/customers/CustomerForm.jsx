import { useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { useCustomer, useCreateCustomer, useUpdateCustomer } from '../../api/customers'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import PeopleRepeater from './PeopleRepeater'

export default function CustomerForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const { data: customer, isLoading } = useCustomer(id)
  const createCustomer = useCreateCustomer()
  const updateCustomer = useUpdateCustomer()

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm({
    defaultValues: {
      title: '',
      people: [
        {
          first_name: '',
          last_name: '',
          email: '',
          phone: '',
          nationality: '',
          is_main: true,
        },
      ],
    },
  })

  useEffect(() => {
    if (customer) {
      reset({
        title: customer.title ?? '',
        people:
          customer.people?.length > 0
            ? customer.people
            : [
                {
                  first_name: '',
                  last_name: '',
                  email: '',
                  phone: '',
                  nationality: '',
                  is_main: true,
                },
              ],
      })
    }
  }, [customer, reset])

  const mutation = isEdit ? updateCustomer : createCustomer
  const mutationError = mutation.error

  async function onSubmit(data) {
    try {
      if (isEdit) {
        await updateCustomer.mutateAsync({ id, ...data })
      } else {
        await createCustomer.mutateAsync(data)
      }
      navigate('/customers')
    } catch {
      // Error displayed from mutation.error
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
    <div className="p-6 max-w-2xl">
      <PageHeader title={isEdit ? 'Edit Customer' : 'New Customer'} />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <FormField
          label="Title"
          htmlFor="title"
          required
          error={errors.title?.message}
        >
          <input
            id="title"
            type="text"
            placeholder="e.g. Elisa & Edoardo"
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            {...register('title', { required: 'Title is required' })}
          />
        </FormField>

        <div>
          <p className="text-sm font-medium text-gray-700 mb-3">People</p>
          <Controller
            name="people"
            control={control}
            render={({ field }) => (
              <PeopleRepeater value={field.value} onChange={field.onChange} />
            )}
          />
        </div>

        {mutationError && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
            {mutationError.message ?? 'An error occurred. Please try again.'}
          </p>
        )}

        <div className="flex items-center gap-3 pt-2">
          <Button
            type="submit"
            disabled={isSubmitting || mutation.isPending}
          >
            {mutation.isPending ? 'Saving...' : isEdit ? 'Update Customer' : 'Create Customer'}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/customers')}
          >
            Cancel
          </Button>
        </div>
      </form>
    </div>
  )
}
