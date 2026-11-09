import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxFriction from '~/modtools/components/ModSysAdminUxFriction.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const row = (page, component, over = {}) => ({
  page,
  component,
  impressions: 1000,
  clicks: 200,
  dead_clicks: 40,
  rage_clicks: 10,
  repeated_intent: 6,
  previous: {
    impressions: 900,
    clicks: 180,
    dead_clicks: 9,
    rage_clicks: 2,
    repeated_intent: 1,
  },
  // A rise of 0.3 is 30 points: the server sends a difference of two shares.
  change: 0.3,
  // Trouble clicks above what last period's rates would give at this period's clicks, device by device.
  effect: 60,
  compared: true,
  ...over,
})

const data = { components: [row('/give', 'PhotoUpload')] }

describe('ModSysAdminUxFriction', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxFriction, { props, global: { stubs: uxStubs } })

  it('lists components with dead and rage clicks as percentages with counts', () => {
    store.friction.data = data
    const t = make({ open: true }).text()
    expect(t).toContain('/give')
    expect(t).toContain('PhotoUpload')
    expect(t).toContain('20.0% (40/200)')
    expect(t).toContain('5.0% (10/200)')
  })

  it('shows the previous period alongside', () => {
    store.friction.data = data
    expect(make({ open: true }).text()).toContain('5.0% (9/180)')
  })

  it('shows the change in points, with its sign', () => {
    store.friction.data = {
      components: [
        row('/a', 'A', { change: 0.3 }),
        row('/b', 'B', { change: -0.05 }),
      ],
    }
    const t = make({ open: true }).text()
    expect(t).toContain('+30.0 points')
    expect(t).toContain('-5.0 points')
  })

  it('shows an empty message with no components', () => {
    store.friction.data = { components: [] }
    expect(make({ open: true }).text()).toContain('Nothing to report.')
  })

  it('shows loading and error states', () => {
    store.friction.loading = true
    expect(make({ open: true }).find('.spinner').exists()).toBe(true)
    store.friction.loading = false
    store.friction.error = 'bad'
    expect(make().text()).toContain('bad')
  })

  it('shows the change in trouble clicks, and says when there was nothing to compare with', () => {
    store.friction.data = {
      components: [
        row('/a', 'A', { effect: -200 }),
        row('/b', 'B', { effect: 70, compared: false }),
      ],
    }
    const t = make({ open: true }).text()
    expect(t).toContain('200 fewer')
    expect(t).toContain('70 more, nothing to compare with')
  })

  describe('the closed row', () => {
    it('names the component whose trouble clicks changed most, which is the first row the server sent', () => {
      // The server ranks by how many trouble clicks changed, either way, device by device. The
      // first is that one, whatever share of clicks the others have.
      store.friction.data = {
        components: [
          row('/ask', 'Submit', { change: -0.1, effect: -200, clicks: 2000 }),
          row('/give', 'PhotoUpload', { change: 0.4, effect: 40, clicks: 100 }),
          row('/home', 'Menu', { change: 0.1, effect: 5, clicks: 50 }),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble clicks changed most at /ask / Submit: 200 fewer. 3 components listed, biggest change first.'
      )
    })

    it('says one component in the singular', () => {
      store.friction.data = data
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble clicks changed most at /give / PhotoUpload: 60 more. 1 component listed.'
      )
    })

    it('says a first row with nothing to compare with is new trouble', () => {
      store.friction.data = {
        components: [
          row('/give', 'PhotoUpload', { effect: 70, compared: false }),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble clicks changed most at /give / PhotoUpload: 70 more, nothing to compare with. 1 component listed.'
      )
    })

    it('says trouble clicks did not change when even the first row did not', () => {
      store.friction.data = {
        components: [
          row('/give', 'PhotoUpload', { effect: 0 }),
          row('/ask', 'Submit', { effect: 0 }),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble clicks did not change at any component. 2 components listed.'
      )
    })

    it('says there is nothing to report with no components', () => {
      store.friction.data = { components: [] }
      expect(make().find('.ux-summary').text()).toBe('Nothing to report.')
    })

    it('is closed by default and asks the section to open it', async () => {
      store.friction.data = data
      const w = make()
      expect(w.find('h4 > button').attributes('aria-expanded')).toBe('false')
      await w.find('h4 > button').trigger('click')
      expect(w.emitted('toggle')).toHaveLength(1)
    })
  })

  it('says when the server cut the figures by device but not by sign-in', () => {
    store.friction.data = {
      ...data,
      filters: { device: 'mobile', signed_in: '' },
      ignored: ['signed_in'],
    }
    store.answered.friction = { device: 'mobile', signed_in: '1' }
    expect(make().find('.ux-note').text()).toBe(
      'Not filtered by sign-in. Shows signed-in and signed-out people together.'
    )
  })

  it('says the part is not switched on when the route is missing', () => {
    store.friction.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
