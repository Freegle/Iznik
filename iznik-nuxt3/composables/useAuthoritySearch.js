// Dashboard Outcomes dates arrive as "YYYY-MM" (V1 PHP legacy) or as
// "YYYY-MM-DDT..." ISO datetimes (V2 Go API). dayjs happily parses both
// when we normalise to a plain "YYYY-MM-DD" string.
export function parseOutcomeDate(date) {
  if (!date || typeof date !== 'string') return ''
  if (date.length === 7) return date + '-01'
  return date.slice(0, 10)
}

// Header row for Google Charts weight/member series on the authority stats
// page. Explicit types prevent "axis #0 cannot be of type string" when
// the data rows are empty (anonymous visitors don't get ApprovedMemberCount).
export const DASHBOARD_CHART_HEADER = [
  { type: 'date', label: 'Date' },
  { type: 'number', label: 'Count' },
]

// True when the chart has at least one data row beyond the header.
export function hasChartDataRows(data) {
  return Array.isArray(data) && data.length > 1
}

export function normalizeAuthoritySearch(results, limit = 5) {
  // V2 Go API returns a bare array; V1 PHP returned { authorities: [...] }.
  const list = Array.isArray(results)
    ? results
    : Array.isArray(results?.authorities)
      ? results.authorities
      : []

  const trimmed = list.slice(0, limit)
  const ret = []
  for (const authority of trimmed) {
    if (authority && authority.name) {
      ret.push(authority)
    }
  }
  return ret
}
