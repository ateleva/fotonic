import { useState, useRef, useEffect } from 'react'
import { useNavigate } from 'react-router-dom'
import { useCalendarWorks } from '../../api/calendar'
import { useSyncAll, useUnlinkWorkGCalEvent, useUnlinkTaskGCalEvent } from '../../api/gcal'
import { __ } from '../../utils/i18n'
import { formatTime } from '../../utils/date'
import Button from '../../components/Button'

const KANBAN_COLORS = {
  todo:        { bg: '#6b7280' },
  in_progress: { bg: '#2563eb' },
  done:        { bg: '#16a34a' },
}

const PAYMENT_COLORS = {
  paid:    { bg: '#dcfce7', color: '#15803d' },
  partial: { bg: '#fef9c3', color: '#a16207' },
  unpaid:  { bg: '#fee2e2', color: '#b91c1c' },
}

function kanbanLabel(status) {
  switch (status) {
    case 'todo':        return __('To Do')
    case 'in_progress': return __('In Progress')
    case 'done':        return __('Done')
    default:            return status
  }
}

function paymentLabel(status) {
  switch (status) {
    case 'paid':    return __('Paid')
    case 'partial': return __('Partial')
    case 'unpaid':  return __('Unpaid')
    default:        return status
  }
}

const DAYS_KEYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']

const wpLocale = (window.FotonicApp?.locale ?? 'en-US').replace('_', '-')

