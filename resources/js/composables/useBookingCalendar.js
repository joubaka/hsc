const JOHANNESBURG_TIME_ZONE = 'Africa/Johannesburg'

export const BOOKING_DAY_START = 6 * 60
export const BOOKING_DAY_END = 22 * 60
export const BOOKING_PIXELS_PER_MINUTE = 1.5
export const BOOKING_LANE_HEIGHT = (BOOKING_DAY_END - BOOKING_DAY_START) * BOOKING_PIXELS_PER_MINUTE
export const BOOKING_TIME_SLOTS = Array.from(
  { length: (BOOKING_DAY_END - BOOKING_DAY_START) / 30 },
  (_, index) => BOOKING_DAY_START + index * 30,
)

export function useBookingCalendar() {
  const money = (value, currency = 'ZAR') => new Intl.NumberFormat('en-ZA', {
    style: 'currency',
    currency,
  }).format((value || 0) / 100)

  const minutesToTime = minutes => `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`

  const timeToMinutes = value => {
    const [hours, minutes] = String(value).slice(0, 5).split(':').map(Number)

    return hours * 60 + minutes
  }

  const localDate = value => new Intl.DateTimeFormat('en-CA', {
    timeZone: JOHANNESBURG_TIME_ZONE,
  }).format(new Date(value))

  const localMinutes = value => {
    const parts = new Intl.DateTimeFormat('en-GB', {
      timeZone: JOHANNESBURG_TIME_ZONE,
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(new Date(value))

    return Number(parts.find(part => part.type === 'hour').value) * 60
      + Number(parts.find(part => part.type === 'minute').value)
  }

  const localDateTimeInput = value => {
    if (!value)
      return ''

    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: JOHANNESBURG_TIME_ZONE,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).formatToParts(new Date(value))

    const part = type => parts.find(row => row.type === type)?.value

    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`
  }

  const formatDay = value => new Intl.DateTimeFormat('en-ZA', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: JOHANNESBURG_TIME_ZONE,
  }).format(new Date(`${value}T12:00:00+02:00`))

  return {
    formatDay,
    localDate,
    localDateTimeInput,
    localMinutes,
    minutesToTime,
    money,
    timeToMinutes,
  }
}
