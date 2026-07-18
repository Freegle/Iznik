import { describe, it, expect } from 'vitest'
import { buildIcs, escapeIcsText } from '~/composables/useCalendarEvent'

const event = {
  startDate: '2026-08-01',
  startTime: '14:30',
  endTime: '15:00',
  timeZone: 'Europe/London',
  name: 'Freegle handover: blue sofa',
  description: 'Meet at the door. Ring the bell, second flat.',
  location: '10 High Street, Anytown, AN1 2BC',
}
const now = new Date('2026-07-18T09:08:07.123Z')

describe('escapeIcsText', () => {
  it('escapes backslash, semicolon, comma and newlines per RFC 5545', () => {
    expect(escapeIcsText('a, b; c\\ d')).toBe('a\\, b\\; c\\\\ d')
    expect(escapeIcsText('line one\nline two')).toBe('line one\\nline two')
    expect(escapeIcsText('crlf\r\nhere')).toBe('crlf\\nhere')
  })

  it('handles null/undefined as empty', () => {
    expect(escapeIcsText(undefined)).toBe('')
    expect(escapeIcsText(null)).toBe('')
  })
})

describe('buildIcs', () => {
  const ics = buildIcs(event, { now })
  const lines = ics.split('\r\n')

  it('is CRLF-delimited and wrapped in VCALENDAR/VEVENT', () => {
    expect(ics).toContain('\r\n')
    expect(lines[0]).toBe('BEGIN:VCALENDAR')
    expect(lines).toContain('BEGIN:VEVENT')
    expect(lines).toContain('END:VEVENT')
    expect(lines[lines.length - 1]).toBe('END:VCALENDAR')
  })

  // The bug in 9927: Google Calendar rejected the file because the VEVENT had no UID and no
  // DTSTAMP. Both must be present.
  it('includes a UID and a UTC DTSTAMP (Google Calendar rejects files without them)', () => {
    expect(lines.some((l) => l.startsWith('UID:'))).toBe(true)
    expect(ics).toContain('DTSTAMP:20260718T090807Z')
  })

  it('writes DTSTART/DTEND with the event timezone and compact local times', () => {
    expect(ics).toContain('DTSTART;TZID=Europe/London:20260801T143000')
    expect(ics).toContain('DTEND;TZID=Europe/London:20260801T150000')
  })

  it('escapes the comma in SUMMARY, DESCRIPTION and LOCATION', () => {
    expect(ics).toContain('SUMMARY:Freegle handover: blue sofa') // ':' is not an escaped ICS char
    expect(ics).toContain('DESCRIPTION:Meet at the door. Ring the bell\\, second flat.')
    expect(ics).toContain('LOCATION:10 High Street\\, Anytown\\, AN1 2BC')
  })

  it('omits TZID when the event has no timezone (floating local time)', () => {
    const floating = buildIcs({ ...event, timeZone: undefined }, { now })
    expect(floating).toContain('DTSTART:20260801T143000')
    expect(floating).not.toContain('TZID')
  })
})