function padTwo(n) { return String(n).padStart(2, '0') }
function getDaysInMonth(year, month) { return new Date(year, month + 1, 0).getDate() }
function dayOfWeek(year, month, day) {
  const d = new Date(year, month, day).getDay()
  return d === 0 ? 6 : d - 1
}
function buildGrid(year, month) {
  const daysInMonth = getDaysInMonth(year, month)
  const firstDow = dayOfWeek(year, month, 1)
  const cells = []
  for (let i = 0; i < firstDow; i++) cells.push(null)
  for (let d = 1; d <= daysInMonth; d++) cells.push(d)
  while (cells.length % 7 !== 0) cells.push(null)
  return cells
}
function monthLabel(year, month) {
  return new Date(year, month, 1).toLocaleString(wpLocale, { month: 'long', year: 'numeric' })
}
function formatDateLong(dateStr) {
  if (!dateStr) return ''
  const [y, m, d] = dateStr.split('-')
  return new Date(y, m - 1, d).toLocaleDateString(wpLocale, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

// ---------------------------------------------------------------------------
// Event popup
// ---------------------------------------------------------------------------

function EventPopup({ event, anchorRef, onClose, onOpen, gcalEnabled }) {
  const popupRef = useRef(null)
  const isTask   = event.type === 'task'
  const kanban   = !isTask ? KANBAN_COLORS[event.kanban_status] : null
  const payment  = !isTask ? PAYMENT_COLORS[event.payment_status] : null

  const unlinkWork = useUnlinkWorkGCalEvent(event.id)
  const unlinkTask = useUnlinkTaskGCalEvent(event.id)
  const unlinkMut  = isTask ? unlinkTask : unlinkWork

  // Close on outside click
  useEffect(() => {
    function handleClick(e) {
      if (
        popupRef.current && !popupRef.current.contains(e.target) &&
        anchorRef.current && !anchorRef.current.contains(e.target)
      ) {
        onClose()
      }
    }
    document.addEventListener('mousedown', handleClick)
    return () => document.removeEventListener('mousedown', handleClick)
  }, [onClose, anchorRef])

  // Position popup below the pill using fixed (viewport-relative) coords
  const [pos, setPos] = useState({ top: -9999, left: -9999 })
  useEffect(() => {
    if (!anchorRef.current || !popupRef.current) return
    const anchor = anchorRef.current.getBoundingClientRect()
    const popup  = popupRef.current.getBoundingClientRect()
    let top  = anchor.bottom + 6
    let left = anchor.left
    if (left + popup.width > window.innerWidth - 12)
      left = window.innerWidth - popup.width - 12
    if (left < 8) left = 8
    if (top + popup.height > window.innerHeight - 12)
      top = anchor.top - popup.height - 6
    setPos({ top, left })
  }, [anchorRef])

  async function handleUnlink() {
    try {
      await unlinkMut.mutateAsync()
    } catch {}
    onClose()
  }

  return (
    <div
      ref={popupRef}
      style={{
        position: 'fixed',
        zIndex: 99999,
        top: pos.top,
        left: pos.left,
        width: 280,
        background: '#fff',
        border: '1px solid #e5e7eb',
        borderRadius: 10,
        boxShadow: '0 8px 24px rgba(0,0,0,0.12)',
        padding: '14px 16px',
        fontSize: 13,
        color: '#111827',
      }}
    >
      {/* Close */}
      <button
        onClick={onClose}
        style={{ position: 'absolute', top: 10, right: 10, background: 'none', border: 'none', cursor: 'pointer', color: '#9ca3af', fontSize: 16, lineHeight: 1, padding: 2 }}
        aria-label={__('Close')}
      >✕</button>

      {/* Colour strip */}
      <div style={{ width: 4, height: '100%', background: event.color || '#9ca3af', position: 'absolute', left: 0, top: 0, borderRadius: '10px 0 0 10px' }} />

      {/* Task badge */}
      {isTask && (
        <div style={{ marginBottom: 6 }}>
          <span style={{ background: '#f3f4f6', color: '#6b7280', borderRadius: 99, padding: '2px 8px', fontSize: 11, fontWeight: 600 }}>{__('Task')}</span>
        </div>
      )}

      {/* Title */}
      <div style={{ fontWeight: 700, fontSize: 14, marginBottom: 10, paddingRight: 20 }}>{event.title}</div>

      {/* Date / time */}
      <div style={{ marginBottom: 6, color: '#374151' }}>
        <span style={{ fontSize: 12 }}>📅 </span>
        {formatDateLong(event.event_date)}
        {event.event_time_from && (
          <span style={{ marginLeft: 6, color: '#6b7280' }}>
            {formatTime(event.event_time_from)}{event.event_time_to ? '–' + formatTime(event.event_time_to) : ''}
          </span>
        )}
      </div>

      {/* Customer (works only) */}
      {!isTask && event.customer_title && (
        <div style={{ marginBottom: 6, color: '#374151' }}>
          <span style={{ fontSize: 12 }}>👤 </span>{event.customer_title}
        </div>
      )}

      {/* Work reference (tasks only) */}
      {isTask && event.work_title && (
        <div style={{ marginBottom: 6, color: '#374151' }}>
          <span style={{ fontSize: 12 }}>📁 </span>{event.work_title}
        </div>
      )}

      {/* Badges row */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 12, marginTop: 8 }}>
        {kanban && (
          <span style={{ background: kanban.bg, color: '#fff', borderRadius: 99, padding: '2px 8px', fontSize: 11, fontWeight: 600 }}>
            {kanbanLabel(event.kanban_status)}
          </span>
        )}
        {payment && (
          <span style={{ background: payment.bg, color: payment.color, borderRadius: 99, padding: '2px 8px', fontSize: 11, fontWeight: 600 }}>
            {paymentLabel(event.payment_status)}
          </span>
        )}
        {event.gcal_event_id && (
          <span style={{ background: '#eff6ff', color: '#2563eb', borderRadius: 99, padding: '2px 8px', fontSize: 11, fontWeight: 600 }}>
            {event.gcal_entry_type === 'activity' ? __('GCal Activity ✓') : __('GCal ✓')}
          </span>
        )}
      </div>

      {/* Remove from GCal */}
      {gcalEnabled && event.gcal_event_id && (
        <div style={{ marginBottom: 8 }}>
          <Button
            variant="danger"
            size="sm"
            onClick={handleUnlink}
            disabled={unlinkMut.isPending}
            style={{ width: '100%' }}
          >
            {unlinkMut.isPending ? __('Removing…') : __('Remove from Google Calendar')}
          </Button>
        </div>
      )}

      {/* Open button (works only) */}
      {!isTask && (
        <Button
          variant="primary"
          size="sm"
          onClick={() => { onClose(); onOpen(event.id) }}
          style={{ width: '100%' }}
        >
          {__('Open Work →')}
        </Button>
      )}
    </div>
  )
}

// ---------------------------------------------------------------------------
// Main CalendarView
// ---------------------------------------------------------------------------

export function CalendarView() {
  const navigate = useNavigate()
  const now      = new Date()
  const [year, setYear]       = useState(now.getFullYear())
  const [month, setMonth]     = useState(now.getMonth())
  const [syncMsg, setSyncMsg] = useState('')
  const [popup, setPopup]     = useState(null) // { event, anchorRef }

  const from = `${year}-${padTwo(month + 1)}-01`
  const to   = `${year}-${padTwo(month + 1)}-${padTwo(getDaysInMonth(year, month))}`

  const { data: events = [], isLoading } = useCalendarWorks(from, to)
  const syncAll    = useSyncAll()
  const gcalEnabled = !!(window.FotonicApp?.features?.gcal)

  const eventsByDate = {}
  for (const ev of events) {
    if (!ev.event_date) continue
    const d = parseInt(ev.event_date.slice(8, 10), 10)
    if (!eventsByDate[d]) eventsByDate[d] = []
    eventsByDate[d].push(ev)
  }

  function prevMonth() {
    if (month === 0) { setYear(y => y - 1); setMonth(11) }
    else setMonth(m => m - 1)
    setPopup(null)
  }
  function nextMonth() {
    if (month === 11) { setYear(y => y + 1); setMonth(0) }
    else setMonth(m => m + 1)
    setPopup(null)
  }

  async function handleSyncAll() {
    setSyncMsg('')
    try {
      const res = await syncAll.mutateAsync()
      setSyncMsg(`${__('Synced')} ${res.synced} ${res.synced !== 1 ? __('works') : __('work')}${res.errors ? `, ${res.errors} ${res.errors !== 1 ? __('errors') : __('error')}` : ''}.`)
    } catch {
      setSyncMsg(__('Sync failed. Try again.'))
    }
    setTimeout(() => setSyncMsg(''), 5000)
  }

  function handlePillClick(e, ev) {
    e.stopPropagation()
    const el = e.currentTarget
    const ref = { current: el }
    setPopup(prev => (prev?.event.id === ev.id && prev?.event.type === ev.type ? null : { event: ev, anchorRef: ref }))
  }

  const grid  = buildGrid(year, month)
  const today = now.getFullYear() === year && now.getMonth() === month ? now.getDate() : null

  return (
    <div style={{ padding: '1.5rem', fontFamily: 'inherit' }} onClick={() => setPopup(null)}>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '1.25rem' }}>
        <h2 style={{ margin: 0, fontSize: 22, fontWeight: 700, color: '#111827' }}>{__('Calendar')}</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          {gcalEnabled && (
            <Button
              variant="primary"
              size="sm"
              onClick={e => { e.stopPropagation(); handleSyncAll() }}
              disabled={syncAll.isPending}
            >
              {syncAll.isPending ? __('Syncing…') : __('↑ Sync to Google Calendar')}
            </Button>
          )}
        </div>
      </div>

      {syncMsg && (
        <div style={{ marginBottom: 12, padding: '8px 14px', background: '#f0fdf4', color: '#16a34a', borderRadius: 8, fontSize: 13 }}>
          {syncMsg}
        </div>
      )}

      {/* Month nav */}
      <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: '1rem' }}>
        <button onClick={e => { e.stopPropagation(); prevMonth() }} style={navBtnStyle}>‹</button>
        <span style={{ fontWeight: 700, fontSize: 17, color: '#111827', minWidth: 180, textAlign: 'center' }}>
          {monthLabel(year, month)}
        </span>
        <button onClick={e => { e.stopPropagation(); nextMonth() }} style={navBtnStyle}>›</button>
      </div>

      {/* Grid */}
      <div style={{ border: '1px solid #e5e7eb', borderRadius: 12, overflow: 'hidden' }}>
        {/* Day headers */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)', background: '#f9fafb' }}>
          {DAYS_KEYS.map(d => (
            <div key={d} style={{ padding: '8px 0', textAlign: 'center', fontSize: 12, fontWeight: 700, color: '#6b7280', borderBottom: '1px solid #e5e7eb' }}>
              {__(d)}
            </div>
          ))}
        </div>

        {/* Cells */}
        {isLoading ? (
          <div style={{ padding: '2rem', textAlign: 'center', color: '#9ca3af' }}>{__('Loading…')}</div>
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(7, 1fr)' }}>
            {grid.map((day, idx) => {
              const isToday  = day !== null && day === today
              const dayEvents = day !== null ? (eventsByDate[day] ?? []) : []
              return (
                <div
                  key={idx}
                  onClick={e => e.stopPropagation()}
                  style={{
                    minWidth: 0,
                    minHeight: 80,
                    padding: '6px 6px 4px',
                    borderRight:  (idx + 1) % 7 !== 0 ? '1px solid #f3f4f6' : 'none',
                    borderBottom: idx < grid.length - 7 ? '1px solid #f3f4f6' : 'none',
                    background:   day ? '#fff' : '#f9fafb',
                  }}
                >
                  {day !== null && (
                    <>
                      <div style={{
                        fontSize: 12,
                        fontWeight: isToday ? 700 : 400,
                        color: isToday ? '#2563eb' : '#374151',
                        marginBottom: 4,
                        width: 22,
                        height: 22,
                        borderRadius: '50%',
                        background: isToday ? '#eff6ff' : 'transparent',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                      }}>
                        {day}
                      </div>
                      {dayEvents.map(ev => (
                        <button
                          key={`${ev.type}-${ev.id}`}
                          onClick={e => handlePillClick(e, ev)}
                          title={ev.title + (ev.event_time_from ? ' · ' + formatTime(ev.event_time_from) : '')}
                          style={{
                            display: 'block',
                            width: '100%',
                            marginBottom: 2,
                            padding: '3px 5px',
                            background: ev.color || '#9ca3af',
                            color: '#fff',
                            border: (popup?.event.id === ev.id && popup?.event.type === ev.type) ? '2px solid rgba(0,0,0,0.3)' : '2px solid transparent',
                            borderRadius: 4,
                            cursor: 'pointer',
                            fontSize: 11,
                            textAlign: 'left',
                            lineHeight: 1.3,
                            opacity: ev.type === 'task' ? 0.88 : 1,
                          }}
                        >
                          <div style={{ overflow: 'hidden', wordBreak: 'break-word', whiteSpace: 'normal', fontWeight: 600 }}>
                            {ev.type === 'task' ? '· ' : ''}{ev.title}
                          </div>
                          {ev.event_time_from && (
                            <div style={{ opacity: 0.85, fontSize: 10, marginTop: 1 }}>
                              {formatTime(ev.event_time_from)}{ev.event_time_to ? '–' + formatTime(ev.event_time_to) : ''}
                            </div>
                          )}
                        </button>
                      ))}
                    </>
                  )}
                </div>
              )
            })}
          </div>
        )}
      </div>

      {/* Popup */}
      {popup && (
        <EventPopup
          event={popup.event}
          anchorRef={popup.anchorRef}
          onClose={() => setPopup(null)}
          onOpen={id => navigate('/works/' + id)}
          gcalEnabled={gcalEnabled}
        />
      )}
    </div>
  )
}

const navBtnStyle = {
  width: 32,
  height: 32,
  border: '1.5px solid #e5e7eb',
  background: '#fff',
  borderRadius: 8,
  cursor: 'pointer',
  fontSize: 18,
  color: '#374151',
  display: 'flex',
  alignItems: 'center',
  justifyContent: 'center',
  lineHeight: 1,
}
