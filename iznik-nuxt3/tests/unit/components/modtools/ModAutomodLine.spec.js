import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const mockIsAutomodGroup = vi.fn()
vi.mock('@/modtools/composables/useAutoapproveTrial', () => ({
  useAutomod: () => ({ isAutomodGroup: mockIsAutomodGroup }),
}))

const mockModalShow = vi.fn()
vi.mock('@/modtools/components/ModAutomodModal.vue', () => ({
  default: {
    name: 'ModAutomodModal',
    props: ['msgid', 'groupid', 'automod'],
    template: '<div class="automod-modal-stub" />',
    methods: { show: mockModalShow },
  },
}))

// Imported after the mocks above so the component picks up the stubbed modal.
const { default: ModAutomodLine } =
  await import('~/modtools/components/ModAutomodLine.vue')

const stubs = {
  'b-button': {
    template: '<button @click="$emit(\'click\')"><slot /></button>',
  },
  'v-icon': true,
}

function messageWithAutomod(groupid, automod, approvedby = null) {
  return {
    id: 42,
    groups: [{ groupid, automod, approvedby }],
  }
}

function mountLine(props) {
  return mount(ModAutomodLine, {
    props: {
      message: null,
      groupid: 7,
      pending: false,
      ...props,
    },
    global: { stubs },
  })
}

describe('ModAutomodLine', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockIsAutomodGroup.mockReturnValue(true)
  })

  it('renders nothing for a community not in the automod trial', () => {
    mockIsAutomodGroup.mockReturnValue(false)
    const message = messageWithAutomod(7, {
      verdict: 'approve',
      mode: 'approve',
    })
    const wrapper = mountLine({ message, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  it('renders nothing when the group has no automod data', () => {
    const message = messageWithAutomod(7, null)
    const wrapper = mountLine({ message, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  it('renders nothing when there is no message at all', () => {
    const wrapper = mountLine({ message: null, groupid: 7 })
    expect(wrapper.find('.automod-line').exists()).toBe(false)
  })

  describe('decided view', () => {
    it('shows auto-approved when the verdict is approve, mode is not shadow, and no moderator approved it', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
      })
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain('Auto-approved by automated review')
    })

    it('shows moderator agreed when the verdict is approve and a moderator approved it', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve' },
        99
      )
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review agreed'
      )
    })

    it('shows the hold reason when the verdict is hold but a moderator approved it', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'hold', mode: 'approve', reason: 'looks like spam' },
        99
      )
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review would have held: looks like spam'
      )
    })

    it('shows what shadow mode would have approved, when nobody has approved it yet', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'shadow',
      })
      const wrapper = mountLine({ message, groupid: 7 })
      expect(wrapper.text()).toContain('Automated review would have approved')
    })
  })

  describe('pending view', () => {
    it('shows shadow mode would approve', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'shadow',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain('Automated review: would approve')
    })

    it('shows shadow mode would hold with the reason', () => {
      const message = messageWithAutomod(7, {
        verdict: 'hold',
        mode: 'shadow',
        reason: 'no photo',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: would hold - no photo'
      )
    })

    it('shows approve mode will publish after the wait', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: will publish after the wait'
      )
    })

    it('shows approve mode holding with the reason', () => {
      const message = messageWithAutomod(7, {
        verdict: 'hold',
        mode: 'approve',
        reason: 'suspicious wording',
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: holding - suspicious wording'
      )
    })
  })

  it('opens the modal when Why? is clicked', async () => {
    const message = messageWithAutomod(7, {
      verdict: 'approve',
      mode: 'approve',
    })
    const wrapper = mountLine({ message, groupid: 7 })
    await wrapper.find('button').trigger('click')
    expect(mockModalShow).toHaveBeenCalled()
  })
})
