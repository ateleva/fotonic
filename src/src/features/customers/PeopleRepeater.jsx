import { Trash2, PlusCircle } from 'lucide-react'
import Button from '../../components/Button'
import { __ } from '../../utils/i18n'

const emptyPerson = () => ({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  nationality: '',
  instagram_username: '',
  address: '',
  tin: '',
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
    // If removed person was main, assign main to first
    const hasMain = next.some((p) => p.is_main)
    if (!hasMain) next[0] = { ...next[0], is_main: true }
    onChange(next)
  }

  return (
    <div className="ftnc-fields">
      {people.map((person, index) => (
        <div
          key={index}
          className="border border-gray-200 rounded-lg p-4 bg-gray-50 ftnc-fields"
        >
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-gray-700">
              {__('Person', 'eleva-crm-for-photographers')} {index + 1}
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
                {__('Main Contact', 'eleva-crm-for-photographers')}
              </label>
              <Button
                type="button"
                variant="danger"
                size="sm"
                onClick={() => removePerson(index)}
                disabled={people.length <= 1}
                aria-label={__('Remove person', 'eleva-crm-for-photographers')}
              >
                <Trash2 size={14} />
              </Button>
            </div>
          </div>

          <div className="ftnc-grid-2">
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('First Name', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.first_name}
                onChange={(e) => update(index, 'first_name', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder={__('First name', 'eleva-crm-for-photographers')}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Last Name', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.last_name}
                onChange={(e) => update(index, 'last_name', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder={__('Last name', 'eleva-crm-for-photographers')}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Email', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="email"
                value={person.email}
                onChange={(e) => update(index, 'email', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="email@example.com"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Phone', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="tel"
                value={person.phone}
                onChange={(e) => update(index, 'phone', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="+39..."
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Nationality', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.nationality}
                onChange={(e) => update(index, 'nationality', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="IT"
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Instagram', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.instagram_username ?? ''}
                onChange={(e) => update(index, 'instagram_username', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="@username"
              />
            </div>
            <div className="col-span-2">
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('Address', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.address ?? ''}
                onChange={(e) => update(index, 'address', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder={__('Via Roma 1, Milano', 'eleva-crm-for-photographers')}
              />
            </div>
            <div>
              <label className="block text-xs font-medium text-gray-600 mb-1">
                {__('TIN', 'eleva-crm-for-photographers')}
              </label>
              <input
                type="text"
                value={person.tin ?? ''}
                onChange={(e) => update(index, 'tin', e.target.value)}
                className="w-full rounded-md border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
                placeholder="RSSMRA80A01H501U"
              />
            </div>
          </div>
        </div>
      ))}

      <Button type="button" variant="secondary" size="sm" onClick={addPerson}>
        <PlusCircle size={14} />
        {__('Add Person', 'eleva-crm-for-photographers')}
      </Button>
    </div>
  )
}
