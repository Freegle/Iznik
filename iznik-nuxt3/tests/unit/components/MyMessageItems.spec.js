import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import MyMessageItems from '~/components/MyMessageItems.vue'

const store = reactive({ list: {} })
const autoSplitUndo = vi.fn()
function undoInStore(id) {
  store.list[id] = { id, bulkcount: 0, bulkitems: undefined, autosplit: false }
  return Promise.resolve()
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({
    byId: (id) => store.list[id] || null,
    autoSplitUndo,
  }),
}))

const opts = {
  props: { id: 5 },
  global: {
    stubs: {
      'b-button': {
        template:
          '<button :disabled="disabled" @click="$emit(\'click\', $event)"><slot /></button>',
        props: ['disabled'],
        emits: ['click'],
      },
      NoticeMessage: { template: '<div><slot /></div>' },
      MessageItemList: {
        props: ['id', 'editable'],
        template: '<div class="items" :data-editable="editable" />',
      },
      AutoSplitEditModal: true,
    },
  },
}

function split(extra = {}) {
  store.list[5] = {
    id: 5,
    autosplit: true,
    bulkcount: 2,
    bulkitems: [{ id: 1 }, { id: 2 }],
    ...extra,
  }
}

describe('MyMessageItems', () => {
  beforeEach(() => {
    autoSplitUndo.mockReset()
    autoSplitUndo.mockImplementation(undoInStore)
    split()
  })

  it('tells the poster their post was listed as items, with Undo and Edit', () => {
    const w = mount(MyMessageItems, opts)
    expect(w.find('[data-testid="autosplit-notice"]').text()).toContain(
      "We've listed this as 2 items"
    )
    expect(w.find('[data-testid="autosplit-undo"]').exists()).toBe(true)
    expect(w.find('[data-testid="autosplit-edit"]').exists()).toBe(true)
    expect(w.find('.items').attributes('data-editable')).toBe('true')
  })

  it('undoes the split in one tap', async () => {
    const w = mount(MyMessageItems, opts)
    await w.find('[data-testid="autosplit-undo"]').trigger('click')
    await flushPromises()
    expect(autoSplitUndo).toHaveBeenCalledWith(5)
    expect(w.find('[data-testid="autosplit-section"]').exists()).toBe(false)
  })

  it('says so when the undo fails', async () => {
    autoSplitUndo.mockImplementation(() => Promise.reject(new Error('nope')))
    const w = mount(MyMessageItems, opts)
    await w.find('[data-testid="autosplit-undo"]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain("Sorry, that didn't work")
  })

  it('shows the items without the controls once the post has an outcome', () => {
    split({ outcomes: [{ outcome: 'Taken' }] })
    const w = mount(MyMessageItems, opts)
    expect(w.find('[data-testid="autosplit-notice"]').exists()).toBe(false)
    expect(w.find('.items').attributes('data-editable')).toBe('false')
  })

  it('shows nothing for a clearance or an ordinary post', () => {
    store.list[5] = { id: 5, bulkcount: 2, bulkitems: [{}, {}] }
    expect(
      mount(MyMessageItems, opts)
        .find('[data-testid="autosplit-section"]')
        .exists()
    ).toBe(false)
    store.list[5] = { id: 5 }
    expect(
      mount(MyMessageItems, opts)
        .find('[data-testid="autosplit-section"]')
        .exists()
    ).toBe(false)
  })
})
