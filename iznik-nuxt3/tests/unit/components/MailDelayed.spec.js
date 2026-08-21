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

const mockVals = ref({})
const mockSet = vi.fn((params) => {
  mockVals.value[params.key] = params.value
})
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get vals() {
      return mockVals.value
    },
    set: mockSet,
  }),
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
    mockVals.value = {}
    mockSet.mockClear()
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

  // Andy (#41600618) could not press Send on a phone: this banner is fixed to the
  // bottom of the viewport, so it sits over the button, and there was no way to
  // get rid of it. Three different browsers behaved the same way, because the
  // banner - not the browser - was the problem.
  it('offers a way to get rid of it', () => {
    mockMe.value = { id: 1, emaildeferred: { domain: 'yahoo.co.uk' } }

    expect(render().find('.test-dismiss').exists()).toBe(true)
  })

  it('goes away when dismissed', async () => {
    mockMe.value = { id: 1, emaildeferred: { domain: 'yahoo.co.uk' } }

    const wrapper = render()
    await wrapper.find('.test-dismiss').trigger('click')

    expect(wrapper.find('.notice').exists()).toBe(false)
  })

  it('stays gone on the next page', () => {
    mockMe.value = {
      id: 1,
      emaildeferred: { domain: 'yahoo.co.uk', since: '2026-08-15 16:38:00' },
    }
    mockVals.value = { mailDelayedDismissed: 'yahoo.co.uk:2026-08-15 16:38:00' }

    expect(render().find('.notice').exists()).toBe(false)
  })

  // Dismissing says "I have read this one", not "never tell me about mail again".
  it('speaks up again for a later delay', () => {
    mockVals.value = { mailDelayedDismissed: 'yahoo.co.uk:2026-08-15 16:38:00' }
    mockMe.value = {
      id: 1,
      emaildeferred: { domain: 'yahoo.co.uk', since: '2026-08-20 09:00:00' },
    }

    expect(render().find('.notice').exists()).toBe(true)
  })

  it('speaks up again when a different domain starts holding mail up', () => {
    mockVals.value = { mailDelayedDismissed: 'yahoo.co.uk:2026-08-15 16:38:00' }
    mockMe.value = {
      id: 1,
      emaildeferred: { domain: 'btinternet.com', since: '2026-08-15 16:38:00' },
    }

    expect(render().find('.notice').exists()).toBe(true)
  })

  it('remembers the dismissal so it survives a reload', async () => {
    mockMe.value = {
      id: 1,
      emaildeferred: { domain: 'yahoo.co.uk', since: '2026-08-15 16:38:00' },
    }

    await render().find('.test-dismiss').trigger('click')

    expect(mockSet).toHaveBeenCalledWith({
      key: 'mailDelayedDismissed',
      value: 'yahoo.co.uk:2026-08-15 16:38:00',
    })
  })
})
