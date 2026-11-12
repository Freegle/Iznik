import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModAutomodModal from '~/modtools/components/ModAutomodModal.vue'

const mockPostAutomodFeedback = vi.fn()
const mockDeleteAutomodFeedback = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({
    postAutomodFeedback: mockPostAutomodFeedback,
    deleteAutomodFeedback: mockDeleteAutomodFeedback,
  }),
}))

vi.mock('@/composables/useOurModal', () => ({
  useOurModal: () => ({ modal: ref(null), show: vi.fn(), hide: vi.fn() }),
}))

const stubs = {
  'b-modal': { template: '<div class="modal-stub"><slot /></div>' },
  'b-button': {
    template: '<button @click="$emit(\'click\')"><slot /></button>',
  },
}

const held = {
  verdict: 'hold',
  mode: 'approve',
  version: '3',
  created: '2026-09-27T10:00:00Z',
  reason: 'Asks to borrow - this community does not allow loans',
  path: [
    {
      node: 'VETO',
      question:
        'Does the member have a reason on record to review their posts?',
      kind: 'fact',
      answer: 'no',
      p: null,
      threshold: null,
      model: 'fact',
      evidence: 'false',
    },
    {
      node: 'LOAN',
      question: 'Is it a loan or a request to borrow?',
      kind: 'text',
      answer: 'yes',
      p: 0.91,
      threshold: 0.7,
      model: 'claude',
      evidence: 'lend me a ladder',
    },
  ],
}

function mountModal(automod) {
  return mount(ModAutomodModal, {
    props: { msgid: 42, groupid: 7, automod },
    global: { stubs, directives: { 'b-tooltip': {} } },
  })
}

