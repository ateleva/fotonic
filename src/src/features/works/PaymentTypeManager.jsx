import { useState } from 'react'
import { PlusCircle, Pencil, Trash2, Check, X, Loader } from 'lucide-react'
import {
  usePaymentTypes,
  useCreatePaymentType,
  useUpdatePaymentType,
  useDeletePaymentType,
} from '../../api/paymentTypes'
import Button from '../../components/Button'
import { __ } from '../../utils/i18n'

export default function PaymentTypeManager() {
  const [open, setOpen]           = useState(false)
  const [addName, setAddName]     = useState('')
  const [addError, setAddError]   = useState(null)
  const [editId, setEditId]       = useState(null)
  const [editName, setEditName]   = useState('')
  const [editError, setEditError] = useState(null)
  const [deleteId, setDeleteId]   = useState(null)

  const { data: types = [], isLoading } = usePaymentTypes()
  const createMutation = useCreatePaymentType()
  const updateMutation = useUpdatePaymentType()
  const deleteMutation = useDeletePaymentType()

  function startEdit(t) {
    setEditId(t.id)
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
    if (!label) { setAddError(__('Name is required.')); return }
    createMutation.mutate(label, {
      onSuccess: () => { setAddName(''); setAddError(null) },
      onError: (err) => setAddError(err?.message ?? __('Error adding type.')),
    })
  }

  function handleUpdate(e) {
    e.preventDefault()
    const label = editName.trim()
    if (!label) { setEditError(__('Name is required.')); return }
    updateMutation.mutate({ id: editId, label }, {
      onSuccess: () => cancelEdit(),
      onError: (err) => setEditError(err?.message ?? __('Error updating type.')),
    })
  }

  return (
    <div className="mt-12 rounded-lg border border-gray-200 overflow-hidden">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between bg-white px-4 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors focus:outline-none"
      >
        <span>{__('Payment Types')}</span>
        <span className="text-gray-400 text-xs">{open ? '▲' : '▼'}</span>
      </button>

      {open && (
        <div className="border-t border-gray-100 bg-white px-4 py-4 space-y-4">
          {/* Add form */}
          <form onSubmit={handleAdd} className="flex items-center gap-2">
            <input
              value={addName}
              onChange={(e) => { setAddName(e.target.value); setAddError(null) }}
              placeholder={__('New payment type…')}
              className="rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 w-56"
            />
            <Button type="submit" variant="primary" size="sm" disabled={createMutation.isPending}>
              {createMutation.isPending
                ? <Loader size={13} className="animate-spin" />
                : <PlusCircle size={13} />}
              {__('Add')}
            </Button>
            {addError && <span className="text-red-600 text-xs">{addError}</span>}
          </form>

          {/* List */}
          {isLoading ? (
            <div className="flex justify-center py-4">
              <Loader size={20} className="animate-spin text-gray-400" />
            </div>
          ) : types.length === 0 ? (
            <p className="text-sm text-gray-400 italic">{__('No payment types yet.')}</p>
          ) : (
            <div className="divide-y divide-gray-100 rounded-md border border-gray-200 overflow-hidden">
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
                        {editError && <span className="text-red-600 text-xs">{editError}</span>}
                      </form>
                    ) : (
                      <span className="text-sm font-medium text-gray-800">{t.label}</span>
                    )}
                  </div>
                  <div className="flex items-center gap-1.5 ml-3 shrink-0">
                    {editId === t.id ? (
                      <>
                        <Button variant="primary" size="sm" onClick={handleUpdate} disabled={updateMutation.isPending}>
                          <Check size={13} />
                          {__('Save')}
                        </Button>
                        <Button variant="secondary" size="sm" onClick={cancelEdit}>
                          <X size={13} />
                          {__('Cancel')}
                        </Button>
                      </>
                    ) : (
                      <>
                        <Button variant="secondary" size="sm" onClick={() => startEdit(t)}>
                          <Pencil size={13} />
                          {__('Edit')}
                        </Button>
                        <Button variant="danger" size="sm" onClick={() => setDeleteId(t.id)} disabled={types.length <= 1}>
                          <Trash2 size={13} />
                          {__('Delete')}
                        </Button>
                      </>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* Delete confirmation */}
      {deleteId !== null && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="bg-white rounded-lg shadow-xl p-6 max-w-sm w-full mx-4">
            <p className="text-sm text-gray-700 mb-5">
              {__('Delete this payment type? Existing installments will keep their current type.')}
            </p>
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setDeleteId(null)}>
                {__('Cancel')}
              </Button>
              <Button
                variant="danger"
                onClick={() => deleteMutation.mutate(deleteId, { onSuccess: () => setDeleteId(null) })}
                disabled={deleteMutation.isPending}
              >
                {__('Delete')}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
