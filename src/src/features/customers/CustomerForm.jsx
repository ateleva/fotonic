import { useState, useEffect } from 'react'
import { useParams, useNavigate, Link } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { useCustomer, useCreateCustomer, useUpdateCustomer, useDeleteCustomer } from '../../api/customers'
import { useWorks } from '../../api/works'
import { apiFetch } from '../../api/client'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import Modal from '../../components/Modal'
import PeopleRepeater from './PeopleRepeater'
import { __ } from '../../utils/i18n'

const fmtEur = (v) =>
  new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(v ?? 0)

const fmtDate = (d) => {
  if (!d) return '—'
  const [y, m, day] = d.split('-')
  return `${day}/${m}/${y}`
}

function paymentBadge(status) {
  const map = {
    paid:    { label: __('Paid', 'fotonic'),    bg: '#dcfce7', color: '#16a34a' },
    partial: { label: __('Partial', 'fotonic'), bg: '#fef9c3', color: '#ca8a04' },
    unpaid:  { label: __('Unpaid', 'fotonic'),  bg: '#fee2e2', color: '#dc2626' },
  }
  const s = map[status] ?? { label: status, bg: '#f3f4f6', color: '#6b7280' }
  return (
    <span style={{ background: s.bg, color: s.color, borderRadius: 4, padding: '2px 8px', fontSize: 12, fontWeight: 600 }}>
      {s.label}
    </span>
  )
}

function CustomerWorksSection({ customerId }) {
  const { data, isLoading } = useWorks({ customer_id: customerId, per_page: 100 })
  const works = data?.data ?? []

  const totalPrice = works.reduce((s, w) => s + (w.total_price ?? 0), 0)
  const paidTotal  = works.reduce((s, w) => {
    const paid = (w.installments ?? [])
      .filter((i) => i.status === 'paid' && i.type !== 'coupon')
      .reduce((a, i) => a + (i.amount ?? 0), 0)
    return s + paid
  }, 0)
  const unpaidTotal = totalPrice - paidTotal

  return (
    <div style={{ marginTop: 32 }}>
      <h3 style={{ margin: '0 0 12px', fontSize: '1rem', fontWeight: 700, color: '#111827' }}>
        {__('Works', 'fotonic')}
      </h3>
      {isLoading ? (
        <div style={{ textAlign: 'center', padding: '2rem', color: '#6b7280' }}><Spinner /></div>
      ) : (
        <div style={{ border: '1px solid #e5e7eb', borderRadius: 8, overflow: 'hidden', fontSize: '0.875rem' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead>
              <tr style={{ background: '#f9fafb', borderBottom: '1px solid #e5e7eb' }}>
                {[__('Title'), __('Date', 'fotonic'), __('Services', 'fotonic'), __('Total Price', 'fotonic'), __('Payment Status', 'fotonic')].map((h) => (
                  <th key={h} style={{ padding: '0.625rem 0.875rem', textAlign: 'left', fontWeight: 600, color: '#374151', whiteSpace: 'nowrap' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {works.length === 0 ? (
                <tr>
                  <td colSpan={5} style={{ padding: '2rem', textAlign: 'center', color: '#9ca3af' }}>
                    {__('No works yet.', 'fotonic')}
                  </td>
                </tr>
              ) : (
                works.map((w, i) => (
                  <tr key={w.id} style={{ borderBottom: i < works.length - 1 ? '1px solid #f3f4f6' : 'none' }}>
                    <td style={{ padding: '0.625rem 0.875rem', fontWeight: 500 }}>
                      <Link to={`/works/${w.id}`} style={{ color: '#4f46e5', textDecoration: 'none' }}>{w.title || __('(no title)', 'fotonic')}</Link>
                    </td>
                    <td style={{ padding: '0.625rem 0.875rem', color: '#6b7280', whiteSpace: 'nowrap' }}>{fmtDate(w.event_date)}</td>
                    <td style={{ padding: '0.625rem 0.875rem', color: '#374151' }}>
                      {(w.services ?? []).map((s) => s.service_title).filter(Boolean).join(', ') || '—'}
                    </td>
                    <td style={{ padding: '0.625rem 0.875rem', color: '#111827', fontWeight: 600, whiteSpace: 'nowrap' }}>{fmtEur(w.total_price)}</td>
                    <td style={{ padding: '0.625rem 0.875rem' }}>{paymentBadge(w.payment_status)}</td>
                  </tr>
                ))
              )}
            </tbody>
            {works.length > 0 && (
              <tfoot>
                <tr style={{ borderTop: '2px solid #e5e7eb', background: '#f9fafb' }}>
                  <td style={{ padding: '0.625rem 0.875rem', fontWeight: 700, color: '#111827' }}>
                    {__('Total works:', 'fotonic')} {works.length}
                  </td>
                  <td />
                  <td />
                  <td style={{ padding: '0.625rem 0.875rem', fontWeight: 700, color: '#111827', whiteSpace: 'nowrap' }}>{fmtEur(totalPrice)}</td>
                  <td style={{ padding: '0.625rem 0.875rem', fontSize: 12, color: '#374151' }}>
                    <span style={{ color: '#16a34a', marginRight: 8 }}>{__('Paid:', 'fotonic')} {fmtEur(paidTotal)}</span>
                    <span style={{ color: '#dc2626' }}>{__('Unpaid:', 'fotonic')} {fmtEur(unpaidTotal)}</span>
                  </td>
                </tr>
              </tfoot>
            )}
          </table>
        </div>
      )}
    </div>
  )
}

export default function CustomerForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)
  const [checkingDelete, setCheckingDelete] = useState(false)

  const { data: customer, isLoading } = useCustomer(id)
  const createCustomer = useCreateCustomer()
  const updateCustomer = useUpdateCustomer()
  const deleteCustomer = useDeleteCustomer()

  async function handleDeleteClick() {
    if (checkingDelete) return
    setCheckingDelete(true)
    try {
      const res = await apiFetch(`customers/${id}/can-delete`)
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
    <>
    <div className="p-6">
      <PageHeader
        title={isEdit ? __('Edit Customer') : __('New Customer')}
        backTo="/customers"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
        <FormField
          label={__('Title')}
          htmlFor="title"
          required
          error={errors.title?.message}
        >
          <input
            id="title"
            type="text"
            placeholder={__('e.g. Elisa & Edoardo')}
            className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
            {...register('title', { required: __('Title is required') })}
          />
        </FormField>

        <div>
          <p className="text-sm font-medium text-gray-700 mb-3">{__('People')}</p>
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
            {mutationError.message ?? __('An error occurred. Please try again.')}
          </p>
        )}

        <div className="flex items-center gap-3 pt-2">
          <Button
            type="submit"
            disabled={isSubmitting || mutation.isPending}
          >
            {mutation.isPending ? __('Saving...') : isEdit ? __('Update Customer') : __('Create Customer')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/customers')}
          >
            {__('Cancel')}
          </Button>
        </div>
      </form>
      {isEdit && <CustomerWorksSection customerId={Number(id)} />}
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteCustomer.mutate(id, { onSuccess: () => navigate('/customers') })}
      message={__('Delete this customer? This action cannot be undone.')}
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
