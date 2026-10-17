import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const mockIsAutomodGroup = vi.fn()
vi.mock('@/modtools/composables/useAutoapproveTrial', () => ({
  useAutomod: () => ({ isAutomodGroup: mockIsAutomodGroup }),
}))

const mockPostAutomodFeedback = vi.fn()
const mockDeleteAutomodFeedback = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({
    postAutomodFeedback: mockPostAutomodFeedback,
    deleteAutomodFeedback: mockDeleteAutomodFeedback,
  }),
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

function messageWithAutomod(groupid, automod, approvedby = null, extra = {}) {
  return {
    id: 42,
    groups: [{ groupid, automod, approvedby, ...extra }],
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
    mockPostAutomodFeedback.mockResolvedValue({ success: true })
    mockDeleteAutomodFeedback.mockResolvedValue({ success: true })
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

    it('shows approve mode will publish after the wait, when the post is counting down', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve' },
        null,
        { collection: 'Pending', autoapproveat: '2026-10-11T12:00:00Z' }
      )
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review: will publish after the wait'
      )
    })

    // The API gives no countdown to a copy that is held, was pulled back to Pending, was
    // edited after the decision or whose member's status has changed. The line must not
    // promise a publication the card is not counting down to.
    it('does not promise publication when there is no countdown', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve' },
        null,
        { collection: 'Pending' }
      )
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'Automated review approved this, but it is not counting down (held, pulled back or edited since)'
      )
      expect(wrapper.text()).not.toContain('will publish after the wait')
    })

    it('says the same of an approved post a moderator is holding', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve' },
        null,
        { collection: 'Pending', heldby: 9 }
      )
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).not.toContain('will publish after the wait')
      expect(wrapper.text()).toContain('it is not counting down')
    })

    it('keeps shadow mode wording, which never counts down', () => {
      const message = messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'shadow' },
        null,
        { collection: 'Pending' }
      )
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain('Automated review: would approve')
      expect(wrapper.text()).not.toContain('not counting down')
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

    it('explains an approved decision that is held back as a quality-check sample', () => {
      const message = messageWithAutomod(7, {
        verdict: 'approve',
        mode: 'approve',
        qualitysample: true,
      })
      const wrapper = mountLine({ message, groupid: 7, pending: true })
      expect(wrapper.text()).toContain(
        'held back as a quality-check sample for a moderator'
      )
      expect(wrapper.text()).not.toContain('will publish after the wait')
    })
  })

  // The chart has not decided yet (a minute or two after the content check, longer with a
  // backlog). A moderator on a trial community should see that, not a blank card.
  describe('waiting for a decision', () => {
    const waiting = (extra = {}, message = {}) => ({
      id: 42,
      ...message,
      groups: [{ groupid: 7, collection: 'Pending', rippled_in: 0, ...extra }],
    })

    it('says so on a pending post the review has not reached', () => {
      const wrapper = mountLine({
        message: waiting(),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.text()).toContain(
        'Automated review: waiting for a decision'
      )
      expect(
        wrapper.find('[data-testid="automod-flag-published"]').exists()
      ).toBe(false)
    })

    it('says nothing on a community that is not in the trial', () => {
      mockIsAutomodGroup.mockReturnValue(false)
      const wrapper = mountLine({
        message: waiting(),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.find('.automod-line').exists()).toBe(false)
    })

    it('says nothing once the post is live', () => {
      const wrapper = mountLine({
        message: waiting({ collection: 'Approved' }),
        groupid: 7,
        pending: false,
      })
      expect(wrapper.find('.automod-line').exists()).toBe(false)
    })

    // The batch only reviews a direct copy nobody holds and nothing has flagged, so a
    // decision is not coming for these and the line must not say it is.
    it.each([
      ['a rippled-in copy', { rippled_in: 1 }, {}],
      ['a held copy', { heldby: 9 }, {}],
      ['a copy with its own spam reason', { spamreason: 'Pulled back' }, {}],
      ['a post with a spam reason', {}, { spamreason: 'Looks like spam' }],
    ])('says nothing for %s', (name, group, message) => {
      const wrapper = mountLine({
        message: waiting(group, message),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.find('.automod-line').exists()).toBe(false)
    })

    it('does not offer a modal there is nothing to put in', () => {
      const wrapper = mountLine({
        message: waiting(),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.find('.automod-line__why').exists()).toBe(false)
    })
  })

  // A community that has every post checked is given no decision at all: the batch records
  // the setting once so the card can say so. It must not read like the chart held the post.
  describe('a community that has every post checked', () => {
    const everyPost = {
      verdict: 'hold',
      mode: 'approve',
      end: 'GROUP_MODERATES_ALL',
      reason: 'This community has moderators check every post.',
    }

    it('says so on a pending post instead of calling it a hold', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, everyPost),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.text()).toContain(
        'No automated review: this community has every post checked by a moderator'
      )
      expect(wrapper.text()).not.toContain('holding')
    })

    it('says so in shadow mode too', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, { ...everyPost, mode: 'shadow' }),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.text()).toContain('No automated review')
      expect(wrapper.text()).not.toContain('would hold')
    })

    it('says so on a post a moderator approved', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, everyPost, 99),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - no automated review, as this community has every post checked'
      )
      expect(wrapper.text()).not.toContain('would have held')
    })

    it('says so on a post that went live without a moderator', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, everyPost, null, {
          collection: 'Approved',
        }),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Published without a moderator; no automated review, as this community has every post checked'
      )
    })
  })

  // The sample is held back for a moderator's verdict. The 48-hour sweep is its backstop, so
  // a sample nobody looked at goes live with no moderator behind it: that is the sample
  // working as designed, not the chart approving it.
  describe('a quality-check sample nobody looked at', () => {
    const sampled = {
      verdict: 'approve',
      mode: 'approve',
      end: 'APPROVE',
      qualitysample: true,
    }
    const live = { collection: 'Approved' }

    it('says it was published by the fallback, not approved by automated review', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, sampled, null, live),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Quality-check sample: published by the 48-hour fallback with no moderator verdict'
      )
      expect(wrapper.text()).not.toContain('Auto-approved by automated review')
    })

    it('cannot be flagged as an automated review mistake', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, sampled, null, live),
        groupid: 7,
      })
      expect(
        wrapper.find('[data-testid="automod-flag-published"]').exists()
      ).toBe(false)
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        false
      )
    })

    it('reads as before once a moderator approved it', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, sampled, 99, live),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review agreed'
      )
    })
  })

  it('keeps the robot and the words together, so a narrow card does not strand the icon', () => {
    const message = messageWithAutomod(7, {
      verdict: 'approve',
      mode: 'approve',
    })
    const wrapper = mountLine({ message, groupid: 7 })
    const text = wrapper.find('.automod-line__text')
    expect(text.exists()).toBe(true)
    expect(text.text()).toContain('Auto-approved by automated review')
    expect(text.find('v-icon-stub').exists()).toBe(true)
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

  // The service being down is recorded as a hold with end UNAVAILABLE so the post waits
  // for a moderator; it is not a decision and must not read like one.
  describe('review could not run', () => {
    const unavailable = {
      verdict: 'hold',
      mode: 'approve',
      end: 'UNAVAILABLE',
      reason: 'Automated review unavailable',
    }

    it('says so on a pending post instead of calling it a hold', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable),
        groupid: 7,
        pending: true,
      })
      expect(wrapper.text()).toContain(
        'Automated review could not run; waiting for a moderator (it will retry)'
      )
      expect(wrapper.text()).not.toContain('holding')
    })

    it('says so on a post that went live without a moderator', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable, null, {
          collection: 'Approved',
        }),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Published without a moderator; automated review could not run'
      )
    })

    it('says so on a post a moderator approved', () => {
      const wrapper = mountLine({
        message: messageWithAutomod(7, unavailable, 99),
        groupid: 7,
      })
      expect(wrapper.text()).toContain(
        'Approved by a moderator - automated review could not run'
      )
    })
  })

  // Live with nobody's approval on it, against a stored hold - the 48-hour fallback, or
  // the clock after a late review. The case the trial exists to count.
  it('shows a stored hold on a post that published with no moderator', () => {
    const wrapper = mountLine({
      message: messageWithAutomod(
        7,
        { verdict: 'hold', mode: 'approve', reason: 'asks for money' },
        null,
        { collection: 'Approved' }
      ),
      groupid: 7,
    })
    expect(wrapper.text()).toContain(
      'Published without a moderator; automated review would have held: asks for money'
    )
  })

  describe('Should not have published', () => {
    const autoApproved = (extra = {}) =>
      messageWithAutomod(
        7,
        { verdict: 'approve', mode: 'approve', end: 'APPROVE', ...extra },
        null,
        { collection: 'Approved' }
      )
    const flagButton = (wrapper) =>
      wrapper.find('[data-testid="automod-flag-published"]')

    it('offers one click on a post that published itself', () => {
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      expect(flagButton(wrapper).exists()).toBe(true)
    })

    it('records feedback against the decision and says so', async () => {
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      await flagButton(wrapper).trigger('click')
      await flushPromises()
      expect(mockPostAutomodFeedback).toHaveBeenCalledWith({
        msgid: 42,
        groupid: 7,
        node: 'APPROVE',
      })
      expect(flagButton(wrapper).exists()).toBe(false)
      expect(wrapper.find('[data-testid="automod-flagged"]').text()).toContain(
        'Noted: should not have published'
      )
    })

    it('starts marked when this moderator already said so', () => {
      const wrapper = mountLine({
        message: autoApproved({ feedback: ['APPROVE'] }),
        groupid: 7,
      })
      expect(flagButton(wrapper).exists()).toBe(false)
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        true
      )
    })

    it('takes the mark back, and offers the button again', async () => {
      const wrapper = mountLine({
        message: autoApproved({ feedback: ['APPROVE'] }),
        groupid: 7,
      })
      const undo = wrapper.find('[data-testid="automod-unflag-published"]')
      expect(undo.exists()).toBe(true)

      await undo.trigger('click')
      await flushPromises()

      expect(mockDeleteAutomodFeedback).toHaveBeenCalledWith({
        msgid: 42,
        groupid: 7,
        node: 'APPROVE',
      })
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        false
      )
      expect(flagButton(wrapper).exists()).toBe(true)
    })

    it('can be marked again after an undo, and a reload does not undo the undo', async () => {
      const wrapper = mountLine({
        message: autoApproved({ feedback: ['APPROVE'] }),
        groupid: 7,
      })
      await wrapper
        .find('[data-testid="automod-unflag-published"]')
        .trigger('click')
      await flushPromises()

      await flagButton(wrapper).trigger('click')
      await flushPromises()

      expect(mockPostAutomodFeedback).toHaveBeenCalledTimes(1)
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        true
      )
    })

    it('stays marked when the undo fails, and says it did not go through', async () => {
      mockDeleteAutomodFeedback.mockRejectedValue(new Error('500'))
      const wrapper = mountLine({
        message: autoApproved({ feedback: ['APPROVE'] }),
        groupid: 7,
      })
      await wrapper
        .find('[data-testid="automod-unflag-published"]')
        .trigger('click')
      await flushPromises()

      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        true
      )
      expect(
        wrapper.find('[data-testid="automod-feedback-error"]').exists()
      ).toBe(true)
    })

    it('says so when the mark does not go through, and leaves the button to try again', async () => {
      mockPostAutomodFeedback.mockRejectedValue(new Error('500'))
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      await flagButton(wrapper).trigger('click')
      await flushPromises()

      expect(flagButton(wrapper).exists()).toBe(true)
      expect(wrapper.find('[data-testid="automod-flagged"]').exists()).toBe(
        false
      )
      expect(
        wrapper.find('[data-testid="automod-feedback-error"]').exists()
      ).toBe(true)
    })

    it('announces the change to a screen reader', () => {
      const wrapper = mountLine({ message: autoApproved(), groupid: 7 })
      expect(wrapper.find('[aria-live="polite"]').exists()).toBe(true)
    })

    it('is not offered on a post a moderator approved, a pending post, or a shadow decision', () => {
      expect(
        flagButton(
          mountLine({
            message: messageWithAutomod(
              7,
              { verdict: 'approve', mode: 'approve', end: 'APPROVE' },
              99
            ),
            groupid: 7,
          })
        ).exists()
      ).toBe(false)
      expect(
        flagButton(
          mountLine({ message: autoApproved(), groupid: 7, pending: true })
        ).exists()
      ).toBe(false)
      expect(
        flagButton(
          mountLine({ message: autoApproved({ mode: 'shadow' }), groupid: 7 })
        ).exists()
      ).toBe(false)
    })
  })
})
