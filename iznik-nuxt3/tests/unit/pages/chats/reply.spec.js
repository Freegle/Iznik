import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, defineComponent, h, Suspense } from 'vue'

import ChatReplyPage from '~/pages/chats/reply.vue'

vi.mock('~/components/ChatReplyPane.vue', () => ({
  default: {
    name: 'ChatReplyPane',
    template: '<div class="chat-reply-pane-stub" :data-message-id="messageId" />',
    props: ['messageId'],
  },
}))

vi.mock('~/composables/useBuildHead', () => ({
  buildHead: () => ({}),
}))

const mockQueryRef = ref({ replyto: '42' })

vi.hoisted(() => {
  vi.resetModules()
})

vi.mock('#imports', async () => {
  const actual = await vi.importActual('#imports')
  return {
    ...actual,
    useRoute: () => ({
      params: {},
      query: mockQueryRef.value,
      path: '/chats/reply',
      name: 'chats-reply',
      fullPath: '/chats/reply?replyto=42',
      matched: [],
      redirectedFrom: undefined,
      meta: {},
    }),
  }
})

globalThis.useHead = vi.fn()
globalThis.useRuntimeConfig = () => ({ public: {} })

describe('chats/reply page', () => {
  beforeEach(() => {
    mockQueryRef.value = { replyto: '42' }
    vi.clearAllMocks()
  })

  async function createWrapper() {
    const TestWrapper = defineComponent({
      setup() {
        return () =>
          h(Suspense, null, {
            default: () => h(ChatReplyPage),
            fallback: () => h('div', 'Loading...'),
          })
      },
    })

    const wrapper = mount(TestWrapper, {
      global: {
        stubs: {
          'client-only': {
            template: '<div class="client-only"><slot /></div>',
          },
          NuxtLink: {
            template: '<a class="nuxt-link" :href="to"><slot /></a>',
            props: ['to'],
          },
        },
      },
    })

    await flushPromises()
    return wrapper
  }

  it('renders ChatReplyPane when replyto query is valid', async () => {
    mockQueryRef.value = { replyto: '42' }
    const wrapper = await createWrapper()
    const pane = wrapper.find('.chat-reply-pane-stub')
    expect(pane.exists()).toBe(true)
    expect(pane.attributes('data-message-id')).toBe('42')
  })

  it('shows empty state when replyto query is missing', async () => {
    mockQueryRef.value = {}
    const wrapper = await createWrapper()
    expect(wrapper.find('.chat-reply-pane-stub').exists()).toBe(false)
    expect(wrapper.find('.empty-state').exists()).toBe(true)
    expect(wrapper.text()).toContain('No message to reply to')
  })

  it('shows empty state when replyto is zero', async () => {
    mockQueryRef.value = { replyto: '0' }
    const wrapper = await createWrapper()
    expect(wrapper.find('.chat-reply-pane-stub').exists()).toBe(false)
    expect(wrapper.find('.empty-state').exists()).toBe(true)
  })

  it('shows empty state when replyto is negative', async () => {
    mockQueryRef.value = { replyto: '-5' }
    const wrapper = await createWrapper()
    expect(wrapper.find('.chat-reply-pane-stub').exists()).toBe(false)
    expect(wrapper.find('.empty-state').exists()).toBe(true)
  })

  it('shows empty state when replyto is not a number', async () => {
    mockQueryRef.value = { replyto: 'abc' }
    const wrapper = await createWrapper()
    expect(wrapper.find('.chat-reply-pane-stub').exists()).toBe(false)
    expect(wrapper.find('.empty-state').exists()).toBe(true)
  })

  it('shows Browse messages link in empty state', async () => {
    mockQueryRef.value = {}
    const wrapper = await createWrapper()
    expect(wrapper.text()).toContain('Browse messages')
  })

  it('computes correct messageId from query string', async () => {
    mockQueryRef.value = { replyto: '123' }
    const wrapper = await createWrapper()
    const pane = wrapper.find('.chat-reply-pane-stub')
    expect(pane.attributes('data-message-id')).toBe('123')
  })
})
