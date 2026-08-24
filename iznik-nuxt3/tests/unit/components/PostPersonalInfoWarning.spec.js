import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PostPersonalInfoWarning from '~/components/PostPersonalInfoWarning.vue'

function createWrapper(props = {}) {
  return mount(PostPersonalInfoWarning, {
    props,
    global: {
      stubs: {
        NoticeMessage: {
          template:
            '<div class="notice-message" :data-variant="variant"><slot /></div>',
          props: ['variant'],
        },
      },
    },
  })
}

const GROUP_NO_RESTRICT = { id: 1, rules: '{}' }
const GROUP_RESTRICT = {
  id: 2,
  rules: JSON.stringify({ restrictpersonalinfo: true }),
}
const GROUP_RESTRICT_PARSED = {
  id: 3,
  rules: { restrictpersonalinfo: true },
}

describe('PostPersonalInfoWarning', () => {
  it('does not warn when group has no restrictpersonalinfo rule', () => {
    const wrapper = createWrapper({
      group: GROUP_NO_RESTRICT,
      text: 'Call me on 07700 900000',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('does not warn when group restricts but post has no personal info', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT,
      text: 'Nice red sofa, good condition',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('does not warn when group is null', () => {
    const wrapper = createWrapper({
      group: null,
      text: 'Call me on 07700 900000',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('warns when group restricts personal info and post contains a UK phone number', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT,
      text: 'Please call 07700 900000 to arrange collection',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(true)
    expect(wrapper.find('.notice-message').text()).toContain(
      'This group restricts personal info'
    )
  })

  it('warns when group restricts personal info and post contains +44 phone number', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT,
      text: 'Ring +447700900000 to collect',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(true)
  })

  it('warns when group restricts personal info and post contains an email address', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT,
      text: 'Email me at someone@example.com',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(true)
  })

  it('does not warn for freegle email addresses even when group restricts', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT,
      text: 'Contact admin@ilovefreegle.org',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })

  it('works when group.rules is already a parsed object (not a JSON string)', () => {
    const wrapper = createWrapper({
      group: GROUP_RESTRICT_PARSED,
      text: 'Call 07700 900000',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(true)
  })

  it('does not warn when restrictpersonalinfo is false', () => {
    const wrapper = createWrapper({
      group: { id: 4, rules: JSON.stringify({ restrictpersonalinfo: false }) },
      text: 'Call 07700 900000',
    })
    expect(wrapper.find('.notice-message').exists()).toBe(false)
  })
})
