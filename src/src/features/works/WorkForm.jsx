import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { useForm, Controller } from 'react-hook-form'
import { useWork, useCreateWork, useUpdateWork, useDeleteWork } from '../../api/works'
import { useCustomer } from '../../api/customers'
import CustomerPicker from '../customers/CustomerPicker'
import { useServices } from '../../api/services'
import { apiFetch } from '../../api/client'
import PageHeader from '../../components/PageHeader'
import FormField from '../../components/FormField'
import Button from '../../components/Button'
import { Trash2 } from 'lucide-react'
import Spinner from '../../components/Spinner'
import ConfirmDialog from '../../components/ConfirmDialog'
import Modal from '../../components/Modal'
import SectionHeading from '../../components/SectionHeading'
import ServicesRepeater from './ServicesRepeater'
import InstallmentsRepeater from './InstallmentsRepeater'
import PaymentsSummary from './PaymentsSummary'
import FilesSection from './FilesSection'
import EventAddressesRepeater from './EventAddressesRepeater'
import MemoryCardsSection from './MemoryCardsSection'
import StorageLocationsSection from './StorageLocationsSection'
import WpEditor from '../../components/WpEditor'
import { normalizeLink } from '../../utils/link'
import { buildDeliveryMessage, mainContactName } from '../../utils/deliveryMessage'
import CopyButton from '../../components/CopyButton'
import { __ } from '../../utils/i18n'
import { NO_VAT, computeInstallmentRows, leftToAssign, requiredAmount } from '../../utils/installments'
import { usePaymentTypes } from '../../api/paymentTypes'

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

/* The explanatory sentence that used to open this component now lives on the
   section's `SectionHeading` `description` prop — it describes the section, not
   the swatch row, and belongs above the rule with the title. */
function ColorPicker({ value, onChange }) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, paddingTop: 2 }}>
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
              width: 20,
              height: 20,
              borderRadius: '50%',
              background: isDefault ? '#e5e7eb' : hex,
              border: isSelected ? '2px solid #1d2327' : isDefault ? '1.5px dashed #9ca3af' : '2px solid transparent',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 11,
              lineHeight: 1,
              padding: 0,
              color: isDefault ? '#374151' : '#fff',
              fontWeight: 700,
              transition: 'transform 0.1s',
              flexShrink: 0,
            }}
            onMouseEnter={e => { e.currentTarget.style.transform = 'scale(1.2)' }}
            onMouseLeave={e => { e.currentTarget.style.transform = 'scale(1)' }}
          >
            {isSelected ? '✓' : ''}
          </button>
        )
      })}
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
  owner_type: 'admin',
  owner_id: null,
  collaborators: [],
  suppliers: [],
  services: [],
  files: [],
  notes: '',
  total_price: '',
  installments: [],
  color: '',
  gcal_sync: false,
  memory_cards: { cards: [], backup_done: false, formatting_done: false },
  storage_locations: [],
  shots_taken: '',
  shots_delivered: '',
  delivery: { title: '', url: '', password: '', notes: '', expires: '' },
  reminders: [],
}

const OwnerField = window.FotonicProComponents?.OwnerField ?? null
const CollaboratorsSection = window.FotonicProComponents?.CollaboratorsSection ?? null
const GCalSyncField = window.FotonicProComponents?.GCalSyncField ?? null
const RemindersSection = window.FotonicProComponents?.RemindersSection ?? null
const SuppliersSection = window.FotonicProComponents?.SuppliersSection ?? null
// Pro: the VAT setting this work is computed with (its own copy once saved, today's settings for a new work).
// A hook, but the same function on every render, so calling it unconditionally is safe.
const useInstallmentVat = window.FotonicProComponents?.useInstallmentVat ?? (() => NO_VAT)
const WorkSourceQuoteLink = window.FotonicProComponents?.WorkSourceQuoteLink ?? null

