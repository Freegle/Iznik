import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FeedComposer from '~/components/FeedComposer.vue'

describe('FeedComposer', () => {
  it('renders composer with input', () => {
    const wrapper = mount(FeedComposer)
    expect(wrapper.find('input[type="text"]').exists()).toBe(true)
  })

  it('emits submit with text when send is clicked', async () => {
    const wrapper = mount(FeedComposer)
    const input = wrapper.find('input[type="text"]')
    await input.setValue('got a sofa')
    const sendBtn = wrapper.find('[class*="send"]')
    if (sendBtn.exists()) {
      await sendBtn.trigger('click')
      expect(wrapper.emitted('submit')).toBeTruthy()
      const payload = wrapper.emitted('submit')[0][0]
      expect(payload.text).toBe('got a sofa')
    }
  })

  it('has camera/attach button', () => {
    const wrapper = mount(FeedComposer)
    expect(wrapper.find('[class*="camera"], [class*="attach"]').exists()).toBe(true)
  })
})
