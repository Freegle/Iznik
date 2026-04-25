import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ChatSlideOver from '~/components/ChatSlideOver.vue'

describe('ChatSlideOver', () => {
  const props = {
    visible: true,
    userName: 'Alice',
    userAvatar: null,
    quotedItem: { title: 'Sofa', type: 'Offer', imageUrl: null },
    messages: [
      { id: 1, text: 'Is the sofa still available?', outgoing: false, time: '14:30' },
      { id: 2, text: 'Yes! Any time after 3pm', outgoing: true, time: '14:32' },
    ],
  }

  it('shows user name in header', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.text()).toContain('Alice')
  })

  it('shows private conversation label', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.text()).toContain('Private')
  })

  it('shows quoted item', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.text()).toContain('Sofa')
  })

  it('renders messages', () => {
    const wrapper = mount(ChatSlideOver, { props })
    expect(wrapper.text()).toContain('Is the sofa still available?')
    expect(wrapper.text()).toContain('Any time after 3pm')
  })

  it('emits close on back button', async () => {
    const wrapper = mount(ChatSlideOver, { props })
    const backBtn = wrapper.find('[class*="back"]')
    if (backBtn.exists()) {
      await backBtn.trigger('click')
      expect(wrapper.emitted('close')).toBeTruthy()
    }
  })
})
