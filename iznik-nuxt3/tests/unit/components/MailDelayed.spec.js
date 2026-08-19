import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import MailDelayed from '~/components/MailDelayed.vue'

// The member-facing twin of ModMailDelayed. Same underlying fact - a domain our
// relay currently cannot deliver to - but a different audience, so the wording
// and the suppression rules are its own and are worth pinning.
const mockMe = ref(null)
vi.mock('~/composables/useMe', () => ({
  useMe: () => ({ me: mockMe }),
}))

vi.mock('~/composables/useTimeFormat', () => ({
  timeago: (val) => `ago:${val}`,
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
  return mount(MailDelayed, { global: { stubs } })
}

function words(wrapper) {
  return wrapper.text().replace(/\s+/g, ' ').trim()
}

describe('MailDelayed', () => {
  beforeEach(() => {
    mockMe.value = null
  })

  it('shows nothing to a logged-out visitor', () => {
    expect(render().find('.notice').exists()).toBe(false)
  })

  it('shows nothing to a member whose mail is flowing', () => {
    mockMe.value = { id: 1 }

    expect(render().find('.notice').exists()).toBe(false)
  })

  it('names the domain that is holding our mail up', async () => {
    mockMe.value = { id: 1, emaildeferred: { domain: 'yahoo.co.uk' } }

    const text = words(render())

    expect(text).toContain('yahoo.co.uk')
    expect(text).toContain('limiting how quickly it accepts our email')
  })

  it('says when the delay started, when we know', () => {
    mockMe.value = {
      id: 1,
      emaildeferred: { domain: 'yahoo.co.uk', since: '2026-08-15 16:38:00' },
    }

    expect(words(render())).toContain('this started ago:2026-08-15 16:38:00')
  })

  it('still shows the banner when we do not know when it started', () => {
    mockMe.value = { id: 1, emaildeferred: { domain: 'yahoo.co.uk' } }

    const text = words(render())

    expect(text).toContain('yahoo.co.uk')
    expect(text).not.toContain('this started')
  })

  // Both banners are fixed to the bottom of the viewport, so showing both puts
  // one on top of the other. Bouncing wins: it is the one the member can
  // actually do something about, where this one only asks them to wait.
  it('stands down while the bouncing banner is showing', () => {
    mockMe.value = {
      id: 1,
      bouncing: true,
      emaildeferred: { domain: 'yahoo.co.uk' },
    }

    expect(render().find('.notice').exists()).toBe(false)
  })

  it('reassures the member that nothing has been lost', () => {
    mockMe.value = { id: 1, emaildeferred: { domain: 'yahoo.co.uk' } }

    expect(words(render())).toContain('Nothing is lost')
  })
})
