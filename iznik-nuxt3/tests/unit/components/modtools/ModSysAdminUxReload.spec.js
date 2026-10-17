// The tab while its reads are in flight, and after one has failed. A reload is the state people see
// every time they change a filter, and a unit test that answers every read at once cannot see it.
//
// What is real: the shell, the section list, the filter bar, the date filter, the nav, the panels and
// the store with its loading rules. What is replaced: the HTTP layer only. Each read is held until the
// test answers it, so a read stays in flight for as long as the test likes. The fake server behaves
// as the Go API does (iznik-server-go/ux/filters.go): it says which filters it used in `filters` and
// which it left alone in `ignored`.
import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUx from '~/modtools/components/ModSysAdminUx.vue'
import ModEmailDateFilter from '~/modtools/components/ModEmailDateFilter.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { OLD_SERVER_NOTE } from '~/modtools/composables/useUxFormat'
import { APIError } from '~/api/APIErrors'
import { uxStubs } from './uxStubs'

const held = vi.hoisted(() => {
  const calls = []
  const fetcher = (name) => (params) =>
    new Promise((resolve, reject) => {
      calls.push({ name, params: { ...params }, resolve, reject, done: false })
    })
  return {
    calls,
    fetchers: {
      fetchHealth: fetcher('health'),
      fetchFunnels: fetcher('funnels'),
      fetchOutcomes: fetcher('outcomes'),
      fetchFields: fetcher('fields'),
      fetchFriction: fetcher('friction'),
      fetchAdoption: fetcher('adoption'),
      fetchRegret: fetcher('regret'),
      fetchSearch: fetcher('search'),
      fetchSamples: fetcher('samples'),
    },
  }
})
vi.mock('~/api', () => ({ default: () => ({ ux: held.fetchers }) }))

// The address. router.replace applies on a later tick, as the real router does.
const route = reactive({ path: '/sysadmin', query: {} })
const replace = vi.fn(async (to) => {
  await Promise.resolve()
  route.query = to.query
})
vi.stubGlobal('useRoute', () => route)
vi.stubGlobal('useRouter', () => ({
  replace,
  push: vi.fn(),
  resolve: (to) => ({
    href: '/sysadmin?' + new URLSearchParams(to.query).toString(),
  }),
}))

// Bootstrap components as plain elements. The date filter is not stubbed.
const { ModEmailDateFilter: _stubbed, ...baseStubs } = uxStubs
const stubs = {
  ...baseStubs,
  ModSysAdminUxSamples: {
    template: '<div class="samples-stub" />',
    props: ['modelValue', 'flow', 'step', 'start', 'end'],
  },
  'b-card': { template: '<div class="card"><slot /></div>' },
  'b-form': {
    template:
      '<form @submit.prevent="$emit(\'submit\', $event)"><slot /></form>',
    emits: ['submit'],
  },
  'b-form-input': {
    template: '<input />',
    props: ['modelValue', 'type', 'max', 'size'],
  },
  'b-form-select': {
    template:
      '<select :id="id" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)">' +
      '<option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
    props: ['modelValue', 'id', 'options', 'size'],
    emits: ['update:modelValue'],
  },
}

const step = (name, over = {}) => ({
  step: name,
  entered: 100,
  reentered: 0,
  visits: 100,
  completed: 60,
  backed: 10,
  abandoned: 30,
  revisits: 5,
  validation_failures: 2,
  help_sought: 4,
  dwell_p50_ms: 8000,
  dwell_p90_ms: 45000,
  by: { device_class: [], logged_in: [], variant: [] },
  ...over,
})
const outcome = (flow, started) => ({
  flow,
  started,
  submitted: Math.round(started / 2),
  submit_ok: Math.round(started / 2),
  submit_failed: 0,
  completed: Math.round(started / 2),
  abandoned: Math.round(started / 3),
  resumed: 0,
})

// Which funnels have people in them. Changed by a test that wants the first funnel with starts to be
// something other than Offering.
let STARTS = { give: 220, ask: 0 }

