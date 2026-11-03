import { useState } from 'react'
import { PlusCircle, Pencil, Trash2, Check, X, Loader } from 'lucide-react'
import {
  usePaymentTypes,
  useCreatePaymentType,
  useUpdatePaymentType,
  useDeletePaymentType,
} from '../../api/paymentTypes'
import Button from '../../components/Button'
import Modal from '../../components/Modal'
import ConfirmDialog from '../../components/ConfirmDialog'
import { __ } from '../../utils/i18n'

// Pro: a switch on the payment type for the extra percentage added to the rows that use it (Rivalsa INPS).
// A plain {value, onChange} component, where value is the percentage (0 = off).
const RivalsaTypeField = window.FotonicProComponents?.RivalsaTypeField ?? null

export default function PaymentTypeManager({ open, onClose }) {
  const [addName, setAddName]     = useState('')
  const [addPct, setAddPct]       = useState(0)
  const [editPct, setEditPct]     = useState(0)
  const [addError, setAddError]   = useState(null)
  const [editId, setEditId]       = useState(null)
  const [editName, setEditName]   = useState('')
  const [editError, setEditError] = useState(null)
  const [deleteId, setDeleteId]   = useState(null)

  const { data: types = [], isLoading } = usePaymentTypes()
  const createMutation = useCreatePaymentType()
  const updateMutation = useUpdatePaymentType()
  const deleteMutation = useDeletePaymentType()

  // Reset every transient state on close so reopening never shows a stale edit row or error.
  function close() {
    setAddName('')
    setAddPct(0)
    setAddError(null)
    setEditId(null)
    setEditName('')
    setEditError(null)
    setDeleteId(null)
    onClose()
  }

  function startEdit(t) {
    setEditId(t.id)
    setEditPct(Number(t.surcharge_pct) || 0)
    setEditName(t.label)
    setEditError(null)
  }

  function cancelEdit() {
    setEditId(null)
    setEditName('')
    setEditError(null)
  }

  function handleAdd(e) {
    e.preventDefault()
    const label = addName.trim()
    if (!label) { setAddError(__('Name is required.', 'eleva-crm-for-photographers')); return }
    createMutation.mutate(RivalsaTypeField ? { label, surcharge_pct: addPct } : label, {
      onSuccess: () => { setAddName(''); setAddPct(0); setAddError(null) },
      onError: (err) => setAddError(err?.message ?? __('Error adding type.', 'eleva-crm-for-photographers')),
    })
  }

  function handleUpdate(e) {
    e.preventDefault()
    const label = editName.trim()
    if (!label) { setEditError(__('Name is required.', 'eleva-crm-for-photographers')); return }
    updateMutation.mutate(RivalsaTypeField ? { id: editId, label, surcharge_pct: editPct } : { id: editId, label }, {
      onSuccess: () => cancelEdit(),
      onError: (err) => setEditError(err?.message ?? __('Error updating type.', 'eleva-crm-for-photographers')),
    })
  }

  return (
    <>
      <Modal open={open} onClose={close} title={__('Payment Types', 'eleva-crm-for-photographers')} size="lg">
        <div className="space-y-4">
          {/* Add form */}
          <form onSubmit={handleAdd} className="flex items-center gap-2">
            <input
              value={addName}
              onChange={(e) => { setAddName(e.target.value); setAddError(null) }}
              placeholder={__('New payment type…', 'eleva-crm-for-photographers')}
              className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 flex-1 min-w-0"
            />
            {RivalsaTypeField && window.FotonicApp?.features?.surcharge && (
              <RivalsaTypeField value={addPct} onChange={setAddPct} />
            )}
            <Button type="submit" variant="primary" size="sm" disabled={createMutation.isPending}>
              {createMutation.isPending
                ? <Loader size={13} className="animate-spin" />
                : <PlusCircle size={13} />}
              {__('Add', 'eleva-crm-for-photographers')}
            </Button>
            {addError && <span className="text-red-600 text-xs">{addError}</span>}
          </form>

          {/* List */}
          {isLoading ? (
            <div className="flex justify-center py-4">
              <Loader size={20} className="animate-spin text-gray-400" />
            </div>
          ) : types.length === 0 ? (
            <p className="text-sm text-gray-400 italic">{__('No payment types yet.', 'eleva-crm-for-photographers')}</p>
          ) : (
            <div className="max-h-[50vh] overflow-y-auto divide-y divide-gray-100 rounded-md border border-gray-200">
              {types.map((t) => (
                <div key={t.id} className="flex items-center justify-between px-3 py-2 bg-white hover:bg-gray-50">
                  <div className="flex-1 min-w-0">
                    {editId === t.id ? (
                      <form onSubmit={handleUpdate} className="flex items-center gap-2">
                        <input
                          autoFocus
                          value={editName}
                          onChange={(e) => setEditName(e.target.value)}
                          className="rounded-md border border-gray-300 px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-48"
                        />
                        {RivalsaTypeField && window.FotonicApp?.features?.surcharge && (
                          <RivalsaTypeField value={editPct} onChange={setEditPct} />
                        )}
                        {editError && <span className="text-red-600 text-xs">{editError}</span>}
                      </form>
                    ) : (
                      // t.label is admin-editable data (fotonic_payment_types option), already
                      // final text by the time it reaches here — not a __()-translatable string.
                      <span className="text-sm font-medium text-gray-800">
                        {t.label}
                        {Number(t.surcharge_pct) > 0 && (
                          <span className="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">+{Number(t.surcharge_pct)}%</span>
                        )}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-1.5 ml-3 shrink-0">
                    {editId === t.id ? (
                      <>
                        <Button variant="primary" size="sm" onClick={handleUpdate} disabled={updateMutation.isPending}>
                          <Check size={13} />
                          {__('Save', 'eleva-crm-for-photographers')}
                        </Button>
                        <Button variant="secondary" size="sm" onClick={cancelEdit}>
                          <X size={13} />
                          {__('Cancel', 'eleva-crm-for-photographers')}
                        </Button>
                      </>
                    ) : (
                      <>
                        <Button variant="secondary" size="sm" onClick={() => startEdit(t)}>
                          <Pencil size={13} />
                          {__('Edit', 'eleva-crm-for-photographers')}
                        </Button>
                        <Button variant="danger" size="sm" onClick={() => setDeleteId(t.id)} disabled={types.length <= 1}>
                          <Trash2 size={13} />
                          {__('Delete', 'eleva-crm-for-photographers')}
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </Modal>

      <ConfirmDialog
        open={deleteId !== null}
        onClose={() => setDeleteId(null)}
        onConfirm={() => deleteMutation.mutate(deleteId)}
        message={__('Delete this payment type? Existing installments will keep their current type.', 'eleva-crm-for-photographers')}
      />
    </>
  )
}
