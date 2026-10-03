import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import NearbySheet from '~/components/chatshell/NearbySheet.vue'

// Nearest first, ten at a time, inside a sheet over the chat: the page never grows.
const rows = Array.from({ length: 12 }, (_, i) => ({
  id: i + 1,
  type: i % 3 === 0 ? 'Wanted' : 'Offer',
  lat: 55.95 + i * 0.001,
  lng: -3.19,
  miles: i * 0.1,
}))
const { fetched, lists, replied } = vi.hoisted(() => ({
  fetched: [],
  lists: [],
  replied: [],
}))
vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    fetch: async (id) => {
      fetched.push(id)
      return { id }
    },
    byId: (id) => ({ id, subject: 'Post ' + id }),
  }),
}))
vi.mock('~/stores/assistant', async () => {
  const { reactive } = await import('vue')
  const store = reactive({ nearby: null, visitorLocation: null })
  return { useAssistantStore: () => store }
})
vi.mock('~/composables/useHostActions', async () => {
  const { useAssistantStore } = await import('~/stores/assistant')
  const store = useAssistantStore()
  return {
    useHostActions: () => ({
      myLatLng: () => store.visitorLocation,
      replyTo: (id) => replied.push(id),
      filterRows: (list, f) =>
        f === 'offers'
          ? list.filter((m) => m.type === 'Offer')
          : f === 'wanted'
            ? list.filter((m) => m.type === 'Wanted')
            : list,
      fetchNearby: async ({ term = '', at } = {}) => {
        lists.push(term)
        const got = term ? rows.slice(0, 2) : rows
        store.nearby = { term, at, rows: got, fetched: Date.now() }
        return got
      },
    }),
  }
})

const stubs = {
  PostcodeInput: {
    template:
      '<button class="pc" @click="$emit(\'selected\', { lat: 55.95, lng: -3.19, name: \'EH3\' })" />',
  },
  PostRow: {
    props: ['id', 'expanded'],
    emits: ['reply', 'expand'],
    template:
      '<div class="row-stub" :data-testid="\'nearby-row-\' + id"><button class="rep" @click="$emit(\'reply\', id)">r</button>{{ id }}</div>',
  },
}

async function sheet(props = {}) {
  const w = mount(NearbySheet, { props, global: { stubs } })
  await flushPromises()
  return w
}

describe('NearbySheet', () => {
  beforeEach(async () => {
    fetched.length = 0
    lists.length = 0
    replied.length = 0
    const { useAssistantStore } = await import('~/stores/assistant')
    const store = useAssistantStore()
    store.nearby = null
    store.visitorLocation = { lat: 55.95, lng: -3.19 }
  })

  it('lists ten nearest first inside the sheet, and Show more appends the rest', async () => {
    const w = await sheet()
    expect(w.findAll('.row-stub')).toHaveLength(10)
    expect(fetched).toHaveLength(10)
    expect(w.find('.sheet-title').text()).toBe('Nearby')
    await w.find('[data-testid="nearby-more"]').trigger('click')
    await flushPromises()
    expect(w.findAll('.row-stub')).toHaveLength(12)
    expect(w.find('[data-testid="nearby-more"]').exists()).toBe(false)
    expect(lists).toEqual([''])
  })

  it('filters to wanted without fetching the list again, and fills the page', async () => {
    const w = await sheet()
    await w.find('[data-testid="nearby-filter-wanted"]').trigger('click')
    await flushPromises()
    const ids = w
      .findAll('.row-stub')
      .map((c) =>
        parseInt(c.attributes('data-testid').replace('nearby-row-', ''))
      )
    expect(ids).toEqual([1, 4, 7, 10])
    expect(
      w.find('[data-testid="nearby-filter-wanted"]').attributes('aria-selected')
    ).toBe('true')
    expect(lists).toHaveLength(1)
  })

  it('asks where they are when the location is unknown, then looks around that postcode', async () => {
    const { useAssistantStore } = await import('~/stores/assistant')
    useAssistantStore().visitorLocation = null
    const w = await sheet()
    expect(w.find('[data-testid="nearby-list"]').exists()).toBe(false)
    expect(w.text()).toContain('Tell me where you are')
    await w.find('.pc').trigger('click')
    await flushPromises()
    expect(useAssistantStore().visitorLocation.name).toBe('EH3')
    expect(w.findAll('.row-stub')).toHaveLength(10)
  })

  it('searches when given a term, and again from its own box', async () => {
    const w = await sheet({ term: 'bike' })
    expect(w.findAll('.row-stub')).toHaveLength(2)
    expect(w.find('[data-testid="nearby-search"]').element.value).toBe('bike')
    expect(w.text()).toContain('Matches for "bike"')
    await w.find('[data-testid="nearby-search"]').setValue('cot')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(lists).toEqual(['bike', 'cot'])
  })

  it('reply closes the sheet and starts the reply', async () => {
    const w = await sheet()
    await w.find('[data-testid="nearby-row-2"] .rep').trigger('click')
    expect(w.emitted('close')).toHaveLength(1)
    expect(replied).toEqual([2])
  })

  it('reuses rows the chat fetched a moment ago for the same place', async () => {
    const { useAssistantStore } = await import('~/stores/assistant')
    const store = useAssistantStore()
    store.nearby = {
      term: '',
      at: store.visitorLocation,
      rows: rows.slice(0, 3),
      fetched: Date.now(),
    }
    const w = await sheet()
    expect(lists).toHaveLength(0)
    expect(w.findAll('.row-stub')).toHaveLength(3)
  })
})