const BODY = {
  health: () => ({ metrics: [] }),
  funnels: (p) => ({
    start: p.start,
    end: p.end,
    flows: [
      {
        flow: 'give',
        steps: STARTS.give
          ? [step('give'), step('give-mobile-photos', { visits: 130 })]
          : [],
      },
      {
        flow: 'ask',
        steps: STARTS.ask
          ? [step('ask'), step('ask-mobile-photos', { visits: 130 })]
          : [],
      },
    ],
    previous: { start: '2026-10-01', end: '2026-10-07', flows: [] },
  }),
  outcomes: () => ({
    flows: [outcome('give', STARTS.give), outcome('ask', STARTS.ask)],
    previous: { flows: [] },
  }),
  fields: () => ({ fields: [] }),
  friction: () => ({ components: [] }),
  adoption: () => ({ features: [] }),
  regret: () => ({ signals: [] }),
  search: () => ({ days: [] }),
}

// Which filters each read honours (section 4.2 of the spec, as filters.go implements it).
const HONOURS = {
  funnels: { device: true, signed_in: true },
  outcomes: { device: true },
  fields: { device: true },
  friction: { device: true },
}

// An answer from a server that is up to date: the figures, the filters it used, and the ones it left
// alone. `old` is a server from before the filters: it sends neither.
function answer(name, params, { old = false } = {}) {
  const honours = HONOURS[name] || {}
  const body = BODY[name](params)
  if (old) return body
  return {
    ...body,
    filters: {
      device: honours.device ? params.device || '' : '',
      signed_in: honours.signed_in ? params.signed_in || '' : '',
    },
    ignored: ['device', 'signed_in'].filter((k) => params[k] && !honours[k]),
  }
}

const failure = (name, status = 500) =>
  new APIError(
    {
      request: { path: `/modtools/ux/${name}`, method: 'GET' },
      response: { status, data: null },
    },
    `API Error GET /modtools/ux/${name} -> status: ${status}`
  )

const pending = () => held.calls.filter((c) => !c.done)
const readsOf = (name) => held.calls.filter((c) => c.name === name)

// Answer every read that is waiting, and any that the answers cause, until none is left. `fail`
// names the reads to fail, with the status to fail them with.
async function settle({ fail = {}, old = false } = {}) {
  for (let i = 0; i < 12; i++) {
    await flushPromises()
    const waiting = pending()
    if (!waiting.length) break
    for (const call of waiting) {
      call.done = true
      if (fail[call.name]) call.reject(failure(call.name, fail[call.name]))
      else call.resolve(answer(call.name, call.params, { old }))
    }
  }
  await flushPromises()
}

const notesIn = (w) => w.findAll('.ux-note').map((n) => n.text())
const titleIn = (w) => w.find('.ux-funnels .ux-panel > h4').text()
const pressedIn = (w) =>
  w.findAll('.ux-picker a[aria-pressed="true"] .name').map((n) => n.text())
const dangerIn = (w) => w.findAll('.notice-message.danger').map((n) => n.text())
const deviceSelect = (w) => w.find('select[id$="-device"]')
const signedSelect = (w) => w.find('select[id$="-signed"]')
const periodSelect = (w) => w.find('select[id$="-period"]')
const navLink = (w, label) => w.findAll('nav a').find((a) => a.text() === label)
const pressUpdate = (w) =>
  w.findComponent(ModEmailDateFilter).find('form').trigger('submit')

