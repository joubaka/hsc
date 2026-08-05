export const BOOKING_WEEKDAYS = Object.freeze([
  { title: 'Mon', value: 1 },
  { title: 'Tue', value: 2 },
  { title: 'Wed', value: 3 },
  { title: 'Thu', value: 4 },
  { title: 'Fri', value: 5 },
  { title: 'Sat', value: 6 },
  { title: 'Sun', value: 7 },
])

export function useBookingSeries() {
  const money = value => new Intl.NumberFormat('en-ZA', {
    style: 'currency',
    currency: 'ZAR',
  }).format((value || 0) / 100)

  const label = value => (value || '').replaceAll('_', ' ')

  return { label, money }
}
