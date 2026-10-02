// Shared wording and formatting for the Partnerships page, so the list, the detail, the
// edit form and the timeline describe a deal the same way.

// The pipeline a deal moves along. Only the last three are shown to members.
export const STATUSES = [
  {
    value: 'Quoted',
    text: 'Quoted',
    variant: 'secondary',
    help: 'We have given them a price.',
  },
  {
    value: 'InPrinciple',
    text: 'Agreed in principle',
    variant: 'info',
    help: 'They have said yes, but not confirmed it.',
  },
  {
    value: 'Confirmed',
    text: 'Confirmed',
    variant: 'success',
    help: 'Confirmed. Members now see the sponsor.',
  },
  {
    value: 'Paid',
    text: 'Paid',
    variant: 'success',
    help: 'Confirmed and paid.',
  },
  {
    value: 'Overdue',
    text: 'Overdue',
    variant: 'danger',
    help: 'Confirmed, but the payment is late. Members still see the sponsor.',
  },
]

export const COMMITTED = ['Confirmed', 'Paid', 'Overdue']

export const RENEWALS = [
  { value: null, text: 'Not sure yet' },
  { value: 'Likely', text: 'Likely to renew', colour: 'green' },
  { value: 'Unsure', text: 'Might renew', colour: 'amber' },
  { value: 'Unlikely', text: 'Unlikely to renew', colour: 'red' },
]

export const CONTACT_ROLES = [
  { value: 'Waste', text: 'Waste team' },
  { value: 'Finance', text: 'Finance' },
  { value: 'Other', text: 'Other' },
]

// We ask about next year this long before a deal ends.
export const RENEWAL_ASK_MONTHS = 3

// When to ask a council about next year: three months before the deal ends. Months have
// different lengths, so 31 December gives 30 September rather than rolling on to 1 October.
export function renewalAskDate(enddate) {
  const end = new Date(enddate)
  const day = end.getDate()
  const ask = new Date(
    end.getFullYear(),
    end.getMonth() - RENEWAL_ASK_MONTHS,
    1
  )
  const lastDay = new Date(ask.getFullYear(), ask.getMonth() + 1, 0).getDate()
  ask.setDate(Math.min(day, lastDay))

  return ask
}

export function statusInfo(status) {
  return STATUSES.find((s) => s.value === status) || STATUSES[0]
}

export function renewalInfo(renewal) {
  return RENEWALS.find((r) => r.value === renewal) || RENEWALS[0]
}

export function isCommitted(status) {
  return COMMITTED.includes(status)
}

// Whole pounds by default; pence where the page is showing individual invoices.
export function formatMoney(v, pence = false) {
  return (parseFloat(v) || 0).toLocaleString('en-GB', {
    minimumFractionDigits: pence ? 2 : 0,
    maximumFractionDigits: pence ? 2 : 0,
  })
}

// "3 years", "1 year", "1 year 6 months" - how long a deal runs, rounded to whole months.
export function dealLength(startdate, enddate) {
  if (!startdate || !enddate || enddate < startdate) {
    return null
  }

  const start = new Date(startdate)
  const end = new Date(enddate)
  // Inclusive of the last day, so 1 Jan to 31 Dec is a year rather than 11 months.
  end.setDate(end.getDate() + 1)

  const months = Math.round(
    (end.getFullYear() - start.getFullYear()) * 12 +
      (end.getMonth() - start.getMonth()) +
      (end.getDate() - start.getDate()) / 30
  )

  if (months < 1) {
    return 'under a month'
  }

  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's')
  const years = Math.floor(months / 12)
  const rest = months % 12

  if (!years) {
    return plural(rest, 'month')
  }

  return rest
    ? plural(years, 'year') + ' ' + plural(rest, 'month')
    : plural(years, 'year')
}

// The bulk discount we gave, when the full price is recorded: "10% (£300)".
export function bulkDiscount(fullprice, amount) {
  const full = parseFloat(fullprice) || 0
  const paid = parseFloat(amount) || 0

  if (!full || full <= paid) {
    return null
  }

  const off = full - paid
  return Math.round((off / full) * 100) + '% (£' + formatMoney(off) + ')'
}

export function formatDate(d) {
  if (!d) {
    return ''
  }

  return new Date(d).toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}
