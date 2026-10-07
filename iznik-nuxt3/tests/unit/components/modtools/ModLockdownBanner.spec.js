import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import ModLockdownBanner from '~/modtools/components/ModLockdownBanner.vue'
import { useLockdownStore } from '~/stores/lockdown'

// The ModTools-wide banner (plans/active/2026-09-27-lockdown-switch.md
// section 10.8: "Members: nothing, by default. Moderators: always the
// banner.") Unlike LockdownNotice.vue on the member site, this is never a
// choice made by the presser - it shows whenever a lockdown is active, so a
// moderator always knows why some Approve buttons have gone missing.
vi.mock('~/stores/lockdown', () => ({
  useLockdownStore: vi.fn(() => ({ active: false, reason: null })),
}))

const stubs = {
  'v-icon': { template: '<i />', props: ['icon'] },
  NoticeMessage: {
    props: ['variant'],
    template: '<div class="notice" :data-variant="variant"><slot /></div>',
  },
}

function render() {
  return mount(ModLockdownBanner, { global: { stubs } })
}

describe('ModLockdownBanner', () => {
  beforeEach(() => {
    useLockdownStore.mockReturnValue({ active: false, reason: null })
  })

  it('shows nothing when there is no lockdown', () => {
    expect(render().find('.notice').exists()).toBe(false)
  })

  it('shows the banner when a lockdown is active', () => {
    useLockdownStore.mockReturnValue({ active: true, reason: null })

    const wrapper = render()

    expect(wrapper.find('.notice').exists()).toBe(true)
    expect(wrapper.text()).toContain('Lockdown is active')
  })

  it('uses the danger variant, not just a warning', () => {
    useLockdownStore.mockReturnValue({ active: true, reason: null })

    expect(render().find('.notice').attributes('data-variant')).toBe(
      'danger'
    )
  })

  it('includes the reason the presser gave, when there is one', () => {
    useLockdownStore.mockReturnValue({
      active: true,
      reason: 'Spam wave from a new domain',
    })

    expect(render().text()).toContain('Spam wave from a new domain')
  })

  it('says nothing extra when no reason was given', () => {
    useLockdownStore.mockReturnValue({ active: true, reason: null })

    const wrapper = render()

    expect(wrapper.text()).toContain('Lockdown is active.')
  })
})
