import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { h, reactive, ref, nextTick } from 'vue'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUx from '~/modtools/components/ModSysAdminUx.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

// The tab is generic: it draws whatever the registry lists. These tests use a registry of five
// small sections, so they check the shell and not the real sections (which have their own specs).
vi.mock('~/modtools/composables/useUxSections', () => {
  const draw = (name) => ({
    props: ['view'],
    emits: ['navigate'],
    template: `<div class="sec sec-${name}">${name}</div>`,
  })
  const SECTIONS = [
    {
      key: 'overview',
      label: 'Overview',
      order: 10,
      component: draw('overview'),
      filters: ['period', 'device'],
      slots: ['health', 'funnels', 'outcomes'],
      params: ['open'],
    },
    {
      key: 'funnels',
      label: 'Funnels',
      order: 20,
      component: draw('funnels'),
      filters: ['period', 'device', 'signed'],
      slots: ['health', 'funnels', 'outcomes'],
      params: ['step', 'open'],
      funnelLink: undefined,
    },
    {
      key: 'things',
      label: 'Things',
      order: 30,
      component: draw('things'),
      filters: [],
      slots: [],
      params: ['q'],
      funnelLink: { label: 'Things' },
    },
    {
      key: 'broken',
      label: 'Broken',
      order: 40,
      component: {
        props: ['view'],
        setup() {
          throw new Error('this section cannot be drawn')
        },
        template: '<div class="sec-broken" />',
      },
      filters: [],
      slots: [],
      params: [],
    },
    {
      key: 'pages',
      label: 'Pages and features',
      order: 90,
      component: draw('pages'),
      filters: ['period', 'device'],
      slots: ['health', 'friction'],
      params: ['open'],
    },
  ]
  return {
    SECTIONS,
    HOSTED: { problem: [], experiment: [] },
    uxSections: () => [...SECTIONS].sort((a, b) => a.order - b.order),
    hostedFor: () => [],
  }
})

