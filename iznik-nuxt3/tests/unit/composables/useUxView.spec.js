import { describe, it, expect, vi } from 'vitest'
import { reactive } from 'vue'
import {
  PERIODS,
  DEVICES,
  SIGNED,
  parseView,
  useUxView,
} from '~/modtools/composables/useUxView'

// A registry of four sections is enough for every rule: one that uses all three filters, one that
// uses two, one that uses none and owns a search box, and the default.
const SECTIONS = [
  { key: 'overview', filters: ['period', 'device'], params: ['open'] },
  {
    key: 'funnels',
    filters: ['period', 'device', 'signed'],
    params: ['step', 'open'],
  },
  { key: 'things', filters: [], params: ['exp', 'estate', 'q'] },
  { key: 'pages', filters: ['period', 'device'], params: ['open'] },
]

// The route is reactive and the router's replace moves it, as the real ones do.
function make(query = {}) {
  const route = reactive({ path: '/sysadmin', query })
  const router = {
    replace: vi.fn((to) => {
      route.query = to.query
    }),
    push: vi.fn(),
    resolve: vi.fn((to) => ({
      href: '/sysadmin?' + new URLSearchParams(to.query).toString(),
    })),
  }
  return { route, router, ...useUxView({ route, router, sections: SECTIONS }) }
}

