import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NewsConvertedNotice from '~/components/NewsConvertedNotice.vue'

// This is the note left on a ChitChat thread, in the member's name, when a
// moderator posts their item properly for them. The convert modal renders the
// same component in preview mode, so a moderator sees exactly what the member
// will read before committing to it.
describe('NewsConvertedNotice', () => {
  function createWrapper(props = {}) {
    return mount(NewsConvertedNotice, {
      props,
      global: {
        stubs: {
          NoticeMessage: {
            template: '<div class="notice-message"><slot /></div>',
            props: ['variant'],
          },
          'b-button': {
            template:
              '<button class="b-button" :disabled="disabled" :data-to="to"><slot /></button>',
            props: ['variant', 'to', 'disabled'],
          },
        },
      },
    })
  }

  it('explains that a volunteer posted it for them', () => {
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted this properly for you'
    )
    expect(wrapper.text()).toContain("You don't need to do anything")
  })

  it('tells them where to find it', () => {
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain('My Posts')
    expect(wrapper.text()).toContain('edit or withdraw it')
  })

  it('links to My Posts on the thread', () => {
    const button = createWrapper().find('.b-button')
    expect(button.attributes('data-to')).toBe('/myposts')
    expect(button.attributes('disabled')).toBeUndefined()
  })

  it('shows the same words in preview, with the button inert', () => {
    const wrapper = createWrapper({ preview: true })

    // The moderator is not the one going to My Posts.
    expect(wrapper.find('.b-button').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.b-button').attributes('data-to')).toBeUndefined()

    // Preview must not change the wording - that is the whole point.
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted this properly for you'
    )
  })
})
