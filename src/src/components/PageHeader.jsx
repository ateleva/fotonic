import { useNavigate } from 'react-router-dom'

export default function PageHeader({ title, action, backTo, onDelete }) {
  const navigate = useNavigate()
  return (
    <div className="flex items-center gap-4 mb-6">
      {backTo && (
        <button
          type="button"
          onClick={() => navigate(backTo)}
          className="text-sm border border-gray-300 rounded px-3 py-1.5 text-gray-700 hover:bg-gray-50"
        >
          ← Back
        </button>
      )}
      <h1 className="text-xl font-semibold text-gray-900">{title}</h1>
      {action && <div className="ml-auto">{action}</div>}
      {onDelete && (
        <button
          type="button"
          onClick={onDelete}
          className="ml-auto text-sm border border-red-300 rounded px-3 py-1.5 text-red-600 hover:bg-red-50"
        >
          Delete
        </button>
      )}
    </div>
  )
}
