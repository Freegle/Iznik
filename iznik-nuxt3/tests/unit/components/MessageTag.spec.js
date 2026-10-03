import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessageTag from '~/components/MessageTag.vue'

const mockMessage = {
  id: 123,
  type: 'Offer',
}

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
  fetch: vi.fn().mockResolvedValue(mockMessage),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

describe('MessageTag', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue({ ...mockMessage })
  })

  function createWrapper(props = {}) {
    return mount(MessageTag, {
      props: {
        id: 123,
        ...props,
      },
    })
  }

  describe('rendering', () => {
    it('renders tagbadge container', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.tagbadge').exists()).toBe(true)
    })

    it('applies fw-bold class', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.fw-bold').exists()).toBe(true)
    })
  })

  describe('props', () => {
    it('requires id prop', () => {
      const wrapper = createWrapper({ id: 456 })
      expect(wrapper.props('id')).toBe(456)
    })

    it('has optional def prop with false default', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('def')).toBe(false)
    })

    it('applies tagdef class when def is true', () => {
      const wrapper = createWrapper({ def: true })
      expect(wrapper.find('.tagdef').exists()).toBe(true)
    })

    it('has optional inline prop with false default', () => {
      const wrapper = createWrapper()
      expect(wrapper.props('inline')).toBe(false)
    })

    it('applies inline class when inline is true', () => {
      const wrapper = createWrapper({ inline: true })
      expect(wrapper.find('.inline').exists()).toBe(true)
    })
  })

  describe('tag display', () => {
    it('displays OFFER for Offer type', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Offer',
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toBe('OFFER')
    })

    it('displays WANTED for Wanted type', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Wanted',
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toBe('WANTED')
    })
  })

  describe('wanted styling', () => {
    it('applies tagbadge--wanted class for Wanted type', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Wanted',
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.tagbadge--wanted').exists()).toBe(true)
    })

    it('does not apply tagbadge--wanted for Offer type', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Offer',
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.tagbadge--wanted').exists()).toBe(false)
    })
  })

  describe('data fetching on mount', () => {
    it('fetches message on mount', async () => {
      createWrapper({ id: 789 })
      await flushPromises()
      expect(mockMessageStore.fetch).toHaveBeenCalledWith(789)
    })
  })

  describe('edge cases', () => {
    it('handles null message', () => {
      mockMessageStore.byId.mockReturnValue(null)
      const wrapper = createWrapper()
      expect(wrapper.text()).toBe('')
    })
  })
})
