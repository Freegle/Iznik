import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LockdownNotice from '~/components/LockdownNotice.vue'
import { useLockdownStore } from '~/stores/lockdown'

// The member-facing banner for a pressed lockdown switch (see
// plans/active/2026-09-27-lockdown-switch.md section 10.8). Unlike
// MailDelayed, this is never dismissible - it is only shown at all when
// Support has deliberately chosen to warn members, most often about a
// security incident, and it should stay up for as long as that choice
// stands.
vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: vi.fn(() => ({ notice: null })),
}))

const stubs = {
  'b-row': { template: '<div class="row"><slot /></div>' },
  'b-col': { template: '<div class="col"><slot /></div>' },
  'v-icon': { template: '<i />', props: ['icon'] },
  NoticeMessage: {
    props: ['variant'],
    template: '<div class="notice" :data-variant="variant"><slot /></div>',
  },
}

function render() {
  return mount(LockdownNotice, { global: { stubs } })
}

describe('LockdownNotice', () => {
  beforeEach(() => {
    useLockdownStore.mockReturnValue({ notice: null })
  })

  it('shows nothing when there is no notice', () => {
    expect(render().find('.notice').exists()).toBe(false)
  })

  it('shows the security notice text', () => {
    useLockdownStore.mockReturnValue({
      notice: {
        key: 'security',
        text: "We're dealing with a spam attack. Messages may be delayed. If you received a message about vouchers or payments, please don't click the link.",
      },
    })

    const wrapper = render()

    expect(wrapper.find('.notice').exists()).toBe(true)
    expect(wrapper.text()).toContain("We're dealing with a spam attack")
    expect(wrapper.text()).toContain("please don't click the link")
  })

  it('uses the danger variant for a security notice', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'security', text: 'Security text' },
    })

    expect(render().find('.notice').attributes('data-variant')).toBe('danger')
  })

  it('shows the delay notice text', () => {
    useLockdownStore.mockReturnValue({
      notice: {
        key: 'delay',
        text: 'Freegle is running slowly today. Messages and posts may take longer than usual to reach people.',
      },
    })

    const wrapper = render()

    expect(wrapper.find('.notice').exists()).toBe(true)
    expect(wrapper.text()).toContain('Freegle is running slowly today')
  })

  it('uses the warning variant for a delay notice', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'delay', text: 'Delay text' },
    })

    expect(render().find('.notice').attributes('data-variant')).toBe('warning')
  })

  it('uses the warning variant for the back-to-normal notice', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'normal', text: 'Things are back to normal.' },
    })

    expect(render().find('.notice').attributes('data-variant')).toBe('warning')
  })

  it('has no dismiss button', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'security', text: 'Security text' },
    })

    expect(render().find('.test-dismiss').exists()).toBe(false)
  })

  it('renders in normal flow, not as a fixed-position overlay', () => {
    // MailDelayed and BouncingEmail pin themselves to the bottom of the
    // viewport with the "bottom verytop" pattern, and MailDelayed's own
    // comment admits that covers whatever is down there, including the
    // reply composer's Send button - a cost it can only pay because it is
    // dismissible. LockdownNotice is never dismissible, so it must never
    // carry that positioning: it has to sit in the page's normal flow
    // instead (see LayoutCommon.vue, top of main.pageContent). jsdom has no
    // layout engine, so this can only check the class list, not real pixel
    // positions - the actual proof is the Playwright run over a browser.
    useLockdownStore.mockReturnValue({
      notice: { key: 'security', text: 'Security text' },
    })

    const wrapper = render()
    const classes = wrapper.classes()

    expect(classes).toContain('lockdown')
    expect(classes).not.toContain('bottom')
    expect(classes).not.toContain('verytop')
    expect(wrapper.find('.bottom').exists()).toBe(false)
    expect(wrapper.find('.verytop').exists()).toBe(false)
  })
})
