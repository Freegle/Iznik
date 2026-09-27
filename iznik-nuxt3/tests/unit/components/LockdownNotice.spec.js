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

    expect(render().find('.notice').attributes('data-variant')).toBe(
      'danger'
    )
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

    expect(render().find('.notice').attributes('data-variant')).toBe(
      'warning'
    )
  })

  it('uses the warning variant for the back-to-normal notice', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'normal', text: 'Things are back to normal.' },
    })

    expect(render().find('.notice').attributes('data-variant')).toBe(
      'warning'
    )
  })

  it('has no dismiss button', () => {
    useLockdownStore.mockReturnValue({
      notice: { key: 'security', text: 'Security text' },
    })

    expect(render().find('.test-dismiss').exists()).toBe(false)
  })
})
