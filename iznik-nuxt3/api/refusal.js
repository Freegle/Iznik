// The server sometimes refuses an action on purpose, with a 403 and a message written
// for the person: a moderator leaving a community they run, for instance (Discourse
// 10242). The API layer turns that into an APIError whose response.data.message is the
// server's text.
//
// Return that text so the screen can show it, or null for anything else. Only a 403
// with a message counts as a refusal; every other failure is a fault and must propagate.
export function asRefusalMessage(e) {
  if (e?.response?.status !== 403) return null

  const message = e.response.data?.message
  if (typeof message !== 'string' || !message.trim()) return null

  return message
}
