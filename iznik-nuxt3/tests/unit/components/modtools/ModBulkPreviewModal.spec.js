import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import ModBulkPreviewModal from '~/modtools/components/ModBulkPreviewModal.vue'

const message = {
  id: 123,
  subject: 'OFFER: Office Clearance (Brighton)',
  textbody: 'Charity clearance.',
  bulkcount: 2,
  bulkitems: [
    {
      id: 1,
      name: 'Office desk',
      quantity: 4,
      condition: 'Good',
      dimensions: '120x80cm',
      attachments: [],
    },
    {
      id: 2,
      name: 'Swivel chair',
      quantity: 14,
      condition: 'LikeNew',
      attachments: [],
    },
  ],
  bulkslots: ['Tue 7 Apr, 10am-4pm'],
}

const mockMessageStore = {
  byId: vi.fn(() => message),
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const mockHide = vi.fn()
vi.mock('~/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: ref(null), show: vi.fn(), hide: mockHide }),
}))

function mountComponent(props = {}) {
  return mount(ModBulkPreviewModal, {
    props: { messageid: 123, ...props },
    global: {
      stubs: {
        'b-modal': {
          template: `
            <div class="modal">
              <div class="modal-title"><slot name="title" /></div>
              <div class="modal-body"><slot name="default" /></div>
              <div class="modal-footer"><slot name="footer" /></div>
            </div>`,
          props: ['id', 'size', 'scrollable'],
          emits: ['hidden'],
        },
        'b-button': {
          template: '<button @click="$emit(\'click\')"><slot /></button>',
          props: ['variant'],
        },
        'b-form-checkbox': {
          template: '<input type="checkbox" class="switch" />',
          props: ['switch', 'disabled'],
        },
        'v-icon': { template: '<i />', props: ['icon'] },
      },
    },
  })
}

describe('ModBulkPreviewModal', () => {
  beforeEach(() => vi.clearAllMocks())

  it('renders the modal with the members-view title', () => {
    const w = mountComponent()
    expect(w.find('.modal').exists()).toBe(true)
    expect(w.find('.modal-title').text()).toContain(
      'How members will see this offer'
    )
  })

  it('explains it is a single post', () => {
    expect(mountComponent().text()).toContain('single post')
  })

  it('lists each item with quantity and condition', () => {
    const t = mountComponent().text()
    expect(t).toContain('Office desk')
    expect(t).toContain('4 available')
    expect(t).toContain('Swivel chair')
    expect(t).toContain('14 available')
    // LikeNew is humanised.
    expect(t).toContain('Like new')
  })

  it('shows the per-item toggle members use', () => {
    const w = mountComponent()
    expect(w.findAll('.switch').length).toBe(2)
  })

  it('shows the collection times', () => {
    expect(mountComponent().text()).toContain('Tue 7 Apr, 10am-4pm')
  })

  it('Close calls hide and onHide emits hidden', async () => {
    const w = mountComponent()
    await w.find('.modal-footer button').trigger('click')
    expect(mockHide).toHaveBeenCalled()
    w.vm.onHide()
    expect(w.emitted('hidden')).toBeTruthy()
  })

  it('handles an offer with no items', () => {
    mockMessageStore.byId.mockReturnValueOnce({ id: 9, bulkitems: [] })
    expect(mountComponent({ messageid: 9 }).text()).toContain(
      'No items have been listed'
    )
  })
})
