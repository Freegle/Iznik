import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import NearbyScreen from '~/components/chatshell/NearbyScreen.vue'

// Nearest first, ten at a time, with a filter and a More button: a list, not a chat.
const rows = Array.from({ length: 12 }, (_, i) => ({
  id: i + 1,
  type: i % 3 === 0 ? 'Wanted' : 'Offer',
  lat: 55.95 + i * 0.001,
  lng: -3.19,
}))
const fetched = []
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetchInBounds: async () => rows,
    search: async () => rows.slice(0, 2),
    fetch: async (id) => {
      fetched.push(id)
      return { id }
    },
    byId: (id) => ({ id, subject: 'Post ' + id, lat: 55.95, lng: -3.19 }),
  }),
}))
let location = { lat: 55.95, lng: -3.19 }
vi.mock('~/composables/useHostActions', () => ({
  useHostActions: () => ({ myLatLng: () => location, replyTo: vi.fn() }),
}))
vi.mock('~/stores/assistant', () => ({
  useAssistantStore: () => ({ visitorLocation: null }),
}))

const stubs = {
  ShellHeader: true,
  PostcodeInput: true,
  PostCard: {
    props: ['id'],
    template: '<div class="card-stub">{{ id }}</div>',
  },
}

describe('NearbyScreen', () => {
  beforeEach(() => {
    fetched.length = 0
    location = { lat: 55.95, lng: -3.19 }
  })

  it('lists ten nearest first with a More button for the rest', async () => {
    const w = mount(NearbyScreen, { global: { stubs } })
    await flushPromises()
    expect(w.findAll('.card-stub')).toHaveLength(10)
    expect(fetched).toHaveLength(10)
    await w.find('[data-testid="nearby-more"]').trigger('click')
    await flushPromises()
    expect(w.findAll('.card-stub')).toHaveLength(12)
    expect(w.find('[data-testid="nearby-more"]').exists()).toBe(false)
  })

  it('filters to offers or wanted without another fetch', async () => {
    const w = mount(NearbyScreen, { global: { stubs } })
    await flushPromises()
    await w.find('[data-testid="nearby-filter-Wanted"]').trigger('click')
    expect(w.findAll('.card-stub').length).toBeGreaterThan(0)
    expect(
      w.findAll('.card-stub').every((c) => (parseInt(c.text()) - 1) % 3 === 0)
    ).toBe(true)
    expect(fetched).toHaveLength(10)
  })

  it('asks where they are when the location is unknown', async () => {
    location = null
    const w = mount(NearbyScreen, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-testid="nearby-list"]').exists()).toBe(false)
    expect(w.findComponent({ name: 'PostcodeInput' }).exists()).toBe(true)
  })

  it('searches when given a term', async () => {
    const w = mount(NearbyScreen, {
      props: { term: 'bike' },
      global: { stubs },
    })
    await flushPromises()
    expect(w.findAll('.card-stub')).toHaveLength(2)
    expect(w.find('[data-testid="nearby-search"]').element.value).toBe('bike')
  })
})
