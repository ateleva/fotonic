import { useState } from 'react'
import { useCustomers, useCustomer } from '../../api/customers'
import SearchSelect from '../../components/SearchSelect'
import useDebounce from '../../hooks/useDebounce'
import { __, sprintf } from '../../utils/i18n'

const SHOWN = 20

/**
 * Pick a customer by typing part of the name. The server does the search (up to SHOWN results), so it works with
 * any number of customers; a plain <select> could only list the first hundred.
 * `value` is the customer id (string or number, '' for none); `selectedTitle` is shown while the record loads.
 */
export default function CustomerPicker({ id, value, onChange, selectedTitle, invalid, allowClear = true }) {
  const [query, setQuery] = useState('')
  const debounced = useDebounce(query.trim())
  const { data, isFetching } = useCustomers({ search: debounced, per_page: SHOWN })
  const rows = data?.data ?? []
  const { data: chosen } = useCustomer(value ? Number(value) : 0)

  const total = data?.total ?? rows.length
  return (
    <SearchSelect
      id={id}
      value={value ? String(value) : ''}
      onChange={onChange}
      onSearch={setQuery}
      options={rows.map((c) => ({ value: String(c.id), label: c.title || __('(no title)', 'eleva-crm-for-photographers') }))}
      selectedLabel={chosen?.title ?? selectedTitle}
      placeholder={__('Select customer', 'eleva-crm-for-photographers')}
      allowClear={allowClear}
      invalid={invalid}
      loading={isFetching}
      emptyText={__('No customers found', 'eleva-crm-for-photographers')}
      footer={total > rows.length ? sprintf(__('Showing %1$s of %2$s customers. Type to narrow down.', 'eleva-crm-for-photographers'), rows.length, total) : null}
    />
  )
}
