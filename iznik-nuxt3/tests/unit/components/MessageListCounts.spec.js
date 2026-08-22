import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MessageListCounts from '~/components/MessageListCounts.vue'

describe('MessageListCounts', () => {
  function createWrapper(props = {}) {
    return mount(MessageListCounts, {
      props: {
        count: 5,
        ...props,
      },
      global: {
        stubs: {
          'v-icon': {
            template: '<span class="v-icon" />',
            props: ['icon'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('renders when count > 0', () => {
      const wrapper = createWrapper({ count: 5 })
      expect(wrapper.find('.unread-divider').exists()).toBe(true)
    })

    it('does not render when count is 0', () => {
      const wrapper = createWrapper({ count: 0 })
      expect(wrapper.find('.unread-divider').exists()).toBe(false)
    })

    it('shows icon', () => {
      const wrapper = createWrapper({ count: 3 })
      expect(wrapper.find('.v-icon').exists()).toBe(true)
    })

    it('shows mark seen button', () => {
      const wrapper = createWrapper({ count: 3 })
      expect(wrapper.find('.mark-seen-btn').exists()).toBe(true)
      expect(wrapper.find('.mark-seen-btn').text()).toBe('Mark seen')
    })

    it('shows divider lines', () => {
      const wrapper = createWrapper({ count: 3 })
      expect(wrapper.findAll('.divider-line').length).toBe(2)
    })
  })

  describe('count display', () => {
    it('shows singular when count is 1', () => {
      const wrapper = createWrapper({ count: 1 })
      expect(wrapper.find('.unread-text').text()).toBe('1 new post')
    })

    it('shows plural when count > 1', () => {
      const wrapper = createWrapper({ count: 5 })
      expect(wrapper.find('.unread-text').text()).toBe('5 new posts')
    })

    // The nav badge caps at 99 with no room for a "+", so a member with a
    // four-figure backlog reads it as a number that will not move. The divider
    // has the room, and says "99+" so the cap is visible as a cap.
    it('shows an exact count at the 99 boundary', () => {
      const wrapper = createWrapper({ count: 99 })
      expect(wrapper.find('.unread-text').text()).toBe('99 new posts')
    })

    it('shows 99+ once past the cap', () => {
      const wrapper = createWrapper({ count: 100 })
      expect(wrapper.find('.unread-text').text()).toBe('99+ new posts')
    })

    it('shows 99+ rather than a four-figure backlog', () => {
      const wrapper = createWrapper({ count: 10463 })
      expect(wrapper.find('.unread-text').text()).toBe('99+ new posts')
    })

    it('shows zero count in text when applicable', () => {
      const wrapper = createWrapper({ count: 0 })
      // Component doesn't render when count is 0, so no text to check
      expect(wrapper.find('.unread-divider').exists()).toBe(false)
    })
  })

  describe('events', () => {
    it('emits markSeen when button clicked', async () => {
      const wrapper = createWrapper({ count: 5 })
      await wrapper.find('.mark-seen-btn').trigger('click')
      expect(wrapper.emitted('markSeen')).toBeTruthy()
      expect(wrapper.emitted('markSeen').length).toBe(1)
    })
  })

  describe('props', () => {
    it('defaults count to 0', () => {
      const wrapper = mount(MessageListCounts, {
        global: {
          stubs: {
            'v-icon': {
              template: '<span class="v-icon" />',
              props: ['icon'],
            },
          },
        },
      })
      expect(wrapper.props('count')).toBe(0)
    })

    it('accepts count prop', () => {
      const wrapper = createWrapper({ count: 42 })
      expect(wrapper.props('count')).toBe(42)
    })
  })
})
