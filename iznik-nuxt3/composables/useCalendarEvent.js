// Build a calendar .ics (RFC 5545) from the structured event data used by AddToCalendar.
// Extracted as a pure function so the .ics - the part Google Calendar and other importers
// actually parse - can be unit tested. The previous inline version omitted UID and DTSTAMP and
// did not escape the text fields, so Google Calendar rejected the file with an error when a
// member tried to add a handover from the confirmation email (Discourse 9927).

// Escape a text value for an ICS property per RFC 5545 section 3.3.11: backslash, semicolon and
// comma are escaped, and any line break becomes the literal `\n`. Without this a description or
// location containing a comma (very common in an address) or a line break yields a malformed
// file that importers refuse.
export function escapeIcsText(value) {
  return String(value ?? '')
    .replace(/\\/g, '\\\\')
    .replace(/;/g, '\\;')
    .replace(/,/g, '\\,')
    .replace(/\r\n|\r|\n/g, '\\n')
}

// Compact a 'YYYY-MM-DD' date and 'HH:MM' time into the ICS local-time form YYYYMMDDTHHMMSS.
function compactLocal(date, time) {
  return `${String(date).replace(/-/g, '')}T${String(time).replace(/:/g, '')}00`
}

// Build the full VCALENDAR/VEVENT text.
//
// eventData: { startDate 'YYYY-MM-DD', startTime 'HH:MM', endTime 'HH:MM', timeZone, name,
//              description, location }
// opts.now: injectable Date for a deterministic DTSTAMP in tests.
//
// A VEVENT MUST carry a UID and a DTSTAMP (RFC 5545 3.6.1); Google Calendar rejects files
// without them. DTSTAMP is UTC. DTSTART/DTEND carry the event's own timezone via TZID (falling
// back to floating local time if none is given). The UID is derived from the event's start/end
// so re-importing the same handover updates one event rather than creating duplicates.
export function buildIcs(eventData, opts = {}) {
  const now = opts.now || new Date()
  const start = compactLocal(eventData.startDate, eventData.startTime)
  const end = compactLocal(eventData.startDate, eventData.endTime)
  const dtstamp = now.toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z')
  const uid = `freegle-${start}-${end}@ilovefreegle.org`
  const tz = eventData.timeZone

  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    'PRODID:-//Freegle//NONSGML Event//EN',
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    'BEGIN:VEVENT',
    `UID:${uid}`,
    `DTSTAMP:${dtstamp}`,
    tz ? `DTSTART;TZID=${tz}:${start}` : `DTSTART:${start}`,
    tz ? `DTEND;TZID=${tz}:${end}` : `DTEND:${end}`,
    `SUMMARY:${escapeIcsText(eventData.name)}`,
    `DESCRIPTION:${escapeIcsText(eventData.description)}`,
    `LOCATION:${escapeIcsText(eventData.location)}`,
    'END:VEVENT',
    'END:VCALENDAR',
  ].join('\r\n')
}
