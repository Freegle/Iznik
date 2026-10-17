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

  describe('the closed row', () => {
    it('names the component whose trouble rose most, which is the first row the server sent', () => {
      // The server ranks the rows by the rise in trouble, not by how much trouble there is. The first
      // is the one with the biggest rise, whatever the others hold, so it is the one to name.
      store.friction.data = {
        components: [
          row('/give', 'PhotoUpload', { change: 0.4, clicks: 10 }),
          row('/ask', 'Submit', { change: 0.2, clicks: 9000 }),
          row('/home', 'Menu', { change: 0.1, clicks: 5000 }),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble rose most at /give / PhotoUpload. 3 components listed, biggest rise first.'
      )
    })

    it('does not call the component with the biggest rise the one with the most trouble', () => {
      // A button going from 1% to 4% outranks one stuck at 40%, and the stuck one may not be listed.
      store.friction.data = {
        components: [
          row('/give', 'PhotoUpload', {
            change: 0.03,
            dead_clicks: 8,
            rage_clicks: 0,
            repeated_intent: 0,
            clicks: 200,
          }),
          row('/ask', 'Submit', { change: 0.0, dead_clicks: 100, clicks: 200 }),
        ],
      }
      const line = make().find('.ux-summary').text()
      expect(line).not.toContain('Most trouble')
      expect(line).toContain('Trouble rose most at /give / PhotoUpload.')
    })

    it('says one component in the singular', () => {
      store.friction.data = data
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble rose most at /give / PhotoUpload. 1 component listed.'
      )
    })

    it('says trouble did not rise when even the first row did not rise', () => {
      store.friction.data = {
        components: [
          row('/give', 'PhotoUpload', { change: 0 }),
          row('/ask', 'Submit', { change: -0.1 }),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        'Trouble did not rise at any component. 2 components listed.'
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
