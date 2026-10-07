// Conversion between the ISO time the API uses for an ADMIN's "send after" and the local
// "YYYY-MM-DDTHH:mm" string a datetime-local input uses. Empty means no send-after time.

function pad(n) {
  return String(n).padStart(2, '0')
}

export function sendAfterToInput(iso) {
  if (!iso) {
    return ''
  }

  const d = new Date(iso)

  if (isNaN(d.getTime())) {
    return ''
  }

  return (
    d.getFullYear() +
    '-' +
    pad(d.getMonth() + 1) +
    '-' +
    pad(d.getDate()) +
    'T' +
    pad(d.getHours()) +
    ':' +
    pad(d.getMinutes())
  )
}

// Returns an ISO string, or null to clear the send-after time.
export function inputToSendAfter(value) {
  if (!value) {
    return null
  }

  const d = new Date(value)
  return isNaN(d.getTime()) ? null : d.toISOString()
}
