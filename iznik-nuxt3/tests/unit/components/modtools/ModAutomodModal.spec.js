import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import ModAutomodModal from '~/modtools/components/ModAutomodModal.vue'

const mockPostAutomodFeedback = vi.fn()
vi.mock('@/stores/message', () => ({
  useMessageStore: () => ({ postAutomodFeedback: mockPostAutomodFeedback }),
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
    global: { stubs },
  })
}

describe('ModAutomodModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockPostAutomodFeedback.mockResolvedValue({})
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
    await node.find('button').trigger('click')
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
})
