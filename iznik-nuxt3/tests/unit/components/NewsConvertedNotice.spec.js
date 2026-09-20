import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NewsConvertedNotice from '~/components/NewsConvertedNotice.vue'

// This is the note left on a ChitChat thread, in the member's name, when a
// moderator posts an OFFER/WANTED for them. The convert modal renders the
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
          // data-to reports the STATE of the `to` prop, not just its value:
          // Vue drops an attribute bound to null, so `:data-to="to"` could not
          // tell "no route passed" from "route passed as null" - and null is
          // exactly what crashed the router.
          'b-button': {
            template:
              '<button class="b-button" :disabled="disabled" :data-to="to === undefined ? \'absent\' : String(to)"><slot /></button>',
            props: ['variant', 'to', 'disabled'],
          },
        },
      },
    })
  }

  it('says a WANTED was posted when it became a WANTED', () => {
    const wrapper = createWrapper({ msgtype: 'Wanted' })
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted a WANTED for you'
    )
    expect(wrapper.text()).toContain("You don't need to do anything")
  })

  it('says an OFFER was posted when it became an OFFER', () => {
    const wrapper = createWrapper({ msgtype: 'Offer' })
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted an OFFER for you'
    )
  })

  it('falls back to neutral wording when the post type is unknown', () => {
    // Notices written before msgid was recorded have no type to show.
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted this for you'
    )
  })

  // The member posted in ChitChat because they didn't know an OFFER/WANTED was
  // the thing to use. A notice that only says "we posted it for you" leaves
  // them none the wiser, so it has to say what one is and which button posts
  // it.
  it('explains that an OFFER is how you give something away, and names the button', () => {
    const wrapper = createWrapper({ msgtype: 'Offer' })
    expect(wrapper.text()).toContain(
      'An OFFER is how you give something away on Freegle'
    )
    expect(wrapper.text()).toContain('with the Give button')
    expect(wrapper.text()).not.toContain('A WANTED is how you ask')
  })

  it('explains that a WANTED is how you ask for something, and names the button', () => {
    const wrapper = createWrapper({ msgtype: 'Wanted' })
    expect(wrapper.text()).toContain(
      'A WANTED is how you ask for something on Freegle'
    )
    expect(wrapper.text()).toContain('with the Ask button')
    expect(wrapper.text()).not.toContain('An OFFER is how you give')
  })

  it('explains both when the post type is unknown', () => {
    // Notices written before msgtype was recorded must still explain what an
    // OFFER/WANTED is, without claiming which one this was.
    const wrapper = createWrapper()
    expect(wrapper.text()).toContain(
      'OFFERs and WANTEDs are how you give and ask for things on Freegle'
    )
  })

  it('never says "properly" - that reads as a telling-off', () => {
    for (const msgtype of ['Wanted', 'Offer', undefined]) {
      const wrapper = createWrapper(msgtype ? { msgtype } : {})
      expect(wrapper.text()).not.toContain('properly')
    }
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
    const wrapper = createWrapper({ preview: true, msgtype: 'Wanted' })

    // The moderator is not the one going to My Posts.
    expect(wrapper.find('.b-button').attributes('disabled')).toBeDefined()

    // No route at all - NOT `to` bound to null. A null `to` still reaches the
    // router, which does `'path' in to` and throws, taking the page down as
    // soon as the convert modal opens.
    expect(wrapper.find('.b-button').attributes('data-to')).toBe('absent')

    // Preview must not change the wording - that is the whole point. That
    // includes the explanation of what a WANTED is, which is the part the
    // moderator most needs to see before it goes out in the member's name.
    expect(wrapper.text()).toContain(
      'One of our volunteers has posted a WANTED for you'
    )
    expect(wrapper.text()).toContain(
      'A WANTED is how you ask for something on Freegle'
    )
  })
})
