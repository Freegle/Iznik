// Pure helpers for classifying and formatting digest-simulator posts.

// Bucket a post by its lifecycle state and return the marker colour, a
// short label, and which section of the digest mock-up it belongs in.
export function classifyPost(p) {
  if (p.successful)
    return { color: '#888', label: 'completed', section: 'completed' }
  if (p.promised)
    return { color: '#f39c12', label: 'promised', section: 'promised' }
  return p.home_group
    ? { color: '#27ae60', label: 'home group', section: 'active' }
    : { color: '#1f77b4', label: 'rippled in', section: 'active' }
}

// Resolve a post's thumbnail URL.  Uploadcare (externaluid) is preferred
// because it gives us a clean cropped CDN URL; the legacy attachment-id
// path falls back to the standard mimg endpoint.
export function thumbUrlFor(p) {
  if (p.thumb_externaluid)
    return `https://ucarecdn.com/${p.thumb_externaluid}/-/scale_crop/120x120/center/-/format/auto/-/quality/smart/`
  if (p.thumb_attachment_id)
    return `https://images.ilovefreegle.org/tmimg_${p.thumb_attachment_id}.jpg`
  return null
}

// Friendly relative time: "X min ago" / "X h ago" / absolute date.
export function formatTimeAgo(arrival) {
  const t = new Date(arrival).getTime()
  const ms = Date.now() - t
  const h = ms / 3600000
  if (h < 1) return `${Math.max(1, Math.round(ms / 60000))} min ago`
  if (h < 24) return `${Math.round(h)} h ago`
  return new Date(arrival).toLocaleString()
}

// Light HTML-escape for user-controlled strings injected into innerHTML.
export function escapeHTML(s) {
  return (s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}
