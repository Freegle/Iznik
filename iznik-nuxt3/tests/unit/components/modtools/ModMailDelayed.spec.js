import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMailDelayed from '~/modtools/components/ModMailDelayed.vue'

// NoticeMessage is auto-imported in the app; stub it here but keep the
// variant, because whether this reads as information or as a fault is the
// entire point of the component.
const NoticeMessage = {
  props: ['variant'],
  template: '<div class="notice" :data-variant="variant"><slot /></div>',
}

function render(props = {}) {
  return mount(ModMailDelayed, {
    props: {
      since: '2026-08-15 16:38:00',
      provider: 'Yahoo',
      count: 9,
      ...props,
    },
    global: { stubs: { NoticeMessage } },
  })
}

// The template wraps across lines, so compare on collapsed whitespace rather
// than let a reflow break the test.
function words(wrapper) {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

describe('ModMailDelayed', () => {
  it('names the provider and the date it started', () => {
    const text = words(render())

    expect(text).toContain('Email delayed since')
    expect(text).toContain('Yahoo is not currently accepting our mail')
  })

  it('says how much has been held back', () => {
    expect(words(render({ count: 9 }))).toContain('9 emails')
  })

  it('copes when we cannot name the provider', () => {
    expect(words(render({ provider: null }))).toContain(
      'their email provider is not currently accepting our mail',
    )
  })

  it('renders nothing at all when the member is not delayed', () => {
    expect(render({ since: null }).find('.notice').exists()).toBe(false)
  })

  // Why this is a separate component from ModBouncing rather than a variant
  // of it: moderators read "bouncing" as "this address is bad" and act on it,
  // chasing or removing the member. A deferral is our sending reputation, the
  // address is fine, and the only correct action is to wait.
  it('reads as information rather than as a fault, unlike bouncing', () => {
    expect(render().find('.notice').attributes('data-variant')).toBe('info')
  })

  it('tells the moderator there is nothing to fix', () => {
    const text = words(render())

    expect(text).toContain(
      'This is a problem at our end, not with their address',
    )
    expect(text).toContain('nothing for them or for you to fix')
  })

  it('promises a catch-up, so nobody goes chasing it by hand', () => {
    expect(words(render())).toContain("we'll send a catch-up once it clears")
  })
})
