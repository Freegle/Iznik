import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import MessageItemList from '~/components/MessageItemList.vue'

const store = reactive({ list: {} })
const bulkItemAvailable = vi.fn()
function markInStore(id, itemid, available) {
  const item = store.list[id].bulkitems.find((i) => i.id === itemid)
  item.available = available
  return Promise.resolve()
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: (id) => store.list[id] || null,
    bulkItemAvailable,
  }),
}))

function post(items) {
  store.list[5] = { id: 5, autosplit: true, bulkitems: items }
}

const opts = (props) => ({
  props: { id: 5, ...props },
  global: {
    stubs: {
      'b-badge': { template: '<span class="badge"><slot /></span>' },
      'b-button': {
        template:
          '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
        props: ['disabled'],
        emits: ['click'],
      },
      'v-icon': true,
      MessagePhotosModal: true,
    },
  },
})

describe('MessageItemList', () => {
  beforeEach(() => {
    bulkItemAvailable.mockReset()
    bulkItemAvailable.mockImplementation(markInStore)
    post([
      {
        id: 1,
        name: 'Sofa',
        quantity: 1,
        available: true,
        attachments: [{ id: 9, paththumb: 'https://d/sofa&cx=1' }],
      },
      { id: 2, name: 'Chairs', quantity: 2, available: false, attachments: [] },
    ])
  })

  it('lists each item with its photo, how many and whether it has gone', () => {
    const w = mount(MessageItemList, opts())
    expect(w.text()).toContain('2 things in this post')
    const sofa = w.find('[data-testid="item-1"]')
    expect(sofa.find('img').attributes('src')).toBe('https://d/sofa&cx=1')
    expect(sofa.text()).not.toContain('Gone')
    const chairs = w.find('[data-testid="item-2"]')
    expect(chairs.text()).toContain('× 2')
    expect(chairs.text()).toContain('Gone')
    expect(chairs.classes()).toContain('itemlist__item--gone')
  })

  it('offers no buttons to someone who is not the poster', () => {
    const w = mount(MessageItemList, opts())
    expect(w.find('[data-testid="item-toggle-1"]').exists()).toBe(false)
  })

  it('lets the poster mark an item gone and back', async () => {
    const w = mount(MessageItemList, opts({ editable: true }))
    expect(w.find('[data-testid="item-toggle-1"]').text()).toBe('Mark gone')
    expect(w.find('[data-testid="item-toggle-2"]').text()).toBe('Still here')

    await w.find('[data-testid="item-toggle-2"]').trigger('click')
    await flushPromises()
    expect(bulkItemAvailable).toHaveBeenCalledWith(5, 2, true)
    expect(w.emitted('all-gone')).toBeFalsy()
  })

  it('says when the last item has gone', async () => {
    const w = mount(MessageItemList, opts({ editable: true }))
    await w.find('[data-testid="item-toggle-1"]').trigger('click')
    await flushPromises()
    expect(bulkItemAvailable).toHaveBeenCalledWith(5, 1, false)
    expect(store.list[5].bulkitems.map((i) => i.available)).toEqual([
      false,
      false,
    ])
    expect(w.emitted('all-gone')).toHaveLength(1)
  })

  it('renders nothing for a post with no items', () => {
    post([])
    const w = mount(MessageItemList, opts())
    expect(w.find('[data-testid="item-list"]').exists()).toBe(false)
  })
})