describe('useUxView', () => {
  describe('reading the address', () => {
    it('reads an empty address as Overview, the last 7 days and no filters', () => {
      const { view } = make()
      expect(view.value).toMatchObject({
        section: 'overview',
        period: '7days',
        from: '',
        to: '',
        device: '',
        signed: '',
        flow: '',
        step: '',
        open: '',
        uses: ['period', 'device'],
        filtersActive: false,
        filterParams: {},
        extra: {},
      })
    })

    it('lists the values it accepts', () => {
      expect(PERIODS).toEqual(['7days', '30days', '90days', 'custom'])
      expect(DEVICES).toEqual([
        'web',
        'app',
        'mobile',
        'tablet',
        'desktop',
        'app-ios',
        'app-android',
      ])
      expect(SIGNED).toEqual(['in', 'out'])
    })

    it('drops a value it does not know', () => {
      const v = parseView(
        {
          ux_section: 'bogus',
          ux_period: 'forever',
          ux_device: 'toaster',
          ux_signed: 'maybe',
          ux_flow: 'a b!',
          ux_step: 'x'.repeat(65),
        },
        SECTIONS
      )
      expect(v).toMatchObject({
        section: 'overview',
        period: '7days',
        device: '',
        signed: '',
        flow: '',
        step: '',
      })
    })

    it('reads the first of a repeated key', () => {
      const v = parseView({ ux_device: ['mobile', 'app'] }, SECTIONS)
      expect(v.device).toBe('mobile')
    })

    it('opens the section the address names', () => {
      const v = parseView({ ux_section: 'funnels', ux_flow: 'ask' }, SECTIONS)
      expect(v.section).toBe('funnels')
      expect(v.flow).toBe('ask')
      expect(v.uses).toEqual(['period', 'device', 'signed'])
    })

    it('reads a custom range and puts reversed dates the right way round', () => {
      expect(
        parseView(
          { ux_period: 'custom', ux_from: '2026-10-01', ux_to: '2026-10-07' },
          SECTIONS
        )
      ).toMatchObject({
        period: 'custom',
        from: '2026-10-01',
        to: '2026-10-07',
      })
      expect(
        parseView(
          { ux_period: 'custom', ux_from: '2026-10-07', ux_to: '2026-10-01' },
          SECTIONS
        )
      ).toMatchObject({
        period: 'custom',
        from: '2026-10-01',
        to: '2026-10-07',
      })
    })

    it.each([
      ['no dates', {}],
      ['only one date', { ux_from: '2026-10-01' }],
      ['dates that are not dates', { ux_from: 'soon', ux_to: 'later' }],
      [
        'a day that does not exist',
        { ux_from: '2026-02-30', ux_to: '2026-03-02' },
      ],
      [
        'dates with a time of day',
        { ux_from: '2026-10-01T10:00', ux_to: '2026-10-02' },
      ],
    ])('falls back to 7 days for a custom period with %s', (_, dates) => {
      const v = parseView({ ux_period: 'custom', ...dates }, SECTIONS)
      expect(v).toMatchObject({ period: '7days', from: '', to: '' })
    })

    it('allows a range of 366 days and not 367', () => {
      const ok = parseView(
        { ux_period: 'custom', ux_from: '2025-10-15', ux_to: '2026-10-15' },
        SECTIONS
      )
      expect(ok.period).toBe('custom')
      const tooLong = parseView(
        { ux_period: 'custom', ux_from: '2025-10-14', ux_to: '2026-10-15' },
        SECTIONS
      )
      expect(tooLong).toMatchObject({ period: '7days', from: '', to: '' })
    })

    it('ignores dates when the period is not custom', () => {
      const v = parseView(
        { ux_period: '30days', ux_from: '2026-10-01', ux_to: '2026-10-07' },
        SECTIONS
      )
      expect(v).toMatchObject({ period: '30days', from: '', to: '' })
    })

    it('reads the keys a section owns, by their short names', () => {
      const v = parseView(
        {
          ux_section: 'things',
          ux_exp: '12',
          ux_estate: 'running',
          ux_open: 'a',
        },
        SECTIONS
      )
      expect(v.extra).toEqual({ exp: '12', estate: 'running' })
      expect(v.open).toBe('a')
    })

    it('does not read the keys of a section that is not open', () => {
      const v = parseView({ ux_section: 'overview', ux_exp: '12' }, SECTIONS)
      expect(v.extra).toEqual({})
    })

    it('drops a character outside letters, digits, space, dot, colon and dash from an owned key', () => {
      const v = parseView(
        { ux_section: 'things', ux_exp: 'a<b>/c;d e.f:g-h_i' },
        SECTIONS
      )
      expect(v.extra.exp).toBe('abcd e.f:g-h_i')
    })

    it('cuts an owned key at 80 characters', () => {
      const v = parseView(
        { ux_section: 'things', ux_exp: 'a'.repeat(100) },
        SECTIONS
      )
      expect(v.extra.exp).toHaveLength(80)
    })

    it('keeps an apostrophe and other printable characters in the search text', () => {
      const v = parseView(
        { ux_section: 'things', ux_q: '  can\'t "stop" £5 & more  ' },
        SECTIONS
      )
      expect(v.extra.q).toBe('can\'t "stop" £5 & more')
    })

    it('trims the search text, cuts it at 60 characters and drops control characters', () => {
      const v = parseView(
        { ux_section: 'things', ux_q: 'a\u0000b\tc\n' + 'x'.repeat(100) },
        SECTIONS
      )
      expect(v.extra.q).toHaveLength(60)
      expect(v.extra.q.startsWith('abc')).toBe(true)
    })

    describe('a Signed in value in a section that has no Signed in select', () => {
      const query = { ux_signed: 'in', ux_device: 'mobile' }

      it.each(['overview', 'pages'])(
        'is read but not used on %s',
        (section) => {
          const v = parseView({ ...query, ux_section: section }, SECTIONS)
          expect(v.signed).toBe('in')
          expect(v.uses).not.toContain('signed')
          expect(v.filterParams).toEqual({ device: 'mobile' })
          expect(v.filtersActive).toBe(true)
        }
      )

      it('does not count as a filter when nothing else is set', () => {
        const v = parseView(
          { ux_signed: 'in', ux_section: 'overview' },
          SECTIONS
        )
        expect(v.filterParams).toEqual({})
        expect(v.filtersActive).toBe(false)
      })

      it('is used on Funnels, as 1', () => {
        const v = parseView(
          { ux_signed: 'in', ux_section: 'funnels' },
          SECTIONS
        )
        expect(v.filterParams).toEqual({ signed_in: '1' })
        expect(v.filtersActive).toBe(true)
      })

      it('is sent as 0 for signed out', () => {
        const v = parseView(
          { ux_signed: 'out', ux_section: 'funnels' },
          SECTIONS
        )
        expect(v.filterParams).toEqual({ signed_in: '0' })
      })
    })
  })

  describe('changing the view', () => {
    it("keeps tab=ux and keys that are not the tab's, and leaves out defaults", () => {
      const { set, router } = make({
        tab: 'ux',
        foo: 'bar',
        ux_device: 'mobile',
      })
      set({ period: '30days' })
      expect(router.replace).toHaveBeenCalledWith({
        query: {
          tab: 'ux',
          foo: 'bar',
          ux_period: '30days',
          ux_device: 'mobile',
        },
      })
      set({ period: '7days', device: undefined })
      expect(router.replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', foo: 'bar' },
      })
    })

    it('writes tab=ux even when the address has no tab', () => {
      const { set, router } = make({})
      set({ device: 'app' })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_device: 'app' },
      })
    })

    it('uses replace and never push', () => {
      const { set, router } = make({ tab: 'ux' })
      set({ section: 'funnels' })
      set({ device: 'web' })
      set({ flow: 'ask' })
      expect(router.replace).toHaveBeenCalledTimes(3)
      expect(router.push).not.toHaveBeenCalled()
    })

    it('does not touch the address when nothing changed', () => {
      const { set, router } = make({ tab: 'ux', ux_device: 'mobile' })
      set({})
      set({ device: 'mobile' })
      set({ period: '7days' })
      expect(router.replace).not.toHaveBeenCalled()
    })

    it('cleans up a value it did not understand the next time it writes', () => {
      const { set, router } = make({ tab: 'ux', ux_section: 'bogus' })
      set({})
      expect(router.replace).toHaveBeenCalledWith({ query: { tab: 'ux' } })
    })

    it('shows the change in the view once the address has moved', () => {
      const { set, view } = make({ tab: 'ux' })
      set({ section: 'funnels', device: 'tablet' })
      expect(view.value).toMatchObject({ section: 'funnels', device: 'tablet' })
    })

    it('writes a custom range and drops it again when the period is not custom', () => {
      const { set, router, view } = make({ tab: 'ux' })
      set({ period: 'custom', from: '2026-10-01', to: '2026-10-07' })
      expect(router.replace).toHaveBeenLastCalledWith({
        query: {
          tab: 'ux',
          ux_period: 'custom',
          ux_from: '2026-10-01',
          ux_to: '2026-10-07',
        },
      })
      expect(view.value).toMatchObject({ from: '2026-10-01', to: '2026-10-07' })
      set({ period: '90days' })
      expect(router.replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_period: '90days' },
      })
    })

    it('keeps its step when one patch carries the section, the funnel and the step', () => {
      // The Overview "Biggest loss" link.
      const { set, router } = make({ tab: 'ux' })
      set({ section: 'funnels', flow: 'give', step: 'give-mobile-photos' })
      expect(router.replace).toHaveBeenCalledWith({
        query: {
          tab: 'ux',
          ux_section: 'funnels',
          ux_flow: 'give',
          ux_step: 'give-mobile-photos',
        },
      })
    })

    it('clears the funnel when the nav moves to a section other than Funnels', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_flow: 'ask',
      })
      set({ section: 'pages' })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_section: 'pages' },
      })
    })

    it('keeps the funnel when the nav moves to Funnels', () => {
      const { set, router } = make({ tab: 'ux', ux_flow: 'ask' })
      set({ section: 'funnels' })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_section: 'funnels', ux_flow: 'ask' },
      })
    })

    it('keeps the funnel when a link to another section names one', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_flow: 'give',
      })
      set({ section: 'things', flow: 'give' })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_section: 'things', ux_flow: 'give' },
      })
    })

    it('clears the step and the open row, and the keys the section owns, when it leaves a section', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'things',
        ux_exp: '12',
        ux_estate: 'running',
        ux_q: 'photo',
        ux_open: 'x',
        ux_device: 'mobile',
      })
      set({ section: 'pages' })
      // The device is the person's choice and is not the section's to clear.
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_section: 'pages', ux_device: 'mobile' },
      })
    })

    it('clears only what the patch did not set itself when it leaves a section', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_flow: 'give',
        ux_step: 'a',
        ux_open: 'help',
      })
      set({ section: 'overview', open: 'health' })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_open: 'health' },
      })
    })

    it('writes the keys of the section it is going to', () => {
      const { set, router } = make({ tab: 'ux' })
      set({ section: 'things', exp: '7', q: "can't" })
      expect(router.replace).toHaveBeenCalledWith({
        query: { tab: 'ux', ux_section: 'things', ux_exp: '7', ux_q: "can't" },
      })
    })

    it('clears the step when the funnel changes, unless the patch sets one', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_flow: 'give',
        ux_step: 'a',
      })
      set({ flow: 'ask' })
      expect(router.replace).toHaveBeenLastCalledWith({
        query: { tab: 'ux', ux_section: 'funnels', ux_flow: 'ask' },
      })
      set({ flow: 'reply', step: 'COMPOSING' })
      expect(router.replace).toHaveBeenLastCalledWith({
        query: {
          tab: 'ux',
          ux_section: 'funnels',
          ux_flow: 'reply',
          ux_step: 'COMPOSING',
        },
      })
    })

    it('keeps the step when a patch names the funnel that is already open', () => {
      const { set, router } = make({
        tab: 'ux',
        ux_section: 'funnels',
        ux_flow: 'give',
        ux_step: 'a',
      })
      set({ flow: 'give', device: 'web' })
      expect(router.replace).toHaveBeenCalledWith({
        query: {
          tab: 'ux',
          ux_section: 'funnels',
          ux_flow: 'give',
          ux_step: 'a',
          ux_device: 'web',
        },
      })
    })

    it("still reads can't after the next change", () => {
      const { set, view } = make({
        tab: 'ux',
        ux_section: 'things',
        ux_q: "can't",
      })
      expect(view.value.extra.q).toBe("can't")
      set({ exp: '3' })
      expect(view.value.extra).toEqual({ exp: '3', q: "can't" })
    })

    it('removes a key when the patch says undefined', () => {
      const { set, view } = make({
        tab: 'ux',
        ux_section: 'things',
        ux_exp: '9',
      })
      set({ exp: undefined })
      expect(view.value.extra).toEqual({})
    })
  })

  describe('hrefFor', () => {
    it('gives the address a change would give, without moving', () => {
      const { hrefFor, router, route } = make({
        tab: 'ux',
        ux_device: 'mobile',
      })
      const href = hrefFor({ section: 'funnels', flow: 'ask' })
      expect(href).toBe(
        '/sysadmin?tab=ux&ux_section=funnels&ux_device=mobile&ux_flow=ask'
      )
      expect(router.replace).not.toHaveBeenCalled()
      expect(route.query).toEqual({ tab: 'ux', ux_device: 'mobile' })
    })

    it('works without a router that can resolve', () => {
      const route = reactive({ path: '/sysadmin', query: { tab: 'ux' } })
      const { hrefFor } = useUxView({
        route,
        router: { replace: vi.fn() },
        sections: SECTIONS,
      })
      expect(hrefFor({ section: 'pages' })).toBe(
        '/sysadmin?tab=ux&ux_section=pages'
      )
    })
  })
})
