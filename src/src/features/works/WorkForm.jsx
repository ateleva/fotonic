import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { useWork, useCreateWork, useUpdateWork, useDeleteWork } from '../../api/works'
import { useCustomers } from '../../api/customers'
import { useServices } from '../../api/services'
import { apiFetch } from '../../api/client'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import Modal from '../../components/Modal'
import ServicesRepeater from './ServicesRepeater'
import InstallmentsRepeater from './InstallmentsRepeater'
import FilesSection from './FilesSection'
import EventAddressesRepeater from './EventAddressesRepeater'
import WpEditor from '../../components/WpEditor'
import { __ } from '../../utils/i18n'

const COLOR_PALETTE = [
  { label: 'Default',   hex: '' },
  { label: 'Tomato',    hex: '#D50000' },
  { label: 'Flamingo',  hex: '#E67C73' },
  { label: 'Tangerine', hex: '#F4511E' },
  { label: 'Banana',    hex: '#F6BF26' },
  { label: 'Sage',      hex: '#33B679' },
  { label: 'Basil',     hex: '#0B8043' },
  { label: 'Peacock',   hex: '#039BE5' },
  { label: 'Blueberry', hex: '#3F51B5' },
  { label: 'Lavender',  hex: '#7986CB' },
  { label: 'Grape',     hex: '#8E24AA' },
  { label: 'Graphite',  hex: '#616161' },
]

function ColorPicker({ value, onChange }) {
  return (
    <div>
      <p className="text-sm text-gray-500 mb-3">Choose the event card color in the calendar view.</p>
      <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }}>
        {COLOR_PALETTE.map(({ label, hex }) => {
          const isSelected = value === hex
          const isDefault  = hex === ''
          return (
            <button
              key={label}
              type="button"
              title={label}
              onClick={() => onChange(hex)}
              style={{
                width: 30,
                height: 30,
                borderRadius: '50%',
                background: isDefault ? '#e5e7eb' : hex,
                border: isSelected ? '3px solid #1d2327' : isDefault ? '2px dashed #9ca3af' : '2px solid transparent',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                fontSize: 14,
                color: isDefault ? '#374151' : '#fff',
                fontWeight: 700,
                transition: 'transform 0.1s',
                flexShrink: 0,
              }}
              onMouseEnter={e => { e.currentTarget.style.transform = 'scale(1.15)' }}
              onMouseLeave={e => { e.currentTarget.style.transform = 'scale(1)' }}
            >
              {isSelected ? '✓' : ''}
            </button>
          )
        })}
      </div>
    </div>
  )
}

function SectionHeading({ children }) {
  return (
    <div className="border-b border-gray-200 pb-2 mb-4">
      <h2 className="text-base font-semibold text-gray-800">{children}</h2>
    </div>
  )
}

const defaultValues = {
  title: '',
  event_date: '',
  event_time_from: '',
  event_time_to: '',
  event_addresses: [],
  customer_id: '',
  services: [],
  files: [],
  notes: '',
  total_price: '',
  installments: [],
  color: '',
}

const NotificationsSection = window.FotonicProComponents?.NotificationsSection ?? null