export default function WorkForm() {
  const { id } = useParams()
  const isEdit = !!id
  const navigate = useNavigate()

  const [showConfirm, setShowConfirm] = useState(false)
  const [blockReason, setBlockReason] = useState(null)
  const [checkingDelete, setCheckingDelete] = useState(false)
  const [remindersBlocked, setRemindersBlocked] = useState(false)

  const { data: work, isLoading: workLoading } = useWork(id)
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
      setBlockReason(__('Unable to check references. Please try again.', 'eleva-crm-for-photographers'))
    } finally {
      setCheckingDelete(false)
    }
  }

  const services = useMemo(() => (Array.isArray(servicesData) ? servicesData : servicesData?.data ?? []), [servicesData])

  const {
    register,
    handleSubmit,
    reset,
    control,
    setValue,
    watch,
    formState: { errors, isSubmitting },
  } = useForm({ defaultValues })

  const [ownerType, ownerId, eventDate, watchedServices, watchedSuppliers, watchedInstallments, shotsTaken, shotsDelivered, watchedCustomerId, deliveryUrl, deliveryPassword, deliveryExpires] = watch([
    'owner_type',
    'owner_id',
    'event_date',
    'services',
    'suppliers',
    'installments',
    'shots_taken',
    'shots_delivered',
    'customer_id',
    'delivery.url',
    'delivery.password',
    'delivery.expires',
  ])

  const deliveredOverTaken = shotsTaken !== '' && shotsDelivered !== ''
    && shotsTaken != null && shotsDelivered != null && Number(shotsDelivered) > Number(shotsTaken)

  // The message for the client: the main contact of the work's customer, the link, the password and the expiry.
  const { data: deliveryCustomer } = useCustomer(watchedCustomerId)
  // The customer this work was saved with is in the Bin (or no longer exists): '' = nothing to say, 'trash' or 'missing'.
  // Only while that customer is still the one chosen in the field.
  const customerWarn = work?.customer_state && work?.customer_id && String(watchedCustomerId) === String(work.customer_id) ? work.customer_state : ''
  const deliveryMessage = buildDeliveryMessage({
    name: mainContactName(deliveryCustomer),
    url: deliveryUrl,
    password: deliveryPassword,
    expires: deliveryExpires,
  })

  useEffect(() => {
    if (work) {
      reset({
        title: work.title ?? '',
        event_date: work.event_date ?? '',
        event_time_from: work.event_time_from ?? '',
        event_time_to: work.event_time_to ?? '',
        event_addresses: work.event_addresses ?? [],
        customer_id: work.customer_id ? String(work.customer_id) : '',
        owner_type: work.owner_type ?? 'admin',
        owner_id: work.owner_id ?? null,
        collaborators: (work.collaborators ?? []).map((c) => ({
          ...c,
          services: (c.services ?? []).map((s) => (typeof s === 'object' ? s.id : s)),
        })),
        suppliers: work.suppliers ?? [],
        services: work.services ?? [],
        files: work.files ?? [],
        notes: work.notes ?? '',
        total_price: work.total_price ?? '',
        installments: work.installments ?? [],
        color: work.color ?? '',
        gcal_sync: work.gcal_sync ?? false,
        memory_cards: {
          cards: (work.memory_cards ?? []).map((mc) => ({ card_id: mc.card_id, notes: mc.notes ?? '' })),
          backup_done: work.backup_done ?? false,
          formatting_done: work.formatting_done ?? false,
        },
        storage_locations: (work.storage_locations ?? []).map((l) => ({
          location_id: l.location_id,
          location_title: l.location_title ?? '',
          deleted: !!l.deleted,
        })),
        shots_taken: work.shots_taken ?? '',
        shots_delivered: work.shots_delivered ?? '',
        // notes stays null when the Vault cannot read them: saving then leaves the stored note alone.
        delivery: {
          title: work.delivery?.title ?? '',
          url: work.delivery?.url ?? '',
          password: work.delivery?.password ?? null,
          notes: work.delivery?.notes ?? null,
          expires: work.delivery?.expires ?? '',
        },
        reminders: work.reminders ?? [],
      })
    }
  }, [work, reset])

  // The work asks for the services plus the supplier fees marked "added"; the installments must cover exactly
  // that. Every other figure (total, taxable price, VAT) is worked out from the installment rows.
  const { subtotal: servicesSubtotal, fees: addedFees, required } = useMemo(
    () => requiredAmount(watchedServices, watchedSuppliers),
    [watchedServices, watchedSuppliers]
  )
  const { data: paymentTypes = [] } = usePaymentTypes()
  const vat = useInstallmentVat(work ?? null)
  const calc = useMemo(
    () => computeInstallmentRows({ rows: watchedInstallments ?? [], types: paymentTypes, vat }),
    [watchedInstallments, paymentTypes, vat]
  )
  const planned = calc.planned
  const left = leftToAssign(required, planned)
  const noServices = (watchedServices ?? []).length === 0
  const noInstallments = (watchedInstallments ?? []).length === 0
  // An empty, zero or negative share is refused by the server (unless nothing has to be covered), even when
  // the others add up, so the form says so before the save.
  const badShare = required > 0 && (watchedInstallments ?? []).some((row) => !(parseFloat(row?.base) > 0))
  const paymentsBlock = noServices
    ? __('Add at least one service.', 'eleva-crm-for-photographers')
    : noInstallments
      ? __('Add at least one installment.', 'eleva-crm-for-photographers')
      : badShare
        ? __('Every installment needs an amount above zero.', 'eleva-crm-for-photographers')
        : Math.abs(left) >= 0.005
        ? __('The installments must add up to the amount they have to cover.', 'eleva-crm-for-photographers')
        : ''

  useEffect(() => {
    setValue('total_price', calc.total)
  }, [calc.total, setValue])

  const mutation = isEdit ? updateWork : createWork

  async function onSubmit(data) {
    if (remindersBlocked || paymentsBlock) {
      return
    }

    const payload = {
      ...data,
      customer_id: data.customer_id ? parseInt(data.customer_id, 10) : null,
      owner_type: data.owner_type ?? 'admin',
      owner_id: data.owner_id ? parseInt(data.owner_id, 10) : null,
      collaborators: (data.collaborators ?? []).map((c) => ({
        type: c.type,
        id: parseInt(c.id, 10),
        services: Array.isArray(c.services) ? c.services.map(Number) : [],
        price: c.price !== '' ? parseFloat(c.price) : 0,
        status: c.status,
      })),
      // Spread first: Pro owns the row shape (e.g. payment_date) and its PHP sanitizer is the whitelist.
      suppliers: (data.suppliers ?? []).map((s) => ({
        ...s,
        id: parseInt(s.id, 10),
        fee_type: s.fee_type,
        fee_value: s.fee_value !== '' ? parseFloat(s.fee_value) : 0,
        override: !!s.override,
        applied: s.applied,
      })),
      // Only what the admin decides goes up: the share of each row and its taxable toggle. The server works out
      // the surcharge, the VAT and the total again.
      installments: data.installments.map((inst) => ({
        title: inst.title ?? '',
        type: inst.type,
        status: inst.status,
        date: inst.date ?? '',
        base: inst.base !== '' && inst.base != null ? parseFloat(inst.base) : 0,
        taxable: !!inst.taxable,
      })),
      services: data.services.map((svc) => ({
        ...svc,
        service_id: svc.service_id ? parseInt(svc.service_id, 10) : null,
        price_override: svc.price_override !== '' ? parseFloat(svc.price_override) : null,
      })),
      // Send file IDs only
      file_ids: data.files.map((f) => f.id),
      memory_cards: (data.memory_cards?.cards ?? [])
        .filter((mc) => mc.card_id)
        .map((mc) => ({ card_id: Number(mc.card_id), notes: mc.notes ?? '' })),
      backup_done: data.memory_cards?.backup_done ?? false,
      formatting_done: data.memory_cards?.formatting_done ?? false,
      storage_locations: (data.storage_locations ?? [])
        .filter((l) => l.location_id)
        .map((l) => ({ location_id: Number(l.location_id) })),
      shots_taken: data.shots_taken === '' || data.shots_taken == null ? null : Number(data.shots_taken),
      shots_delivered: data.shots_delivered === '' || data.shots_delivered == null ? null : Number(data.shots_delivered),
      delivery: {
        title: data.delivery?.title ?? '',
        url: normalizeLink(data.delivery?.url) ?? '',
        password: data.delivery?.password ?? null,
        notes: data.delivery?.notes ?? null,
        expires: data.delivery?.expires ?? '',
      },
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
    <div className="p-6 ftnc-pane">
      <PageHeader
        title={isEdit ? __('Edit Work', 'eleva-crm-for-photographers') : __('New Work', 'eleva-crm-for-photographers')}
        backTo="/works"
        onDelete={isEdit ? handleDeleteClick : undefined}
      />

      {WorkSourceQuoteLink && isEdit && (work?.source_quote || work?.lead_source) && (
        <WorkSourceQuoteLink work={work} />
      )}

      <form onSubmit={handleSubmit(onSubmit)} className="ftnc-stack">
        {/* Section 1: Event */}
        <section>
          <SectionHeading>{__('Event Details', 'eleva-crm-for-photographers')}</SectionHeading>
          <div className="ftnc-grid-2">
            <div className="col-span-2">
              <FormField
                label={__('Title', 'eleva-crm-for-photographers')}
                htmlFor="title"
                required
                error={errors.title?.message}
              >
                <input
                  id="title"
                  type="text"
                  placeholder={__('e.g. Matrimonio Rossi', 'eleva-crm-for-photographers')}
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('title', { required: __('Title is required', 'eleva-crm-for-photographers') })}
                />
              </FormField>
            </div>
            <div className="col-span-2 ftnc-grid-3">
              <FormField
                label={__('Event Date', 'eleva-crm-for-photographers')}
                htmlFor="event_date"
                required
                error={errors.event_date?.message}
              >
                <input
                  id="event_date"
                  type="date"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('event_date', { required: __('Event date is required', 'eleva-crm-for-photographers') })}
                />
              </FormField>
              <FormField label={__('Event Time From', 'eleva-crm-for-photographers')} htmlFor="event_time_from">
                <input
                  id="event_time_from"
                  type="time"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('event_time_from')}
                />
              </FormField>
              <FormField label={__('Event Time To', 'eleva-crm-for-photographers')} htmlFor="event_time_to">
                <input
                  id="event_time_to"
                  type="time"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('event_time_to')}
                />
              </FormField>
            </div>
            <div className="col-span-2">
              <FormField label={__('Addresses', 'eleva-crm-for-photographers')}>
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

        {/* Section 2: Customer */}
        <section>
          <SectionHeading>{__('Customer', 'eleva-crm-for-photographers')}</SectionHeading>
          {/* As wide as a long customer name (the same 40ch as the quote form), not the whole page. */}
          <div style={{ maxWidth: '40ch' }}>
            <FormField label={__('Customer', 'eleva-crm-for-photographers')} htmlFor="customer_id">
              <Controller
                name="customer_id"
                control={control}
                render={({ field }) => (
                  // Outlined in red while the customer this work was saved with is in the Bin (or gone): see the notice below.
                  <div style={customerWarn ? { borderRadius: 6, boxShadow: '0 0 0 1.5px #fca5a5' } : undefined}>
                    <CustomerPicker
                      id="customer_id"
                      value={field.value}
                      onChange={field.onChange}
                      selectedTitle={work?.customer_title}
                    />
                  </div>
                )}
              />
            </FormField>
          </div>
          {customerWarn && (
            // A full row, not limited to the width of the field: the sentence, and next to it a small button that opens the Bin
            // already filtered on customers. It goes away when another customer is chosen (or this one is restored).
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-red-700">
              <span>
                {customerWarn === 'trash'
                  ? __('This customer is in the Bin. Choose another customer, or restore this one from the Bin.', 'eleva-crm-for-photographers')
                  : __('This customer no longer exists. Choose another customer.', 'eleva-crm-for-photographers')}
              </span>
              {customerWarn === 'trash' && (
                <Button type="button" variant="secondary" size="sm" onClick={() => navigate('/trash?type=ftnc_customer')}>
                  <Trash2 size={12} aria-hidden="true" />
                  {__('See in the Bin', 'eleva-crm-for-photographers')}
                </Button>
              )}
            </div>
          )}
        </section>

        {/* Section 3: Calendar Color */}
        <section>
          <SectionHeading
            description={__('Choose the event card color in the calendar view.', 'eleva-crm-for-photographers')}
          >
            {__('Calendar Color', 'eleva-crm-for-photographers')}
          </SectionHeading>
          <div className="flex flex-col lg:flex-row lg:items-start gap-8">
            <div className="lg:flex-1">
              <Controller
                name="color"
                control={control}
                render={({ field }) => <ColorPicker value={field.value} onChange={field.onChange} />}
              />
            </div>
            {GCalSyncField && window.FotonicApp?.features?.gcal && (
              <div className="lg:flex-1">
                <Controller
                  name="gcal_sync"
                  control={control}
                  render={({ field }) => <GCalSyncField value={field.value} onChange={field.onChange} />}
                />
              </div>
            )}
          </div>
        </section>

        {/* Section 4: Work Owner (Pro only) */}
        {OwnerField && window.FotonicApp?.features?.collaborators && (
          <section>
            <SectionHeading>{__('Work Owner', 'eleva-crm-for-photographers')}</SectionHeading>
            <OwnerField ownerType={ownerType} ownerId={ownerId} setValue={setValue} />
          </section>
        )}

        {/* Section 5: Collaborators (Pro only) */}
        {CollaboratorsSection && window.FotonicApp?.features?.collaborators && (
          <section>
            <SectionHeading>{__('Collaborators', 'eleva-crm-for-photographers')}</SectionHeading>
            <Controller
              name="collaborators"
              control={control}
              render={({ field }) => (
                <CollaboratorsSection value={field.value} onChange={field.onChange} ownerType={ownerType} />
              )}
            />
          </section>
        )}

        {/* Section 6: Suppliers (Pro only) */}
        {SuppliersSection && window.FotonicApp?.features?.suppliers && (
          <section>
            <SectionHeading>{__('Suppliers', 'eleva-crm-for-photographers')}</SectionHeading>
            <Controller
              name="suppliers"
              control={control}
              render={({ field }) => (
                <SuppliersSection value={field.value} onChange={field.onChange} />
              )}
            />
          </section>
        )}

        {/* Section 7: Services */}
        <section>
          <SectionHeading>{__('Services Included', 'eleva-crm-for-photographers')}</SectionHeading>
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

        {/* Section 8: Files */}
        <section>
          <SectionHeading>{__('Files', 'eleva-crm-for-photographers')}</SectionHeading>
          <Controller
            name="files"
            control={control}
            render={({ field }) => (
              <FilesSection value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 9: Memory Cards */}
        <section>
          <SectionHeading>{__('Memory Cards', 'eleva-crm-for-photographers')}</SectionHeading>
          <Controller
            name="memory_cards"
            control={control}
            render={({ field }) => (
              <MemoryCardsSection value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 9b: Storage & Backup */}
        <section>
          <SectionHeading description={__('Where the backups of this work are saved.', 'eleva-crm-for-photographers')}>
            {__('Storage & Backup', 'eleva-crm-for-photographers')}
          </SectionHeading>
          <Controller
            name="storage_locations"
            control={control}
            render={({ field }) => (
              <StorageLocationsSection value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 9c: Shots */}
        <section>
          <SectionHeading description={__('Used for your yearly statistics.', 'eleva-crm-for-photographers')}>
            {__('Shots', 'eleva-crm-for-photographers')}
          </SectionHeading>
          <div className="ftnc-fields">
            <div className="ftnc-grid-3">
              <FormField label={__('Shots taken', 'eleva-crm-for-photographers')} htmlFor="shots_taken">
                <input
                  id="shots_taken"
                  type="number"
                  step="1"
                  min="0"
                  inputMode="numeric"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('shots_taken')}
                />
              </FormField>
              <FormField label={__('Photos delivered', 'eleva-crm-for-photographers')} htmlFor="shots_delivered">
                <input
                  id="shots_delivered"
                  type="number"
                  step="1"
                  min="0"
                  inputMode="numeric"
                  className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                  {...register('shots_delivered')}
                />
              </FormField>
            </div>
            {deliveredOverTaken && (
              <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2">
                {__('You delivered more photos than you took. Check the two numbers.', 'eleva-crm-for-photographers')}
              </p>
            )}
          </div>
        </section>

        {/* Section 9d: Delivery */}
        <section>
          <SectionHeading description={__('How the photos reached the client: a gallery link, a transfer, a USB stick…', 'eleva-crm-for-photographers')}>
            {__('Delivery', 'eleva-crm-for-photographers')}
          </SectionHeading>
          <div className="ftnc-fields">
            {/* Row 1: title (40%) and notes (60%) side by side. */}
            <div className="flex flex-wrap gap-4">
              <div className="min-w-[12rem]" style={{ flex: '2 1 0' }}>
                <FormField label={__('Delivery title', 'eleva-crm-for-photographers')} htmlFor="delivery_title">
                  <input
                    id="delivery_title"
                    type="text"
                    placeholder={__('e.g. Pixieset gallery', 'eleva-crm-for-photographers')}
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                    {...register('delivery.title')}
                  />
                </FormField>
              </div>
              <div className="min-w-[14rem]" style={{ flex: '3 1 0' }}>
                <FormField label={__('Delivery notes', 'eleva-crm-for-photographers')} htmlFor="delivery_notes">
                  <Controller
                    name="delivery.notes"
                    control={control}
                    render={({ field }) => (
                      <input
                        id="delivery_notes"
                        type="text"
                        value={field.value ?? ''}
                        onChange={(e) => field.onChange(e.target.value)}
                        onBlur={field.onBlur}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                      />
                    )}
                  />
                </FormField>
              </div>
            </div>

            {/* Row 2: link, expiry date (the smallest) and gallery password, with the copy button on the right. */}
            <div className="flex flex-wrap items-end gap-4">
              <div className="min-w-[14rem]" style={{ flex: '2 1 0' }}>
                <FormField label={__('Link', 'eleva-crm-for-photographers')} htmlFor="delivery_url" error={errors.delivery?.url?.message}>
                  <input
                    id="delivery_url"
                    type="text"
                    inputMode="url"
                    placeholder="https://"
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                    {...register('delivery.url', {
                      validate: (v) => !v?.trim() || normalizeLink(v) !== null
                        || __('This does not look like a web address. Use something like gallery.example.com', 'eleva-crm-for-photographers'),
                    })}
                  />
                </FormField>
              </div>
              <div className="w-44 shrink-0">
                <FormField label={__('Link expiry date', 'eleva-crm-for-photographers')} htmlFor="delivery_expires">
                  <input
                    id="delivery_expires"
                    type="date"
                    className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                    {...register('delivery.expires')}
                  />
                </FormField>
              </div>
              <div className="min-w-[11rem]" style={{ flex: '1 1 0' }}>
                <FormField label={__('Gallery password', 'eleva-crm-for-photographers')} htmlFor="delivery_password">
                  <Controller
                    name="delivery.password"
                    control={control}
                    render={({ field }) => (
                      <input
                        id="delivery_password"
                        type="text"
                        autoComplete="off"
                        value={field.value ?? ''}
                        onChange={(e) => field.onChange(e.target.value)}
                        onBlur={field.onBlur}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-full"
                      />
                    )}
                  />
                </FormField>
              </div>
              <div className="ml-auto shrink-0">
                {deliveryMessage ? (
                  <CopyButton text={deliveryMessage} label={__('Copy message', 'eleva-crm-for-photographers')} buttonVariant="primary" className="" />
                ) : (
                  <Button
                    type="button"
                    disabled
                    title={__('Add a link to prepare the message for your client.', 'eleva-crm-for-photographers')}
                  >
                    {__('Copy message', 'eleva-crm-for-photographers')}
                  </Button>
                )}
              </div>
            </div>
          </div>
        </section>

        {/* Section 10: Notes */}
        <section>
          <SectionHeading>{__('Notes', 'eleva-crm-for-photographers')}</SectionHeading>
          <Controller
            name="notes"
            control={control}
            render={({ field }) => (
              <WpEditor value={field.value} onChange={field.onChange} />
            )}
          />
        </section>

        {/* Section 11: Payments */}
        <section>
          <SectionHeading>{__('Payments', 'eleva-crm-for-photographers')}</SectionHeading>
          <div className="ftnc-fields">
            {work?.legacy_payments && (
              <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 mb-3">
                {__(
                  'This work was saved before the payments changed. Check the installments below: each one is now a share of the services price, and they must add up to the amount shown before you can save.',
                  'eleva-crm-for-photographers'
                )}
              </p>
            )}
            <PaymentsSummary
              subtotal={servicesSubtotal}
              fees={addedFees}
              required={required}
              calc={calc}
              vat={vat}
              showTaxable={!!window.FotonicApp?.features?.taxable_price}
            />

            <div>
              {/* The Installments sub-heading (an <h3> under the section's <h2>), the paid and unpaid totals and the
                  arrows of the cards are drawn by the repeater itself. */}
              <Controller
                name="installments"
                control={control}
                render={({ field }) => (
                  <InstallmentsRepeater value={field.value} onChange={field.onChange} required={required} vat={vat} />
                )}
              />
            </div>
          </div>
        </section>

        {/* Section 12: Reminders (Pro only) */}
        {RemindersSection && window.FotonicApp?.features?.kanban && (
          <section>
            <SectionHeading>{__('Reminders', 'eleva-crm-for-photographers')}</SectionHeading>
            <Controller
              name="reminders"
              control={control}
              render={({ field }) => (
                <RemindersSection
                  value={field.value}
                  onChange={field.onChange}
                  eventDate={eventDate}
                  baseDate={work?.reminders_base_date ?? ''}
                  onBlockedChange={setRemindersBlocked}
                />
              )}
            />
          </section>
        )}

        {paymentsBlock && (
          <p className="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">{paymentsBlock}</p>
        )}

        {mutation.error && (
          <p className="text-sm text-red-600 bg-red-50 border border-red-200 rounded px-3 py-2">
            {mutation.error.message ?? __('An error occurred. Please try again.', 'eleva-crm-for-photographers')}
          </p>
        )}

        <div className="flex items-center gap-3 pt-2">
          <Button type="submit" disabled={isSubmitting || mutation.isPending || remindersBlocked || !!paymentsBlock}>
            {mutation.isPending
              ? __('Saving...', 'eleva-crm-for-photographers')
              : isEdit
              ? __('Update Work', 'eleva-crm-for-photographers')
              : __('Create Work', 'eleva-crm-for-photographers')}
          </Button>
          <Button
            type="button"
            variant="secondary"
            onClick={() => navigate('/works')}
          >
            {__('Cancel', 'eleva-crm-for-photographers')}
          </Button>
        </div>
      </form>
    </div>

    <ConfirmDialog
      open={showConfirm}
      onClose={() => setShowConfirm(false)}
      onConfirm={() => deleteWork.mutate(id, { onSuccess: () => navigate('/works') })}
      message={__('Delete this work? This action cannot be undone.', 'eleva-crm-for-photographers')}
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