describe('the UX tab while its reads are in flight', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    held.calls.length = 0
    replace.mockClear()
    STARTS = { give: 220, ask: 0 }
  })

  const open = async (query, settleOptions) => {
    route.query = { tab: 'ux', ...query }
    const w = mount(ModSysAdminUx, { global: { stubs } })
    await settle(settleOptions)
    return w
  }

  describe('a changed filter', () => {
    it('says nothing about an old server on Pages while the reads are pending', async () => {
      const w = await open({ ux_section: 'pages' })
      expect(notesIn(w)).toEqual([])

      await deviceSelect(w).setValue('mobile')
      await flushPromises()

      // The reads for the new filter are out, and the slots still hold the earlier answers.
      expect(
        pending().some((c) => c.name === 'adoption' && c.params.device)
      ).toBe(true)
      expect(store.adoption.loading).toBe(true)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
      expect(notesIn(w)).toEqual([])
    })

    it('says what the server did with the filter once the answers arrive', async () => {
      const w = await open({ ux_section: 'pages' })
      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      await settle()

      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
      // Adoption, Regret and Search leave Device alone, and say so.
      expect(notesIn(w)).toContain(
        'Not filtered by device. Shows every device.'
      )
    })

    it('says nothing on Funnels while the reads are pending', async () => {
      const w = await open({ ux_section: 'funnels' })
      expect(notesIn(w)).toEqual([])

      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      expect(pending().some((c) => c.name === 'funnels')).toBe(true)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)

      await settle()
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
    })

    it('says nothing on Overview while the reads are pending', async () => {
      const w = await open({ ux_section: 'overview' })
      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)

      await settle()
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
    })

    it('says nothing when one device is changed to another', async () => {
      const w = await open({ ux_section: 'funnels', ux_device: 'mobile' })
      expect(notesIn(w)).toEqual([])
      expect(store.funnels.data.filters.device).toBe('mobile')

      await deviceSelect(w).setValue('tablet')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)

      await settle()
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
    })

    it('says nothing when Signed in is chosen on Funnels', async () => {
      const w = await open({ ux_section: 'funnels' })
      await signedSelect(w).setValue('in')
      await flushPromises()
      expect(
        pending().some(
          (c) => c.name === 'funnels' && c.params.signed_in === '1'
        )
      ).toBe(true)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)

      await settle()
      // Outcomes leave Signed in alone, and the tab says so in its own words.
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
      expect(notesIn(w)).toContain(
        'Started and finished count everyone. Signed in changes the steps only.'
      )
    })

    it('says nothing when only the period changes', async () => {
      const w = await open({ ux_section: 'funnels', ux_device: 'mobile' })
      await periodSelect(w).setValue('30days')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(notesIn(w)).toEqual([])
      await settle()
      expect(notesIn(w)).toEqual([])
    })

    it('says nothing while Update asks again with the same filters', async () => {
      const w = await open({ ux_section: 'pages', ux_device: 'mobile' })
      const notes = notesIn(w)
      await pressUpdate(w)
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
      await settle()
      expect(notesIn(w)).toEqual(notes)
    })

    it('still warns about a server that really does not say which filters it used', async () => {
      const w = await open(
        { ux_section: 'pages', ux_device: 'mobile' },
        { old: true }
      )
      expect(w.text()).toContain(OLD_SERVER_NOTE)
    })

    it('warns about it after a change too, once the old server has answered', async () => {
      const w = await open({ ux_section: 'pages' }, { old: true })
      expect(notesIn(w)).toEqual([])
      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      expect(w.text()).not.toContain(OLD_SERVER_NOTE)
      await settle({ old: true })
      expect(w.text()).toContain(OLD_SERVER_NOTE)
    })
  })

  describe('the funnel that is open', () => {
    // Only Asking has people in it, so the first funnel with starts is not Offering.
    beforeEach(() => {
      STARTS = { give: 0, ask: 220 }
    })

    it('opens Asking, the first funnel with starts, and leaves the address alone', async () => {
      const w = await open({ ux_section: 'funnels' })
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])
      expect(route.query.ux_flow).toBeUndefined()
    })

    it('stays on Asking while the reads reload for a Device', async () => {
      const w = await open({ ux_section: 'funnels' })
      await deviceSelect(w).setValue('mobile')
      await flushPromises()

      expect(
        pending().some(
          (c) => c.name === 'funnels' && c.params.device === 'mobile'
        )
      ).toBe(true)
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])

      await settle()
      expect(titleIn(w)).toBe('Asking for something')
    })

    it('stays on Asking while the reads reload for Signed in', async () => {
      const w = await open({ ux_section: 'funnels' })
      await signedSelect(w).setValue('in')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])
      await settle()
    })

    it('stays on Asking while the reads reload for a period', async () => {
      const w = await open({ ux_section: 'funnels' })
      await periodSelect(w).setValue('30days')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])
      await settle()
    })

    it('stays on Asking while Update asks again', async () => {
      const w = await open({ ux_section: 'funnels' })
      await pressUpdate(w)
      await flushPromises()
      expect(pending().some((c) => c.name === 'funnels')).toBe(true)
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])
      await settle()
      expect(titleIn(w)).toBe('Asking for something')
    })

    it('moves to the new first funnel with starts once the answers say it changed', async () => {
      const w = await open({ ux_section: 'funnels' })
      STARTS = { give: 300, ask: 0 }
      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      // Until the answers are in, the funnel on screen is the one the earlier answers chose.
      expect(titleIn(w)).toBe('Asking for something')
      await settle()
      expect(titleIn(w)).toBe('Offering something')
    })

    it('opens the funnel the address names, whatever is loading', async () => {
      const w = await open({ ux_section: 'funnels', ux_flow: 'ask' })
      await deviceSelect(w).setValue('mobile')
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(titleIn(w)).toBe('Asking for something')
      expect(pressedIn(w)).toEqual(['Asking'])
      await settle()
      expect(titleIn(w)).toBe('Asking for something')
    })

    it('opens Offering before anything has loaded', async () => {
      route.query = { tab: 'ux', ux_section: 'funnels' }
      const w = mount(ModSysAdminUx, { global: { stubs } })
      await flushPromises()
      expect(pending().length).toBeGreaterThan(0)
      expect(titleIn(w)).toBe('Offering something')
      await settle()
      expect(titleIn(w)).toBe('Asking for something')
    })
  })

  describe('a read that failed', () => {
    it('is asked again when the person opens another section', async () => {
      const w = await open(
        { ux_section: 'overview' },
        { fail: { funnels: 500 } }
      )
      expect(store.funnels.error).toBe(
        'API Error GET /modtools/ux/funnels -> status: 500'
      )
      expect(dangerIn(w).join(' ')).toContain('status: 500')
      const before = readsOf('funnels').length

      await navLink(w, 'Funnels').trigger('click')
      await settle()

      expect(route.query.ux_section).toBe('funnels')
      expect(readsOf('funnels').length - before).toBe(1)
      expect(store.funnels.error).toBeNull()
      expect(store.funnels.data).not.toBeNull()
      expect(dangerIn(w)).toEqual([])
    })

    it('keeps the dates and filters it was asked with, for the field figures', async () => {
      const w = await open(
        { ux_section: 'overview', ux_device: 'mobile' },
        { fail: { funnels: 500 } }
      )
      const asked = readsOf('funnels')[0].params
      expect(asked.device).toBe('mobile')

      await navLink(w, 'Funnels').trigger('click')
      await settle()

      // The fields read goes out once, with the dates of the reads it sits beside.
      const fields = readsOf('fields')
      expect(fields.length).toBeGreaterThan(0)
      for (const f of fields) {
        expect(f.params.start).toBe(asked.start)
        expect(f.params.end).toBe(asked.end)
        expect(f.params.device).toBe('mobile')
      }
    })

    it('is not asked again for a read that succeeded', async () => {
      const w = await open({ ux_section: 'overview' })
      const before = held.calls.length

      await navLink(w, 'Funnels').trigger('click')
      await settle()

      const shared = held.calls
        .slice(before)
        .filter((c) => ['health', 'funnels', 'outcomes'].includes(c.name))
      expect(shared).toHaveLength(0)
    })

    it('is not asked again when the route is missing, which asking again cannot fix', async () => {
      const w = await open(
        { ux_section: 'overview' },
        { fail: { funnels: 404 } }
      )
      expect(store.funnels.missing).toBe(true)
      const before = readsOf('funnels').length

      await navLink(w, 'Funnels').trigger('click')
      await settle()

      expect(readsOf('funnels').length - before).toBe(0)
      expect(store.funnels.missing).toBe(true)
    })

    it('is still asked again by Update and by a change of filter', async () => {
      const w = await open(
        { ux_section: 'overview' },
        { fail: { funnels: 500 } }
      )
      let before = readsOf('funnels').length
      await pressUpdate(w)
      await settle()
      expect(readsOf('funnels').length - before).toBe(1)
      expect(store.funnels.error).toBeNull()

      before = readsOf('funnels').length
      await deviceSelect(w).setValue('mobile')
      await settle()
      expect(readsOf('funnels').length - before).toBe(1)
    })
  })
})
