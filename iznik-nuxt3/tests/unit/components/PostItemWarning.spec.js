import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PostItemWarning from '~/components/PostItemWarning.vue'

function mountWarning(props) {
  return mount(PostItemWarning, {
    props,
    global: {
      stubs: {
        NoticeMessage: {
          template:
            '<div class="notice-message" :class="variant"><slot /></div>',
          props: ['variant'],
        },
        'v-icon': { template: '<i />' },
      },
    },
  })
}

describe('PostItemWarning', () => {
  it('shows the note and its heading for an offer', () => {
    const wrapper = mountWarning({ item: 'Kids car seat', type: 'Offer' })
    expect(wrapper.find('.notice-message.warning').exists()).toBe(true)
    expect(wrapper.text()).toContain('Car seats')
    expect(wrapper.text()).toContain('Please say the make and model')
  })

  it('shows the asker wording for a wanted', () => {
    const wrapper = mountWarning({ item: 'Kids car seat', type: 'Wanted' })
    expect(wrapper.text()).toContain('Ask for the make, model and age')
  })

  it('shows nothing for an item with no note', () => {
    const wrapper = mountWarning({ item: 'Bookcase', type: 'Offer' })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('shows nothing before an item is typed', () => {
    const wrapper = mountWarning({ item: null, type: 'Offer' })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })
})