const route = reactive({ path: '/sysadmin', query: {} })
const replace = vi.fn((to) => {
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

// The date filter draws what is put in its extra-filters slot, and sends dates when it is told to.
const stubs = {
  ...uxStubs,
  ModEmailDateFilter: {
    name: 'DateFilterStub',
    template: '<div class="date-filter"><slot name="extra-filters" /></div>',
    props: [
      'loading',
      'fetchLabel',
      'defaultPreset',
      'presets',
      'daily',
      'defaultStart',
      'defaultEnd',
    ],
    emits: ['fetch'],
  },
  'b-form-select': {
    template:
      '<select :id="id" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)">' +
      '<option v-for="o in options" :key="o.value" :value="o.value">{{ o.text }}</option></select>',
    props: ['modelValue', 'id', 'options', 'size'],
    emits: ['update:modelValue'],
  },
}

const DATES = {
  start: '2026-10-08',
  end: '2026-10-14',
  preset: '7days',
  lokiRange: '7d',
}

describe('ModSysAdminUx', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    vi.spyOn(store, 'loadSlots').mockResolvedValue()
    vi.spyOn(store, 'forget')
    route.query = { tab: 'ux' }
    replace.mockClear()
  })

  const make = async (query = { tab: 'ux' }, config = {}) => {
    route.query = query
    const w = mount(ModSysAdminUx, { global: { stubs, config } })
    await nextTick()
    return w
  }
  const send = async (w, dates = DATES) => {
    w.findComponent({ name: 'DateFilterStub' }).vm.$emit('fetch', dates)
    await nextTick()
  }
  const links = (w) => w.findAll('nav a')

  it('keeps itself out of the UX instrumentation', async () => {
    expect((await make()).attributes('data-ux-ignore')).toBeDefined()
  })

  describe('the section row', () => {
    it('lists the sections in the order of their `order`, with the open one marked', async () => {
      const w = await make()
      expect(links(w).map((a) => a.text())).toEqual([
        'Overview',
        'Funnels',
        'Things',
        'Broken',
        'Pages and features',
      ])
      expect(links(w).map((a) => a.attributes('aria-current'))).toEqual([
        'page',
        undefined,
        undefined,
        undefined,
        undefined,
      ])
    })

    it('opens the section the address names', async () => {
      const w = await make({ tab: 'ux', ux_section: 'pages' })
      expect(w.find('.sec-pages').exists()).toBe(true)
      expect(links(w)[4].attributes('aria-current')).toBe('page')
    })

    it('opens Overview for a section it does not know, and takes the key out of the address', async () => {
      const w = await make({ tab: 'ux', ux_section: 'bogus' })
      expect(w.find('.sec-overview').exists()).toBe(true)
      expect(replace).toHaveBeenCalledWith({ query: { tab: 'ux' } })
    })

    it('writes the address when a section is clicked, and replaces rather than pushes', async () => {
      const w = await make()
      await links(w)[4].trigger('click')
      expect(replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_section: 'pages' },
      })
      expect(w.find('.sec-pages').exists()).toBe(true)
    })

    it('mounts only the open section', async () => {
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      expect(w.findAll('.sec')).toHaveLength(1)
      expect(w.find('.sec-funnels').exists()).toBe(true)
    })

    it('makes each a real link to its section', async () => {
      const w = await make()
      expect(links(w)[2].attributes('href')).toBe(
        '/sysadmin?tab=ux&ux_section=things'
      )
    })
  })

  describe('at setup', () => {
    it('makes sure the address has tab=ux', async () => {
      await make({})
      expect(replace).toHaveBeenCalledWith({ query: { tab: 'ux' } })
    })

    it('leaves a good address alone', async () => {
      await make({ tab: 'ux', ux_device: 'mobile' })
      expect(replace).not.toHaveBeenCalled()
    })

    it('forgets what the store held from the last time the tab was open', async () => {
      await make()
      expect(store.forget).toHaveBeenCalled()
    })

    it('loads nothing before the date filter has said which dates', async () => {
      await make()
      expect(store.loadSlots).not.toHaveBeenCalled()
    })
  })

  describe('the filter bar', () => {
    // v-show only sets display: none, so read the style.
    const hidden = (wrapper) => wrapper.element.style.display === 'none'
    const bar = (w) => w.findComponent({ name: 'ModSysAdminUxFilters' })

    it('shows for a section that uses filters', async () => {
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      expect(hidden(bar(w))).toBe(false)
      expect(hidden(w.find('.ux-pillar-note'))).toBe(true)
    })

    it('is hidden, and a note says why, for a section with none', async () => {
      const w = await make({ tab: 'ux', ux_section: 'things' })
      expect(hidden(bar(w))).toBe(true)
      const note = w.find('.ux-pillar-note')
      expect(hidden(note)).toBe(false)
      expect(note.text()).toBe(
        'This section shows everything so far. The period, device and sign-in filters do not apply here.'
      )
    })

    it('stays mounted when hidden, so the date filter still works out the dates', async () => {
      const w = await make({ tab: 'ux', ux_section: 'things' })
      expect(w.findComponent({ name: 'DateFilterStub' }).exists()).toBe(true)
    })

    it('shows Signed in on Funnels and not on Overview', async () => {
      const funnels = await make({ tab: 'ux', ux_section: 'funnels' })
      expect(funnels.text()).toContain('Signed in:')
      const overview = await make({ tab: 'ux' })
      expect(overview.text()).not.toContain('Signed in:')
    })
  })

  describe('loading what the open section shows', () => {
    it("loads the section's slots with the dates and the filters it uses", async () => {
      const w = await make({ tab: 'ux', ux_device: 'mobile' })
      await send(w)
      expect(store.loadSlots).toHaveBeenLastCalledWith(
        ['health', 'funnels', 'outcomes'],
        {
          start: '2026-10-08',
          end: '2026-10-14',
          device: 'mobile',
        }
      )
    })

    it('loads a different list of slots for a different section', async () => {
      const w = await make({ tab: 'ux', ux_section: 'pages' })
      await send(w)
      expect(store.loadSlots.mock.calls.at(-1)[0]).toEqual([
        'health',
        'friction',
      ])
    })

    it('loads nothing for a section that loads its own data', async () => {
      const w = await make({ tab: 'ux', ux_section: 'things' })
      await send(w)
      expect(store.loadSlots.mock.calls.at(-1)[0]).toEqual([])
    })

    it('writes the period in the address when the person chooses one', async () => {
      const w = await make()
      await send(w, {
        start: '2026-09-15',
        end: '2026-10-14',
        preset: '30days',
      })
      expect(replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_period: '30days' },
      })
    })

    it('writes a custom range in the address', async () => {
      const w = await make()
      await send(w, {
        start: '2026-09-01',
        end: '2026-09-30',
        preset: 'custom',
      })
      expect(replace).toHaveBeenLastCalledWith({
        query: {
          tab: 'ux',
          ux_period: 'custom',
          ux_from: '2026-09-01',
          ux_to: '2026-09-30',
        },
      })
    })

    it('does not write the address for the period it is already on', async () => {
      const w = await make()
      await send(w)
      expect(replace).not.toHaveBeenCalled()
    })

    it('forgets what the store holds when the dates arrive, so Update asks the server again', async () => {
      const w = await make()
      await send(w)
      store.forget.mockClear()
      await send(w)
      expect(store.forget).toHaveBeenCalledTimes(1)
      expect(store.loadSlots).toHaveBeenCalledTimes(2)
    })

    it('reloads and writes ux_device when Device changes', async () => {
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      await send(w)
      store.loadSlots.mockClear()
      await w.findAll('select')[0].setValue('mobile')
      await flushPromises()
      expect(replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_section: 'funnels', ux_device: 'mobile' },
      })
      expect(store.loadSlots).toHaveBeenLastCalledWith(
        ['health', 'funnels', 'outcomes'],
        {
          start: '2026-10-08',
          end: '2026-10-14',
          device: 'mobile',
        }
      )
    })

    it('takes a filter off with the reset link', async () => {
      const w = await make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_device: 'app',
        ux_signed: 'in',
      })
      await send(w)
      await w.find('.ux-filters p button').trigger('click')
      expect(replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_section: 'funnels' },
      })
    })

    describe('with Signed in left in the address', () => {
      const query = { tab: 'ux', ux_signed: 'in' }

      it('does not send it on Overview, and does not print it', async () => {
        const w = await make(query)
        await send(w)
        expect(store.loadSlots).toHaveBeenLastCalledWith(
          ['health', 'funnels', 'outcomes'],
          { start: '2026-10-08', end: '2026-10-14' }
        )
        expect(w.find('.ux-summary').text()).not.toContain('Signed in only')
      })

      it('does not send it on Pages, and does not print it', async () => {
        const w = await make({ ...query, ux_section: 'pages' })
        await send(w)
        expect(store.loadSlots.mock.calls.at(-1)[1]).toEqual({
          start: '2026-10-08',
          end: '2026-10-14',
        })
        expect(w.find('.ux-summary').text()).not.toContain('Signed in only')
      })

      it('sends it on Funnels, as 1, and says so', async () => {
        const w = await make({ ...query, ux_section: 'funnels' })
        await send(w)
        expect(store.loadSlots).toHaveBeenLastCalledWith(
          ['health', 'funnels', 'outcomes'],
          { start: '2026-10-08', end: '2026-10-14', signed_in: '1' }
        )
        expect(w.find('.ux-summary').text()).toContain('Signed in only.')
      })

      it('keeps it in the address when the person moves between sections', async () => {
        const w = await make({ ...query, ux_section: 'pages' })
        await links(w)[1].trigger('click')
        expect(replace).toHaveBeenLastCalledWith({
          query: { tab: 'ux', ux_section: 'funnels', ux_signed: 'in' },
        })
      })
    })
  })

  describe('the summary line', () => {
    it('prints the dates the server gave, once they have loaded', async () => {
      const w = await make()
      await send(w)
      store.funnels.data = {
        start: '2026-10-08',
        end: '2026-10-14',
        previous: { start: '2026-10-01', end: '2026-10-07' },
        flows: [],
      }
      await nextTick()
      expect(w.find('.ux-summary').text()).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC). Compared with 1 Oct to 7 Oct.'
      )
    })

    it('prints the dates that were asked for before anything has loaded', async () => {
      const w = await make()
      await send(w)
      expect(w.find('.ux-summary').text()).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC).'
      )
    })

    it('does not print the dates of an answer that was asked for other dates', async () => {
      const w = await make()
      await send(w)
      store.funnels.data = { start: '2026-09-01', end: '2026-09-07', flows: [] }
      await nextTick()
      expect(w.find('.ux-summary').text()).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC).'
      )
    })
  })

  describe('the health banner', () => {
    const failing = {
      metrics: [
        {
          metric: 'impressions',
          ok: false,
          days: [{ date: 'a', ratio: 0.4, ok: false }],
        },
      ],
    }

    it('shows on a section that draws figures, naming the failing checks', async () => {
      store.health.data = failing
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      const banner = w.find('.notice-message.danger')
      expect(banner.text()).toContain('Not delivering as expected: impressions')
      expect(banner.text().toLowerCase()).toContain('instrumentation')
    })

    it('names the failing checks in words, not by the names the batch stores', async () => {
      store.health.data = {
        metrics: [
          { metric: 'flow_start:give', ok: false, days: [] },
          { metric: 'event:interaction', ok: false, days: [] },
          { metric: 'event:page_view', ok: true, days: [] },
        ],
      }
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      const text = w.find('.notice-message.danger').text()
      expect(text).toContain(
        'Not delivering as expected: Starts of Offering something, Clicks and scrolls.'
      )
      expect(text).not.toMatch(/flow_start|event:|flow/)
    })

    it('shows on Pages too', async () => {
      store.health.data = failing
      const w = await make({ tab: 'ux', ux_section: 'pages' })
      expect(w.find('.notice-message.danger').exists()).toBe(true)
    })

    it('does not show on Overview, which has the health row itself', async () => {
      store.health.data = failing
      const w = await make()
      expect(w.find('.notice-message.danger').exists()).toBe(false)
    })

    it('does not show on a section that loads its own data', async () => {
      store.health.data = failing
      const w = await make({ tab: 'ux', ux_section: 'things' })
      expect(w.find('.notice-message.danger').exists()).toBe(false)
    })

    it('does not show while every check is fine', async () => {
      store.health.data = {
        metrics: [{ metric: 'flow_events', ok: true, days: [] }],
      }
      const w = await make({ tab: 'ux', ux_section: 'funnels' })
      expect(w.find('.notice-message.danger').exists()).toBe(false)
    })
  })

  describe('a section that cannot be drawn', () => {
    // The app's error handler is where Sentry and the page's own handler are wired in. An error the
    // tab swallowed would never reach either, and the person would only see a notice.
    //
    // Vue Test Utils re-throws any error that reaches the app handler while it is mounting, which the
    // tab does not do. So each test mounts a section that draws and then moves to the one that
    // does not, as a click does.
    const handler = () => ({ errorHandler: vi.fn() })
    const openBroken = async (config) => {
      const w = await make({ tab: 'ux' }, config)
      await links(w)
        .find((a) => a.text() === 'Broken')
        .trigger('click')
      await nextTick()
      return w
    }

    it('says so and does not stop the nav or the filters', async () => {
      const w = await openBroken(handler())
      expect(w.find('.notice-message.danger').text()).toContain(
        'could not be drawn'
      )
      expect(links(w)).toHaveLength(5)
      expect(w.findComponent({ name: 'DateFilterStub' }).exists()).toBe(true)
    })

    it('passes the error on to the app error handler, so it is reported', async () => {
      const config = handler()
      await openBroken(config)
      expect(config.errorHandler).toHaveBeenCalledTimes(1)
      const [error] = config.errorHandler.mock.calls[0]
      expect(error.message).toBe('this section cannot be drawn')
    })

    it('reports nothing for a section that draws', async () => {
      const config = handler()
      const w = await make({ tab: 'ux' }, config)
      await links(w)[1].trigger('click')
      await nextTick()
      expect(config.errorHandler).not.toHaveBeenCalled()
    })

    it('lets another section be opened afterwards', async () => {
      const w = await openBroken(handler())
      await links(w)[0].trigger('click')
      await nextTick()
      expect(w.find('.sec-overview').exists()).toBe(true)
      expect(w.find('.notice-message.danger').exists()).toBe(false)
    })

    describe('a fault in the nav or the filter bar', () => {
      // They are the tab's own furniture. The open section drew, so it stays, and the fault is
      // reported like any other. This one draws until it is told to fail, as a real fault would
      // appear after the tab is up.
      const breakable = () => {
        const failing = ref(false)
        return {
          failing,
          component: {
            render() {
              if (failing.value) throw new Error('the furniture broke')
              return h('span')
            },
          },
        }
      }

      it('is reported, and the open section is not replaced by the notice', async () => {
        const { SECTIONS } =
          await import('~/modtools/composables/useUxSections')
        const part = breakable()
        SECTIONS[0].badge = part.component
        try {
          const config = handler()
          const w = await make({ tab: 'ux' }, config)
          part.failing.value = true
          await nextTick()

          expect(config.errorHandler).toHaveBeenCalledTimes(1)
          expect(config.errorHandler.mock.calls[0][0].message).toBe(
            'the furniture broke'
          )
          expect(w.find('.sec-overview').exists()).toBe(true)
          expect(w.find('.notice-message.danger').exists()).toBe(false)
        } finally {
          delete SECTIONS[0].badge
        }
      })

      it('is reported, and the open section is kept, when it is the filter bar', async () => {
        route.query = { tab: 'ux' }
        const part = breakable()
        const config = handler()
        const w = mount(ModSysAdminUx, {
          global: {
            stubs: { ...stubs, ModEmailDateFilter: part.component },
            config,
          },
        })
        await nextTick()
        part.failing.value = true
        await nextTick()

        expect(config.errorHandler).toHaveBeenCalledTimes(1)
        expect(w.find('.sec-overview').exists()).toBe(true)
        expect(w.find('.notice-message.danger').exists()).toBe(false)
      })
    })
  })

  describe('what it gives the sections', () => {
    it('lets a section get the address of a change, and offers the sections that want a link from a funnel', async () => {
      const seen = {}
      const Probe = {
        inject: ['uxHrefFor', 'uxFunnelLinks'],
        props: ['view'],
        template: '<div class="probe" />',
        created() {
          seen.href = this.uxHrefFor({ section: 'pages' })
          seen.links = this.uxFunnelLinks
        },
      }
      const { SECTIONS } = await import('~/modtools/composables/useUxSections')
      const original = SECTIONS[0].component
      SECTIONS[0].component = Probe
      try {
        await make()
        expect(seen.href).toBe('/sysadmin?tab=ux&ux_section=pages')
        expect(seen.links).toEqual([{ key: 'things', label: 'Things' }])
      } finally {
        SECTIONS[0].component = original
      }
    })
  })
})