describe('ModAutomodModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPostAutomodFeedback.mockResolvedValue({})
    mockDeleteAutomodFeedback.mockResolvedValue({})
  })

  it('lists every node of the path with its question and answer', () => {
    const wrapper = mountModal(held)
    const nodes = wrapper.findAll('.automod-node')
    expect(nodes).toHaveLength(2)
    expect(nodes[0].text()).toContain('reason on record')
    expect(nodes[0].text()).toContain('No')
    expect(nodes[1].text()).toContain('Is it a loan')
    expect(nodes[1].text()).toContain('Yes')
  })

  it('shows confidence against the threshold for text nodes only', () => {
    const wrapper = mountModal(held)
    const nodes = wrapper.findAll('.automod-node')
    expect(nodes[1].text()).toContain('91% sure, holds above 70%')
    expect(nodes[0].text()).not.toContain('% sure')
  })

  it('highlights the node that held the post', () => {
    const wrapper = mountModal(held)
    const nodes = wrapper.findAll('.automod-node')
    expect(nodes[1].classes()).toContain('automod-node--deciding')
    expect(nodes[0].classes()).not.toContain('automod-node--deciding')
  })

  it('highlights nothing on an approved post', () => {
    const wrapper = mountModal({ ...held, verdict: 'approve' })
    expect(wrapper.find('.automod-node--deciding').exists()).toBe(false)
  })

  it('shows the chart version', () => {
    expect(mountModal(held).text()).toContain('Chart version 3')
  })

  it('records "this step is wrong" and thanks the moderator', async () => {
    const wrapper = mountModal(held)
    const node = wrapper.findAll('.automod-node')[1]
    await node.find('button').trigger('click') // the "wrong?" link
    await flushPromises()
    expect(mockPostAutomodFeedback).toHaveBeenCalledWith({
      msgid: 42,
      groupid: 7,
      node: 'LOAN',
    })
    expect(wrapper.findAll('.automod-node')[1].text()).toContain(
      'Thanks, noted'
    )
  })

  // The server sends back the nodes this moderator has already marked, so the mark
  // survives a reload and there is no second click to make a second row.
  it('starts with the steps this moderator already marked', () => {
    const wrapper = mountModal({ ...held, feedback: ['LOAN'] })
    const nodes = wrapper.findAll('.automod-node')
    expect(nodes[1].text()).toContain('Thanks, noted')
    expect(nodes[1].find('[data-testid="automod-wrong"]').exists()).toBe(false)
    expect(nodes[0].find('[data-testid="automod-wrong"]').exists()).toBe(true)
  })

  it('posts once however many times the link is clicked', async () => {
    let release
    mockPostAutomodFeedback.mockReturnValue(
      new Promise((resolve) => {
        release = resolve
      })
    )
    const wrapper = mountModal(held)
    const button = wrapper.findAll('.automod-node')[1].find('button')
    await button.trigger('click')
    await button.trigger('click')
    release({ success: true, already: true })
    await flushPromises()
    expect(mockPostAutomodFeedback).toHaveBeenCalledTimes(1)
    expect(wrapper.findAll('.automod-node')[1].text()).toContain(
      'Thanks, noted'
    )
  })

  it('says what happened first', () => {
    expect(mountModal(held).text()).toContain('Held: Asks to borrow')
    expect(mountModal({ ...held, mode: 'shadow' }).text()).toContain(
      'Would hold: Asks to borrow'
    )
    expect(mountModal({ ...held, verdict: 'approve' }).text()).toContain(
      'Approved: no question'
    )
  })

  it('quotes evidence from the post, but shows a fact finding plainly', () => {
    const wrapper = mountModal({
      ...held,
      path: [
        {
          node: 'MOD_NOTE',
          question: 'Has a moderator left a note about this member?',
          kind: 'fact',
          answer: 'yes',
          model: 'fact',
          evidence: 'Note left 3 Sep 2026',
        },
        {
          node: 'LOAN',
          question: 'Is it a loan?',
          kind: 'text',
          answer: 'yes',
          p: 0.9,
          threshold: 0.7,
          model: 'claude',
          evidence: 'lend me a ladder',
        },
      ],
    })
    const text = wrapper.text()
    expect(text).toContain('Note left 3 Sep 2026')
    expect(text).not.toContain('"Note left')
    expect(text).toContain('"lend me a ladder"')
  })

  // A note the chart wrote about its own step is not a quote from the post.
  it('does not put quotation marks round a note the chart wrote itself', () => {
    const wrapper = mountModal({
      ...held,
      path: [
        {
          node: 'PHONE',
          question: 'Does it contain a phone number?',
          kind: 'text',
          answer: 'no',
          model: 'rule',
          evidence: 'This community does not restrict it',
        },
        {
          node: 'SALE',
          question: 'Does it read like a sale advert?',
          kind: 'text',
          answer: 'no',
          model: 'skipped',
          evidence: 'Does not apply to this post',
        },
        {
          node: 'LOAN',
          question: 'Is it a loan?',
          kind: 'text',
          answer: 'yes',
          p: 0.9,
          threshold: 0.7,
          model: 'claude',
          evidence: 'lend me a ladder',
        },
      ],
    })
    const text = wrapper.text()
    expect(text).toContain('This community does not restrict it')
    expect(text).not.toContain('"This community does not restrict it"')
    expect(text).toContain('Does not apply to this post')
    expect(text).not.toContain('"Does not apply to this post"')
    expect(text).toContain('"lend me a ladder"')
  })

  it("says a deciding fact came from Freegle's records, and a deciding question who answered it", () => {
    const fact = mountModal({
      ...held,
      path: [
        {
          node: 'MOD_NOTE',
          question: 'Has a moderator left a note about this member?',
          kind: 'fact',
          answer: 'yes',
          model: 'fact',
          evidence: 'Note left 3 Sep 2026',
        },
      ],
    })
    expect(fact.text()).toContain("Answered from Freegle's records")
    expect(fact.text()).not.toContain('Answered by fact')

    expect(mountModal(held).text()).toContain('Answered by claude')
  })

  // The batch records a review that could not run, or a community that has every post
  // checked, with no path and no chart version. Neither is a hold the chart decided on.
  describe('a review that did not run', () => {
    const batchUnavailable = {
      verdict: 'hold',
      mode: 'approve',
      end: 'UNAVAILABLE',
      version: '',
      created: '2026-09-27T10:00:00Z',
      reason: 'Automated review unavailable',
      path: [],
    }

    it('says it made no decision, rather than that it held the post', () => {
      const text = mountModal(batchUnavailable).text()
      expect(text).toContain('Automated review could not run')
      expect(text).toContain('made no decision')
      expect(text).not.toContain('Held:')
    })

    it('does not describe questions or a chart version that never existed', () => {
      const wrapper = mountModal(batchUnavailable)
      expect(wrapper.text()).not.toContain('The questions below')
      expect(wrapper.text()).not.toContain('Chart version')
      expect(wrapper.text()).toContain('ran ')
      expect(wrapper.findAll('.automod-node')).toHaveLength(0)
    })

    it('says a service that failed part way did not answer, not that it said yes', () => {
      const wrapper = mountModal({
        ...batchUnavailable,
        version: '5',
        path: [
          {
            node: 'MONEY',
            question: 'Does it mention money, selling or buying?',
            kind: 'text',
            answer: 'yes',
            p: 1,
            threshold: 0.7,
            model: 'unavailable',
            evidence: '',
            error: 'timed out',
          },
        ],
      })
      const step = wrapper.find('.automod-node')
      expect(step.text()).toContain('Could not answer')
      expect(step.text()).not.toContain('Yes')
      expect(step.text()).not.toContain('% sure')
      expect(step.text()).toContain('could not be answered')
      expect(step.text()).not.toContain('Answered by unavailable')
      expect(step.find('[data-testid="automod-wrong"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Chart version 5')
    })
  })

  it('says a community that has every post checked was not looked at', () => {
    const wrapper = mountModal({
      verdict: 'hold',
      mode: 'approve',
      end: 'GROUP_MODERATES_ALL',
      version: '',
      created: '2026-09-27T10:00:00Z',
      reason: 'This community has moderators check every post.',
      path: [],
    })
    const text = wrapper.text()
    expect(text).toContain('did not look at this post')
    expect(text).toContain('moderator check every post')
    expect(text).not.toContain('Held:')
    expect(text).not.toContain('The questions below')
  })

  // Marking is not permanent: the moderator who clicked can take it back.
  describe('taking a mark back', () => {
    it('offers an undo beside a mark, and puts the link back when it is used', async () => {
      const wrapper = mountModal({ ...held, feedback: ['LOAN'] })
      const node = () => wrapper.findAll('.automod-node')[1]
      await node()
        .find('[data-testid="automod-undo-feedback"]')
        .trigger('click')
      await flushPromises()

      expect(mockDeleteAutomodFeedback).toHaveBeenCalledWith({
        msgid: 42,
        groupid: 7,
        node: 'LOAN',
      })
      expect(node().text()).not.toContain('Thanks, noted')
      expect(node().find('[data-testid="automod-wrong"]').exists()).toBe(true)
    })

    it('offers the undo after marking on this screen too', async () => {
      const wrapper = mountModal(held)
      const node = () => wrapper.findAll('.automod-node')[1]
      await node().find('[data-testid="automod-wrong"]').trigger('click')
      await flushPromises()
      expect(
        node().find('[data-testid="automod-undo-feedback"]').exists()
      ).toBe(true)
    })

    it('stays marked, and says so, when the undo does not go through', async () => {
      mockDeleteAutomodFeedback.mockRejectedValue(new Error('500'))
      const wrapper = mountModal({ ...held, feedback: ['LOAN'] })
      await wrapper
        .findAll('.automod-node')[1]
        .find('[data-testid="automod-undo-feedback"]')
        .trigger('click')
      await flushPromises()

      expect(wrapper.findAll('.automod-node')[1].text()).toContain(
        'Thanks, noted'
      )
      expect(
        wrapper.find('[data-testid="automod-feedback-error"]').exists()
      ).toBe(true)
    })

    it('says so when a mark does not go through, and leaves the link to try again', async () => {
      mockPostAutomodFeedback.mockRejectedValue(new Error('500'))
      const wrapper = mountModal(held)
      const node = () => wrapper.findAll('.automod-node')[1]
      await node().find('[data-testid="automod-wrong"]').trigger('click')
      await flushPromises()

      expect(node().text()).not.toContain('Thanks, noted')
      expect(node().find('[data-testid="automod-wrong"]').exists()).toBe(true)
      expect(
        wrapper.find('[data-testid="automod-feedback-error"]').exists()
      ).toBe(true)
    })

    it('does not undo a step twice however many times it is clicked', async () => {
      let release
      mockDeleteAutomodFeedback.mockReturnValue(
        new Promise((resolve) => {
          release = resolve
        })
      )
      const wrapper = mountModal({ ...held, feedback: ['LOAN'] })
      const button = wrapper
        .findAll('.automod-node')[1]
        .find('[data-testid="automod-undo-feedback"]')
      await button.trigger('click')
      await button.trigger('click')
      release({ success: true })
      await flushPromises()
      expect(mockDeleteAutomodFeedback).toHaveBeenCalledTimes(1)
    })
  })

  it('names the question on each "wrong?" link, for a screen reader', () => {
    const wrapper = mountModal(held)
    const label = wrapper
      .findAll('.automod-node')[1]
      .find('[data-testid="automod-wrong"]')
      .attributes('aria-label')
    expect(label).toContain('Is it a loan or a request to borrow?')
  })
})
