import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { computed, ref } from 'vue'
import ChatPromptPost from '~/components/ChatPromptPost.vue'

const { mockData, fetchMessage } = vi.hoisted(() => {
  return {
    mockData: {
      message: {
        id: 42,
        subject: 'OFFER: Dining table and 4 chairs (Tuvalu High Street)',
        attachments: [],
      },
      strippedSubject: 'Dining table and 4 chairs (Tuvalu High Street)',
    },
    fetchMessage: vi.fn().mockResolvedValue({}),
  }
})

vi.mock('~/composables/useMessageDisplay', () => ({
  useMessageDisplay: () => ({
    message: computed(() => mockData.message),
    strippedSubject: computed(() => mockData.strippedSubject),
    placeholderClass: ref('placeholder-offer'),
    categoryIcon: ref('gift'),
  }),
}))

vi.mock('~/stores/message', () => ({
  useMessageStore: () => ({ fetch: fetchMessage }),
}))

// One of a member's posts, listed compactly inside a Freegle prompt. Deliberately
// not the full ChatMessageCard: a prompt routinely covers five posts, and five
// full-bleed photo tiles would bury the question under them.
describe('ChatPromptPost', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockData.message = {
      id: 42,
      subject: 'OFFER: Dining table and 4 chairs (Tuvalu High Street)',
      attachments: [],
    }
    mockData.strippedSubject = 'Dining table and 4 chairs (Tuvalu High Street)'
  })

  function createWrapper(id = 42) {
    return mount(ChatPromptPost, {
      props: { id },
      global: {
        stubs: {
          OurUploadedImage: { template: '<img class="ouruploaded" />' },
          NuxtPicture: { template: '<img class="nuxtpicture" />' },
          ProxyImage: { template: '<img class="proxyimage" />' },
          'v-icon': { template: '<i class="v-icon" />', props: ['icon'] },
          'nuxt-link': {
            template: '<a :href="to"><slot /></a>',
            props: ['to', 'noPrefetch'],
          },
        },
      },
    })
  }

  it('names the post, because the prompt wording deliberately does not', () => {
    // "your pending bookshelf" is what you get from interpolating a subject into
    // prose, and nothing can tell a good item name from a silly one. So the
    // question names nothing and this says exactly which posts it covers.
    const wrapper = createWrapper()

    expect(wrapper.text()).toContain('Dining table and 4 chairs')
  })

  it('links to the post so the row is a way in, not just a label', () => {
    const wrapper = createWrapper(42)

    expect(wrapper.find('a').attributes('href')).toBe('/mypost/42')
  })

  it('fetches the post itself, since the display composable only reads the store', async () => {
    createWrapper(42)
    await flushPromises()

    expect(fetchMessage).toHaveBeenCalledWith(42)
  })

  it('falls back to an icon when the post has no photo', () => {
    const wrapper = createWrapper()

    expect(wrapper.find('.v-icon').exists()).toBe(true)
    expect(wrapper.find('.ouruploaded').exists()).toBe(false)
  })

  it('shows the photo when there is one', () => {
    mockData.message.attachments = [{ ouruid: 'abc123', externalmods: null }]

    const wrapper = createWrapper()

    expect(wrapper.find('.ouruploaded').exists()).toBe(true)
  })

  it('renders nothing for a post that has since been deleted', async () => {
    // The question still reads correctly without it, and the counts came from
    // the server - so a missing post is silence, not an error.
    mockData.message = null

    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('survives a fetch that fails', async () => {
    fetchMessage.mockRejectedValueOnce(new Error('gone'))

    const wrapper = createWrapper()
    await flushPromises()

    expect(wrapper.exists()).toBe(true)
  })
})
