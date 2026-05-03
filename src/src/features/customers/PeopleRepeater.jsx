import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'

const emptyPerson = () => ({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  nationality: '',
  is_main: false,
})

export default function PeopleRepeater({ value = [], onChange }) {
  const people = value.length > 0 ? value : [{ ...emptyPerson(), is_main: true }]

  function update(index, field, val) {
    const next = people.map((p, i) => {
      if (field === 'is_main') {
        return { ...p, is_main: i === index }
      }
      if (i === index) return { ...p, [field]: val }
      return p
    })
    onChange(next)
  }

  function addPerson() {
    onChange([...people, emptyPerson()])
  }

  function removePerson(index) {
    if (people.length <= 1) return
    const next = people.filter((_, i) => i !== index)
    const hasMain = next.some((p) => p.is_main)
    if (!hasMain) next[0] = { ...next[0], is_main: true }
    onChange(next)
  }

  return (
    <div className="space-y-4">
      {people.map((person, index) => (
        <div
          key={index}
          className="border border-gray-200 rounded-lg p-4 bg-gray-50 space-y-3"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-700">
              Person {index + 1}
            </span>
            <div className="flex items-center gap-3">
              <label className="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer">
                <input
                  type="radio"
                  name="is_main_contact"
                  checked={!!person.is_main}
                  onChange={() => update(index, 'is_main', true)}
                  className="text-indigo-600"
                />
                Main Contact
              </label>
              <button
                type="button"
                onClick={() => removePerson(index)}
                disabled={people.length <= 1}
                className="text-gray-400 hover:text-red-500 disabled:opacity-30 disabled:cursor-not-allowed transition-colors"
                aria-label="Remove person"
              >
                <Trash2 size={16} />
              </button>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">First Name</label>
              <input type="text" value={person.first_name} onChange={(e) => update(index, 'first_name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="First name" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Last Name</label>
              <input type="text" value={person.last_name} onChange={(e) => update(index, 'last_name', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Last name" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Email</label>
              <input type="email" value={person.email} onChange={(e) => update(index, 'email', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="email@example.com" />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Phone</label>
              <input type="tel" value={person.phone} onChange={(e) => update(index, 'phone', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="+39..." />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">Nationality</label>
              <input type="text" value={person.nationality} onChange={(e) => update(index, 'nationality', e.target.value)} className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="IT" />
            </div>
          </div>
        </div>
      ))}

      <Button type="button" variant="secondary" size="sm" onClick={addPerson}>
        <PlusCircle size={14} />
        Add Person
      </Button>
    </div>
  )
}
