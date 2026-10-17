// What the SysAdmin UX tab is showing, kept in the address so a copied link restores it. The SysAdmin
// page mounts the tab afresh each time its UX pill is clicked and reads ?tab= only once, so state held
// in a component would be lost.
//
// Keys, all optional, and a default is left out of the address:
//   tab         always ux (the SysAdmin page's own key; this tab keeps it)
//   ux_section  a registered section, default overview
//   ux_period   7days, 30days, 90days or custom, default 7days
//   ux_from, ux_to   YYYY-MM-DD, only with custom
//   ux_device, ux_signed, ux_flow, ux_step, ux_open
//   ux_<name>   whatever a section lists in its `params` (see useUxSections)
import { computed } from 'vue'
import { uxSections } from '~/modtools/composables/useUxSections'

export const PERIODS = ['7days', '30days', '90days', 'custom']
export const DEVICES = [
  'web',
  'app',
  'mobile',
  'tablet',
  'desktop',
  'app-ios',
  'app-android',
]
export const SIGNED = ['in', 'out']

const MAX_RANGE_DAYS = 366
// The same pattern as the Go API's flowRe, so a key that passes here passes there.
const NAME_RE = /^[A-Za-z0-9_.:-]{1,64}$/
const OWNED_MAX = 80
const SEARCH_MAX = 60
// The one owned key that may hold any printable character, so a search for can't survives.
const FREE_TEXT_KEY = 'q'
const PREFIX = 'ux_'

// The keys every section has, apart from the ones it lists in `params`.
const FIXED = [
  'section',
  'period',
  'from',
  'to',
  'device',
  'signed',
  'flow',
  'step',
  'open',
]

const first = (v) => (Array.isArray(v) ? v[0] : v)
const str = (v) => (typeof first(v) === 'string' ? first(v) : '')

// A real calendar date written YYYY-MM-DD, as milliseconds, or null.
function dayMs(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s || '')
  if (!m) return null
  const ms = Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3]))
  const d = new Date(ms)
  const same =
    d.getUTCFullYear() === Number(m[1]) &&
    d.getUTCMonth() === Number(m[2]) - 1 &&
    d.getUTCDate() === Number(m[3])
  return same ? ms : null
}

// An owned key's value: characters outside [\w .:-] are dropped and it is cut at 80 characters. The
// search text is the exception: any printable character, trimmed and cut at 60.
function ownedValue(name, raw) {
  const v = str(raw)
  if (name === FREE_TEXT_KEY) {
    // The control characters are the ones to take out.
    // eslint-disable-next-line no-control-regex
    const printable = v.replace(/[\u0000-\u001f\u007f]/g, '')
    return printable.trim().slice(0, SEARCH_MAX)
  }
  return v.replace(/[^\w .:-]/g, '').slice(0, OWNED_MAX)
}

/**
 * Read the address strictly. An unknown value falls back to its default, and so is gone from the
 * address the next time the tab writes. `sections` is the registry (useUxSections).
 */
export function parseView(query = {}, sections = []) {
  const q = query || {}
  const wanted = str(q[PREFIX + 'section'])
  const active = sections.find((s) => s.key === wanted) ||
    sections.find((s) => s.key === 'overview') ||
    sections[0] || { key: 'overview', filters: [], params: [] }

  let period = PERIODS.includes(str(q[PREFIX + 'period']))
    ? str(q[PREFIX + 'period'])
    : '7days'
  let from = ''
  let to = ''
  if (period === 'custom') {
    let a = dayMs(str(q[PREFIX + 'from']))
    let b = dayMs(str(q[PREFIX + 'to']))
    if (a === null || b === null) {
      period = '7days'
    } else {
      if (a > b) [a, b] = [b, a]
      if ((b - a) / 86400000 + 1 > MAX_RANGE_DAYS) {
        period = '7days'
      } else {
        from = new Date(a).toISOString().slice(0, 10)
        to = new Date(b).toISOString().slice(0, 10)
      }
    }
  }

  const device = DEVICES.includes(str(q[PREFIX + 'device']))
    ? str(q[PREFIX + 'device'])
    : ''
  const signed = SIGNED.includes(str(q[PREFIX + 'signed']))
    ? str(q[PREFIX + 'signed'])
    : ''
  const name = (key) =>
    NAME_RE.test(str(q[PREFIX + key])) ? str(q[PREFIX + key]) : ''

  const uses = [...(active.filters || [])]
  const params = {}
  for (const key of active.params || []) {
    if (FIXED.includes(key)) continue
    const v = ownedValue(key, q[PREFIX + key])
    if (v) params[key] = v
  }

  const filterParams = {}
  if (uses.includes('device') && device) filterParams.device = device
  if (uses.includes('signed') && signed) {
    filterParams.signed_in = signed === 'in' ? '1' : '0'
  }

  return {
    section: active.key,
    period,
    from,
    to,
    device,
    signed,
    flow: name('flow'),
    step: name('step'),
    open: ownedValue('open', q[PREFIX + 'open']),
    uses,
    filtersActive: Object.keys(filterParams).length > 0,
    filterParams,
    extra: params,
  }
}

