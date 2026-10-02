import { describe, it, expect } from 'vitest'
import {
  bulkDiscount,
  dealLength,
  formatMoney,
  isCommitted,
  renewalAskDate,
  renewalInfo,
  statusInfo,
} from '~/modtools/composables/usePartnershipFormat'

describe('usePartnershipFormat', () => {
  it.each([
    ['2025-01-01', '2027-12-31', '3 years'],
    ['2026-04-01', '2027-03-31', '1 year'],
    ['2026-01-01', '2027-06-30', '1 year 6 months'],
    ['2026-01-01', '2026-01-31', '1 month'],
    ['2026-01-01', '2026-01-05', 'under a month'],
  ])('a deal from %s to %s lasts %s', (start, end, expected) => {
    expect(dealLength(start, end)).toBe(expected)
  })

  it('has no length without both dates, or when they are the wrong way round', () => {
    expect(dealLength('', '2026-01-01')).toBeNull()
    expect(dealLength('2027-01-01', '2026-01-01')).toBeNull()
  })

  it.each([
    ['2027-12-31', '2027-09-30'],
    ['2028-03-31', '2027-12-31'],
    ['2027-05-31', '2027-02-28'],
    ['2027-06-15', '2027-03-15'],
  ])('asks about renewing a deal ending %s by %s', (end, ask) => {
    const d = renewalAskDate(end)
    const got = [d.getFullYear(), d.getMonth() + 1, d.getDate()]
      .map((n) => String(n).padStart(2, '0'))
      .join('-')
    expect(got).toBe(ask)
  })

  it('works out the bulk discount from the full price', () => {
    expect(bulkDiscount(3000, 2700)).toBe('10% (£300)')
    expect(bulkDiscount(null, 2700)).toBeNull()
    expect(bulkDiscount(2700, 2700)).toBeNull()
  })

  it('formats money in whole pounds unless pence are asked for', () => {
    expect(formatMoney(9300)).toBe('9,300')
    expect(formatMoney('12.5', true)).toBe('12.50')
    expect(formatMoney(null)).toBe('0')
  })

  it('treats only confirmed, paid and overdue deals as committed', () => {
    expect(isCommitted('Quoted')).toBe(false)
    expect(isCommitted('InPrinciple')).toBe(false)
    expect(isCommitted('Confirmed')).toBe(true)
    expect(isCommitted('Paid')).toBe(true)
    expect(isCommitted('Overdue')).toBe(true)
  })

  it('describes statuses and renewal likelihood in plain words', () => {
    expect(statusInfo('InPrinciple').text).toBe('Agreed in principle')
    expect(statusInfo('Nonsense').value).toBe('Quoted')
    expect(renewalInfo('Unsure').colour).toBe('amber')
    expect(renewalInfo(null).text).toBe('Not sure yet')
  })
})
