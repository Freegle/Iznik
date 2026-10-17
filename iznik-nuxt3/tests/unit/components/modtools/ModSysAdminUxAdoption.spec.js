import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import ModSysAdminUxAdoption from '~/modtools/components/ModSysAdminUxAdoption.vue'
import { useUxStore } from '~/modtools/stores/ux'
import { uxStubs } from './uxStubs'

const feature = (name, share, over = {}) => ({
  feature: name,
  active_users: 400,
  using_users: Math.round(400 * share),
  sessions_using: 90,
  share,
  previous_share: 0.05,
  ...over,
})

describe('ModSysAdminUxAdoption', () => {
  let store
  beforeEach(() => {
    setActivePinia(createPinia())
    store = useUxStore()
    store.adoption.data = { features: [feature('saved_search', 0.1)] }
  })
  const make = (props = {}) =>
    mount(ModSysAdminUxAdoption, { props, global: { stubs: uxStubs } })

  it('shows users of the feature over active users, with counts', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('saved_search')
    expect(t).toContain('10.0% (40/400)')
  })

  it('shows the previous share and the change in points', () => {
    const t = make({ open: true }).text()
    expect(t).toContain('5.0%')
    expect(t).toContain('+5.0 points')
  })

  it('shows the empty message', () => {
    store.adoption.data = { features: [] }
    expect(make({ open: true }).text()).toContain('No adoption data')
    expect(make().find('.ux-summary').text()).toBe(
      'No adoption data for this period.'
    )
  })

  it('shows an error', () => {
    store.adoption.data = null
    store.adoption.error = 'down'
    expect(make().text()).toContain('down')
  })

  describe('the closed row', () => {
    it('names the least and the most used feature', () => {
      store.adoption.data = {
        features: [
          feature('chats', 0.4),
          feature('saved_search', 0.05),
          feature('map', 0.2),
        ],
      }
      expect(make().find('.ux-summary').text()).toBe(
        '3 features. Least used: saved_search (5.0%). Most used: chats (40.0%).'
      )
    })

    it('names the first of the features that tie', () => {
      store.adoption.data = {
        features: [feature('a', 0.1), feature('b', 0.1), feature('c', 0.1)],
      }
      expect(make().find('.ux-summary').text()).toBe(
        '3 features. Least used: a (10.0%). Most used: a (10.0%).'
      )
    })

    it('says one feature in the singular', () => {
      expect(make().find('.ux-summary').text()).toBe(
        '1 feature: saved_search (10.0%).'
      )
    })
  })

  it('says when the server did not use the device that was asked for, even when closed', () => {
    // A closed line that says "Least used" under a Device filter would otherwise read as filtered.
    store.adoption.data = {
      features: [feature('a', 0.1)],
      filters: { device: '', signed_in: '' },
      ignored: ['device'],
    }
    store.answered.adoption = { device: 'app' }
    const w = make()
    expect(w.find('.ux-note').text()).toBe(
      'Not filtered by device. Shows every device.'
    )
    expect(w.find('h4 > button').attributes('aria-expanded')).toBe('false')
  })

  it('says the part is not switched on when the route is missing', () => {
    store.adoption.data = null
    store.adoption.missing = true
    expect(make().find('.ux-summary').text()).toBe(
      'This part is not switched on yet.'
    )
  })
})
