export default [
  {
    title: 'Court bookings',
    to: { name: 'booking-calendar' },
    icon: { icon: 'tabler-calendar-event' },
  },
  {
    title: 'Members',
    to: { name: 'root' },
    icon: { icon: 'tabler-users' },
  },
  {
    title: 'Site & booking settings',
    to: { name: 'site-settings' },
    icon: { icon: 'tabler-adjustments-horizontal' },
  },
  {
    title: 'Membership',
    icon: { icon: 'tabler-id-badge-2' },
    children: [
      {
        title: 'Setup',
        to: { name: 'membership-setup' },
        icon: { icon: 'tabler-settings' },
      },
      {
        title: 'Periods',
        to: { name: 'membership-periods' },
        icon: { icon: 'tabler-calendar-stats' },
      },
      {
        title: 'Applications',
        to: { name: 'membership-applications' },
        icon: { icon: 'tabler-clipboard-check' },
      },
      {
        title: 'Renewals',
        to: { name: 'renewals' },
        icon: { icon: 'tabler-refresh' },
      },
    ],
  },
  {
    title: 'Booking administration',
    icon: { icon: 'tabler-calendar-cog' },
    children: [
      {
        title: 'Recurring bookings',
        to: { name: 'booking-series' },
        icon: { icon: 'tabler-calendar-repeat' },
      },
      {
        title: 'Public bookings',
        to: { name: 'public-bookings' },
        icon: { icon: 'tabler-world' },
      },
      {
        title: 'Facilities',
        to: { name: 'booking-facilities' },
        icon: { icon: 'tabler-building-stadium' },
      },
      {
        title: 'Court lighting',
        to: { name: 'lighting' },
        icon: { icon: 'tabler-bulb' },
      },
      {
        title: 'Check-in operations',
        to: { name: 'check-in-operations' },
        icon: { icon: 'tabler-door-enter' },
      },
      {
        title: 'Rule simulator',
        to: { name: 'booking-rule-simulator' },
        icon: { icon: 'tabler-test-pipe' },
      },
      {
        title: 'Booking refunds',
        to: { name: 'booking-refunds' },
        icon: { icon: 'tabler-receipt-refund' },
      },
    ],
  },
  {
    title: 'Finance',
    icon: { icon: 'tabler-wallet' },
    children: [
      {
        title: 'Invoices',
        to: { name: 'finance-invoices' },
        icon: { icon: 'tabler-file-invoice' },
      },
      {
        title: 'Payments',
        to: { name: 'finance-payments' },
        icon: { icon: 'tabler-cash' },
      },
      {
        title: 'Reports',
        to: { name: 'finance-reports' },
        icon: { icon: 'tabler-report-money' },
      },
      {
        title: 'Settings',
        to: { name: 'financial-settings' },
        icon: { icon: 'tabler-settings-dollar' },
      },
    ],
  },
  {
    title: 'Communication Centre',
    to: { name: 'communication-center' },
    icon: { icon: 'tabler-mail' },
  },
  {
    title: 'Club Championships',
    to: { name: 'club-championships' },
    icon: { icon: 'tabler-trophy' },
  },
]
