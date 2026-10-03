import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { bootstrapStubs } from '../../mocks/bootstrap-stubs'
import { ref } from 'vue'

const mockMessages = {}
const mockUsers = {}

const mockMessageStore = {
  byId: (id) => mockMessages[id] || null,
  fetch: vi.fn().mockResolvedValue(null),
  patch: vi.fn().mockImplementation(async (params) => {
    const existing = mockMessages[params.id] || {}
    if (params.action === 'TakeDown') {
      mockMessages[params.id] = {
        ...existing,
        deleted: '2026-09-20T12:00:00Z',
        spamreason: params.reason,
      }
    } else if (params.action === 'Restore') {
      mockMessages[params.id] = {
        ...existing,
        deleted: null,
        collection: 'Approved',
      }
    } else if (params.action === 'Edit') {
      mockMessages[params.id] = {
        ...existing,
        subject: params.subject,
        textbody: params.textbody,
      }
    }
    return mockMessages[params.id]
  }),
}

const mockUserStore = {
  byId: (id) => mockUsers[id] || null,
  fetch: vi.fn().mockResolvedValue(null),
}

vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

vi.mock('~/stores/user', () => ({
  useUserStore: () => mockUserStore,
}))

vi.mock('~/stores/member', () => ({
  useMemberStore: () => ({ list: {} }),
}))

vi.mock('~/modtools/composables/useModMe', () => ({
  useModMe: () => ({
    hasPermissionSpamAdmin: ref(false),
  }),
}))

import ModMessage from '~/modtools/components/ModMessage.vue'

function setMessage(overrides = {}) {
  const message = {
    id: 1,
    type: 'Offer',
    subject: 'Sofa',
    textbody: 'Free to a good home',
    arrival: '2026-09-20T09:00:00Z',
    deleted: null,
    collection: 'Approved',
    contentcheck_reasons: null,
    attachments: [],
    fromuser: 42,
    ...overrides,
  }
  mockMessages[message.id] = message
  return message
}

function setUser(overrides = {}) {
  const user = {
    id: 42,
    displayname: 'Jo Bloggs',
    spammer: null,
    ...overrides,
  }
  mockUsers[user.id] = user
  return user
}

function mountComponent(props = {}) {
  return mount(ModMessage, {
    props: { messageid: 1, ...props },
    global: {
      stubs: {
        ...bootstrapStubs,
        NoticeMessage: {
          template: '<div class="notice"><slot /></div>',
        },
        ModPhoto: true,
        ModSpammer: true,
      },
    },
  })
}

describe('ModMessage', () => {
  beforeEach(() => {
    Object.keys(mockMessages).forEach((k) => delete mockMessages[k])
    Object.keys(mockUsers).forEach((k) => delete mockUsers[k])
    vi.clearAllMocks()
  })

  it('shows the subject and body of a published message', () => {
    setMessage()
    setUser()
    const wrapper = mountComponent()

    expect(wrapper.text()).toContain('Sofa')
    expect(wrapper.text()).toContain('Free to a good home')
    expect(wrapper.text()).toContain('Published')
  })

  it('never says pending, awaiting or queue', () => {
    setMessage()
    setUser()
    const wrapper = mountComponent()

    const text = wrapper.text().toLowerCase()
    expect(text).not.toContain('pending')
    expect(text).not.toContain('awaiting')
    expect(text).not.toContain('queue')
  })

  it('shows the takedown reason for a taken down message, not the word approve', () => {
    setMessage({
      deleted: '2026-09-19T08:00:00Z',
      spamreason: 'Reported as a scam',
    })
    setUser()
    const wrapper = mountComponent()

    expect(wrapper.text()).toContain('Taken down')
    expect(wrapper.text()).toContain('Reported as a scam')
    expect(wrapper.text().toLowerCase()).not.toContain('approve')
  })

  it('offers Restore, not Release or Approve, for a taken down message', () => {
    setMessage({ deleted: '2026-09-19T08:00:00Z' })
    setUser()
    const wrapper = mountComponent()

    expect(wrapper.text()).toContain('Restore')
    expect(wrapper.text()).not.toContain('Release')
    expect(wrapper.text()).not.toContain('Approve')
  })

  it('restores a taken down message via the store', async () => {
    setMessage({ deleted: '2026-09-19T08:00:00Z' })
    setUser()
    const wrapper = mountComponent()

    await wrapper.find('button').trigger('click')
    await flushPromises()

    expect(mockMessageStore.patch).toHaveBeenCalledWith({
      id: 1,
      action: 'Restore',
    })
  })

  it('takes down a published message with a reason the poster is told', async () => {
    setMessage()
    setUser()
    const wrapper = mountComponent()

    const buttons = wrapper.findAll('button')
    const takeDownButton = buttons.find((b) => b.text() === 'Take down')
    await takeDownButton.trigger('click')

    const input = wrapper.find('input')
    await input.setValue('Not free, asking for money')

    const confirmButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Confirm take down')
    await confirmButton.trigger('click')
    await flushPromises()

    expect(mockMessageStore.patch).toHaveBeenCalledWith({
      id: 1,
      action: 'TakeDown',
      reason: 'Not free, asking for money',
    })
  })

  it('edits the subject and body via the store', async () => {
    setMessage()
    setUser()
    const wrapper = mountComponent()

    const editButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Edit')
    await editButton.trigger('click')

    const inputs = wrapper.findAll('input')
    await inputs[0].setValue('Sofa (free)')

    const saveButton = wrapper
      .findAll('button')
      .find((b) => b.text() === 'Save')
    await saveButton.trigger('click')
    await flushPromises()

    expect(mockMessageStore.patch).toHaveBeenCalledWith(
      expect.objectContaining({
        id: 1,
        action: 'Edit',
        subject: 'Sofa (free)',
      })
    )
  })

  it('tolerates contentcheck_reasons as a JSON string', () => {
    setMessage({
      contentcheck_reasons: JSON.stringify([
        { category: 'Suspicious', detail: 'Off-platform payment mentioned' },
      ]),
    })
    setUser()
    const wrapper = mountComponent()

    expect(wrapper.text()).toContain('Off-platform payment mentioned')
  })

  it('tolerates contentcheck_reasons as an array of objects', () => {
    setMessage({
      contentcheck_reasons: [{ category: 'Suspicious', check: 'keyword' }],
    })
    setUser()
    const wrapper = mountComponent()

    expect(wrapper.text()).toContain('Suspicious')
  })

  it('tolerates a malformed contentcheck_reasons string without throwing', () => {
    setMessage({ contentcheck_reasons: 'not json {' })
    setUser()

    expect(() => mountComponent()).not.toThrow()
  })

  it('hides actions when noactions is set, for read-only Support search', () => {
    setMessage()
    setUser()
    const wrapper = mountComponent({ noactions: true })

    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('fetches the message from the store when not already loaded', () => {
    const wrapper = mountComponent({ messageid: 99 })

    expect(mockMessageStore.fetch).toHaveBeenCalledWith(99)
    expect(wrapper.text()).toContain('Loading')
  })
})