export default function WorkForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)
  const [checkingDelete, setCheckingDelete] = useState(false)

  const { data: work, isLoading: workLoading } = useWork(id)
  const { data: customersData } = useCustomers({ per_page: 100 })
  const { data: servicesData } = useServices()
  const createWork = useCreateWork()
  const updateWork = useUpdateWork()
  const deleteWork = useDeleteWork()

  async function handleDeleteClick() {
    if (checkingDelete) return
    setCheckingDelete(true)
    try {
      const res = await apiFetch(`works/${id}/can-delete`)
      if (res.can_delete) {
        setShowConfirm(true)
      } else {
        setBlockReason(res.reason)
      }
    } catch {
      setBlockReason('Unable to check references. Please try again.')
    } finally {
      setCheckingDelete(false)
    }
  }

  const customers = Array.isArray(customersData) ? customersData : customersData?.data ?? []
  const services = Array.isArray(servicesData) ? servicesData : servicesData?.data ?? []

  const {
    register,
    handleSubmit,
    reset,
    control,
    formState: { errors, isSubmitting },
  } = useForm({ defaultValues })

  useEffect(() => {
    if (work) {
      reset({
        title: work.title ?? '',
        event_date: work.event_date ?? '',
        event_time_from: work.event_time_from ?? '',
        event_time_to: work.event_time_to ?? '',
        event_addresses: work.event_addresses ?? [],
        customer_id: work.customer_id ? String(work.customer_id) : '',
        services: work.services ?? [],
        files: work.files ?? [],
        notes: work.notes ?? '',
        total_price: work.total_price ?? '',
        installments: work.installments ?? [],
        color: work.color ?? '',
      })
    }
  }, [work, reset])

  const mutation = isEdit ? updateWork : createWork

  async function onSubmit(data) {
    const payload = {
      ...data,
      customer_id: data.customer_id ? parseInt(data.customer_id, 10) : null,
      total_price: data.total_price !== '' ? parseFloat(data.total_price) : null,
      installments: data.installments.map((inst) => ({
        ...inst,
        amount: inst.amount !== '' ? parseFloat(inst.amount) : 0,
      })),
      services: data.services.map((svc) => ({
        ...svc,
        service_id: svc.service_id ? parseInt(svc.service_id, 10) : null,
        price_override: svc.price_override !== '' ? parseFloat(svc.price_override) : null,
      })),
      // Send file IDs only
      file_ids: data.files.map((f) => f.id),
    }

    try {
      if (isEdit) {
        await updateWork.mutateAsync({ id, ...payload })
      } else {
        await createWork.mutateAsync(payload)
      }
      navigate('/works')
    } catch {
      // Error displayed below
    }
  }

  if (isEdit && workLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    )
  }

  return (
    <>
    <div className="p-6 max-w-3xl">
      <PageHeader
        title={isEdit ? 'Edit Work' : 'New Work'}
        backTo="/works"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      <form onSubmit={handleSubmit(onSubmit)} className="space-y-8">
        {/* Section 1 — Event */}
        <section>
          <SectionHeading>Event Details</SectionHeading>
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <FormField
                label="Title"
                htmlFor="title"
                required
                error={errors.title?.message}
              >
                <input
                  id="title"
                  type="text"
                  placeholder="e.g. Matrimonio Rossi"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('title', { required: 'Title is required' })}
                />
              </FormField>
            </div>
            <FormField label="Event Date" htmlFor="event_date">
              <input
                id="event_date"
                type="date"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                {...register('event_date')}
              />
            </FormField>
            <FormField label={__('Event Time From', 'fotonic')} htmlFor="event_time_from">
              <input
                id="event_time_from"
                type="time"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                {...register('event_time_from')}
              />
            </FormField>
            <FormField label={__('Event Time To', 'fotonic')} htmlFor="event_time_to">
              <input
                id="event_time_to"
                type="time"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                {...register('event_time_to')}
              />
            </FormField>
            <div className="col-span-2">
              <FormField label={__('Addresses', 'fotonic')}>
                <Controller
                  name="event_addresses"
                  control={control}
                  render={({ field }) => (
                    <EventAddressesRepeater value={field.value} onChange={field.onChange} />
                  )}
                />
              </FormField>
            </div>
          </div>
        </section>

        {/* Section 2 — Customer */}
        <section>
          <SectionHeading>Customer</SectionHeading>
          <FormField label="Customer" htmlFor="customer_id">
            <select
              id="customer_id"
              className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
              {...register('customer_id')}
            >
              <option value="">— Select customer —</option>
              {customers.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.title}
                </option>
              ))}
            </select>
          </FormField>
        </section>

        {/* Section 3 — Calendar Color */}
        <section>
          <SectionHeading>Calendar Color</SectionHeading>
          <Controller
            name="color"
            control={control}
            render={({ field }) => <ColorPicker value={field.value} onChange={field.onChange} />}
          />
        </section>

        {/* Section 4 — Services */}
        <section>
          <SectionHeading>Services Included</SectionHeading>
          <Controller
            name="services"
            control={control}
            render={({ field }) => (
              <ServicesRepeater
                value={field.value}
                onChange={field.onChange}
                services={services}
              />
            )}
          />
        </section>

        {/* Section 4 — Files */}
        <section>
          <SectionHeading>Files</SectionHeading>
          <Controller
            name="files"
            control={control}
            render={({ field }) => (
              <FilesSection value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 5 — Notes */}
        <section>
          <SectionHeading>{__('Notes', 'fotonic')}</SectionHeading>
          <Controller
            name="notes"
            control={control}
            render={({ field }) => (
              <WpEditor value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 6 — Payments */}
        <section>
          <SectionHeading>Payments</SectionHeading>
          <div className="space-y-4">
            <FormField label="Total Price (€)" htmlFor="total_price">
              <input
                id="total_price"
                type="number"
                min="0"
                step="0.01"
                placeholder="0.00"
                className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-48"
                {...register('total_price')}
              />
            </FormField>

            <div>
              <p className="text-sm font-medium text-gray-700 mb-3">Installments</p>
              <Controller
                name="installments"
                control={control}
                render={({ field }) => (
                  <InstallmentsRepeater value={field.value} onChange={field.onChange} />
                )}
              />
            </div>
          </div>
        </section>

        {/* Section 7 — Notifications (Pro only, edit mode only) */}
        {isEdit && NotificationsSection && window.FotonicApp?.features?.notifications && (
          <section>
            <SectionHeading>{__('Scheduled Notifications', 'fotonic')}</SectionHeading>
            <NotificationsSection workId={id} />
          </section>
        )}

        {mutation.error && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
            {mutation.error.message ?? 'An error occurred. Please try again.'}
          </p>
        )}

        <div className="flex items-center gap-3 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>
            {mutation.isPending
              ? 'Saving...'
              : isEdit
              ? 'Update Work'
              : 'Create Work'}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/works')}
          >
            Cancel
          </Button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteWork.mutate(id, { onSuccess: () => navigate('/works') })}
      message="Delete this work? This action cannot be undone."
    />
    <Modal open={blockReason !== null} onClose={() => setBlockReason(null)} title="Cannot Delete">
      <p className="text-sm text-gray-600 mb-6">{blockReason}</p>
      <div className="flex justify-end">
        <Button onClick={() => setBlockReason(null)}>OK</Button>
      </div>
    </Modal>
    </>
  )
}
