import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MessageTag from '~/components/MessageTag.vue'

const mockMessage = {
  id: 123,
  type: 'Offer',
  groups: [{ groupid: 1 }],
}

const mockGroup = {
  id: 1,
  settings: {
    keywords: {
      offer: 'OFFERING',
      wanted: 'SEEKING',
    },
  },
}

const mockMessageStore = {
  byId: vi.fn().mockReturnValue(mockMessage),
  fetch: vi.fn().mockResolvedValue(mockMessage),
}

const mockGroupStore = {
  get: vi.fn().mockReturnValue(mockGroup),
  fetch: vi.fn().mockResolvedValue(mockGroup),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/group', () => ({
  useGroupStore: () => mockGroupStore,
}))

describe('MessageTag', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMessageStore.byId.mockReturnValue({ ...mockMessage })
    mockGroupStore.get.mockReturnValue({ ...mockGroup })
  })

  function createWrapper(props = {}) {
    return mount(MessageTag, {
      props: {
        id: 123,
        ...props,
      },
    })
  }

  describe('tag display', () => {

    it('falls back to OFFER when no custom keyword', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Offer',
      })
      mockGroupStore.get.mockReturnValue({
        ...mockGroup,
        settings: { keywords: {} },
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toBe('OFFER')
    })

    it('falls back to WANTED when no custom keyword', () => {
      mockMessageStore.byId.mockReturnValue({
        ...mockMessage,
        type: 'Wanted',
      })
      mockGroupStore.get.mockReturnValue({
        ...mockGroup,
        settings: { keywords: {} },
      })
      const wrapper = createWrapper()
      expect(wrapper.text()).toBe('WANTED')
    })
  })

})
