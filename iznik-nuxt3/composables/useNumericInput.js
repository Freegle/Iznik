// Coerce a form value to a number for a numeric JSON API field, or null when blank/invalid.
//
// bootstrap-vue-next's `<b-form-input type="number">` emits its model value as a STRING, and the
// Go API types numeric fields (a group's centre lat/lng, altlat/altlng, ...) as `*float64`. A
// JSON string in one of those fields fails Fiber's BodyParser, so the WHOLE PATCH is rejected
// with 400 and nothing in that request saves - which is why a new group's centre point silently
// never stuck while the CGA (a string field, sent in a separate request) did (Discourse 9932).
//
// Returning null for '', null, undefined or a non-numeric value means the caller can omit the
// field (the API treats a missing field as "leave unchanged") rather than send an unparseable
// string or a stray 0 that would move the point to the Atlantic.
export function toNumberOrNull(value) {
  if (value === '' || value === null || value === undefined) {
    return null
  }
  const n = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(n) ? n : null
}
