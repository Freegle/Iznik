// Pure helpers for classifying and formatting digest-simulator posts.

// Bucket a post by its lifecycle state and return the marker colour, a
// short label, and which section of the digest mock-up it belongs in.
export function classifyPost(p) {
  // has_success (a Taken/Received outcome) is the authoritative "came and went"
  // signal in the new simulator response; the legacy `successful` flag may not be
  // set on those rows, so check both.
  if (p.successful || p.has_success)
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

// Classify the swingometer reading against the area's deprivation baseline.
//
// `baselineReady` is false when the area baseline hasn't been measured for the
// current location yet (the fetch is pending or failed). In that case we must
// NOT present the national-average fallback as if it were this area's measured
// figure — return an explicit "unavailable" state so the UI can say so instead
// of showing a confident-but-wrong bias.
export function swingometerDisplay(pct, localBaseline, baselineReady) {
  if (!baselineReady) {
    return { ready: false, label: 'Area baseline unavailable', color: '#999' }
  }
  const lo = localBaseline - 8
  const hi = localBaseline + 8
  const label =
    pct < lo ? 'Affluent bias' : pct > hi ? 'Deprived bias' : 'Balanced'
  const color = pct < lo ? '#4477aa' : pct > hi ? '#d73027' : '#1a9850'
  const aboveBaseline = pct >= localBaseline
  const diff = Math.abs(pct - localBaseline)
  return { ready: true, label, color, aboveBaseline, diff }
}

// Split a digest-simulator response into the sections used to draw the map
// markers and the side pie. Consumes the NEW simulator contract:
//
//   * top_picks (aka selected) + deferred — the deduped "available" pool
//     (the real send's Top picks; the real digest has no separate Promised
//     section, so promised-but-no-outcome posts live in here)
//   * came_and_went                       — deduped Taken/Received posts
//
// Mutates each post with a 1-based `_rank` matching its global position in the
// ranked array (available → came-and-went). Inputs are not otherwise modified.
// `promised` is still surfaced as a slice for the side pie only.
export function partitionInboxData(data) {
  const available = [].concat(
    data.top_picks || data.selected || [],
    data.deferred || []
  )
  const completed = [].concat(data.came_and_went || [])
  const promised = available.filter((p) => p.promised)
  const active = available.filter((p) => !p.promised)
  const activeHome = active.filter((p) => p.home_group)
  const activeCross = active.filter((p) => !p.home_group)
  const taken = completed
  const ranked = available.concat(completed)
  ranked.forEach((p, i) => (p._rank = i + 1))
  return { ranked, active, activeHome, activeCross, promised, taken }
}
