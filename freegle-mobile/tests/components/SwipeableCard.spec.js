import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SwipeableCard from '~/components/SwipeableCard.vue'

describe('SwipeableCard', () => {
  it('renders slot content', () => {
    const wrapper = mount(SwipeableCard, {
      slots: { default: '<div class="child">Hello</div>' },
    })
    expect(wrapper.find('.child').text()).toBe('Hello')
  })

  it('emits swipe-left on left swipe gesture', async () => {
    const wrapper = mount(SwipeableCard)
    const el = wrapper.find('[class*="swipeable"], div')

    await el.trigger('touchstart', { touches: [{ clientX: 200 }] })
    await el.trigger('touchmove', { touches: [{ clientX: 50 }] })
    await el.trigger('touchend')

    expect(wrapper.emitted('swipe-left')).toBeTruthy()
  })
})