// The query string keys this tab writes, in a steady order, from a model of short names.
function toQuery(model) {
  const out = {}
  const put = (key, value, dflt = '') => {
    if (
      value !== undefined &&
      value !== null &&
      value !== '' &&
      value !== dflt
    ) {
      out[PREFIX + key] = String(value)
    }
  }
  put('section', model.section, 'overview')
  put('period', model.period, '7days')
  if (model.period === 'custom') {
    put('from', model.from)
    put('to', model.to)
  }
  put('device', model.device)
  put('signed', model.signed)
  put('flow', model.flow)
  put('step', model.step)
  put('open', model.open)
  for (const [key, value] of Object.entries(model.extra || {})) {
    put(key, value)
  }
  return out
}

const sameQuery = (a, b) => {
  const flat = (o) =>
    JSON.stringify(
      Object.entries(o || {})
        .map(([k, v]) => [k, Array.isArray(v) ? v.join(',') : String(v ?? '')])
        .sort()
    )
  return flat(a) === flat(b)
}

/**
 * The view as a computed over the address, a way to change it (`set`) and a way to get the address a
 * change would give without moving there (`hrefFor`). `route` and `router` default to the app's own.
 * `sections` is the registry, and is only given by tests.
 */
export function useUxView({ route, router, sections } = {}) {
  const r = route || useRoute()
  const rt = router || useRouter()
  const list = () => sections || uxSections()

  const view = computed(() => parseView(r.query, list()))

  // The address a patch would give. A patch holds short names (period, device, flow, step, open and
  // the section's own); undefined removes a key. Each rule clears only keys the patch did not set.
  function build(patch = {}) {
    const cur = view.value
    const model = {
      section: cur.section,
      period: cur.period,
      from: cur.from,
      to: cur.to,
      device: cur.device,
      signed: cur.signed,
      flow: cur.flow,
      step: cur.step,
      open: cur.open,
      extra: { ...cur.extra },
    }
    const set = new Set(Object.keys(patch))
    const left =
      list()
        .find((s) => s.key === model.section)
        ?.params?.filter((p) => !FIXED.includes(p)) || []

    for (const [key, value] of Object.entries(patch)) {
      if (FIXED.includes(key)) model[key] = value ?? ''
      else model.extra[key] = value
    }
    const clear = (...keys) => {
      for (const key of keys) {
        if (set.has(key)) continue
        if (FIXED.includes(key)) model[key] = ''
        else delete model.extra[key]
      }
    }

    const sectionChanged =
      patch.section !== undefined && patch.section !== cur.section
    if (sectionChanged) {
      clear('step', 'open', ...left)
      // A click on the nav row leaves the funnel behind. A link that names one keeps it.
      if (patch.section !== 'funnels') clear('flow')
    }
    if (patch.flow !== undefined && patch.flow !== cur.flow) clear('step')
    if (model.period !== 'custom') {
      model.from = ''
      model.to = ''
    }

    // Anything this tab does not own is kept as it is, and tab=ux is always there.
    const kept = {}
    for (const [k, v] of Object.entries(r.query || {})) {
      if (!k.startsWith(PREFIX) && k !== 'tab') kept[k] = v
    }
    return { tab: 'ux', ...kept, ...toQuery(model) }
  }

  function set(patch) {
    const query = build(patch)
    if (sameQuery(query, r.query)) return
    // replace, never push: for someone who arrived on a link, the first Back after a pushed query
    // change would send them to the home page (plugins/back-navigation.client.js).
    rt.replace({ query })
  }

  function hrefFor(patch) {
    const query = build(patch)
    if (typeof rt.resolve === 'function') return rt.resolve({ query }).href
    const qs = new URLSearchParams(query).toString()
    return `${r.path || ''}${qs ? '?' + qs : ''}`
  }

  return { view, set, hrefFor }
}
