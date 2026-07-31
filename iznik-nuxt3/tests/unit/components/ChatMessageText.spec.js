import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import ChatMessageText from '~/components/ChatMessageText.vue'

// Mock vue-highlight-words external component
vi.mock('vue-highlight-words', () => ({
  default: defineComponent({
    name: 'VueHighlightWords',
    props: {
      textToHighlight: { type: String, default: '' },
      searchWords: { type: Array, default: () => [] },
      highlightClassName: { type: String, default: '' },
    },
    setup(props) {
      return () => h('span', { class: 'highlighter' }, props.textToHighlight)
    },
  }),
}))

// Mock chat message base composable
const mockChat = {
  id: 123,
  lastmsgseen: 100,
}
const mockChatMessage = {
  id: 101,
  message: 'Hello there!',
  secondsago: 30,
  image: null,
}

const mockComposableReturn = {
  chat: { value: mockChat },
  chatmessage: { value: mockChatMessage },
  emessage: 'Hello there!',
  messageIsFromCurrentUser: false,
  chatMessageProfileImage: '/path/to/image.jpg',
  chatMessageProfileName: 'Test User',
  regexEmail: /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/g,
}

vi.mock('~/composables/useChat', () => ({
  useChatMessageBase: vi.fn(() => ({ ...mockComposableReturn })),
}))

// Mock location store
vi.mock('~/stores/location', () => ({
  useLocationStore: () => ({
    typeahead: vi.fn().mockResolvedValue([]),
  }),
}))

// Mock misc store - modtools flag for linkification
vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    modtools: false,
  }),
}))

// Mock linkify composable - document.createElement not available in test env
vi.mock('~/composables/useLinkify', () => ({
  linkifyText: (text) => text || '',
  linkifyAndHighlightEmails: (text) => text || '',
}))

// Mock map composables
vi.mock('~/composables/useMap', () => ({
  attribution: () => '© OpenStreetMap',
  osmtile: () => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  INLINE_MAP_OPTIONS: { scrollWheelZoom: false, bounceAtZoomLimits: true },
}))

// Mock constants
vi.mock('~/constants', () => ({
  MAX_MAP_ZOOM: 18,
  POSTCODE_REGEX: /[A-Z]{1,2}[0-9]{1,2}[A-Z]?\s*[0-9][A-Z]{2}/gi,
}))

describe('ChatMessageText', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(ChatMessageText, {
      props: {
        chatid: 123,
        id: 101,
        ...props,
      },
      global: {
        stubs: {
          ProfileImage: {
            template:
              '<div class="profile-image" :data-image="image" :data-name="name"></div>',
            props: ['image', 'name', 'isThumbnail', 'size'],
          },
          'b-img': {
            template: '<img class="b-img" :src="src" />',
            props: ['fluid', 'src', 'lazy', 'rounded'],
          },
          'l-map': {
            template:
              '<div class="l-map" :data-scrollwheelzoom="String(options && options.scrollWheelZoom)"><slot /></div>',
            props: ['zoom', 'maxZoom', 'center', 'style', 'options'],
          },
          'l-tile-layer': {
            template: '<div class="l-tile-layer"></div>',
            props: ['url', 'attribution'],
          },
          'l-marker': {
            template: '<div class="l-marker"></div>',
            props: ['latLng', 'interactive'],
          },
        },
      },
    })
  }

  it('applies myChatMessage styling based on message sender', async () => {
    // Message from other user - no special class
    const wrapper = createWrapper()
    expect(wrapper.find('.myChatMessage').exists()).toBe(false)
    expect(wrapper.find('.chatMessageWrapper').exists()).toBe(true)

    // Message from current user - has myChatMessage class
    const { useChatMessageBase } = await import('~/composables/useChat')
    useChatMessageBase.mockReturnValueOnce({
      ...mockComposableReturn,
      messageIsFromCurrentUser: true,
    })
    const wrapperMine = createWrapper()
    expect(wrapperMine.find('.myChatMessage').exists()).toBe(true)
  })

  it('disables wheel zoom on the postcode map', async () => {
    // Leaflet grabs the wheel event by default, so without scrollWheelZoom
    // disabled, scrolling the conversation with the pointer over the map zooms
    // the map out instead of scrolling it, leaving it stuck at world view.
    const wrapper = createWrapper()
    expect(wrapper.find('.l-map').exists()).toBe(false)

    wrapper.vm.lat = 53.8321
    wrapper.vm.lng = -2.6191
    await nextTick()

    expect(wrapper.find('.l-map').attributes('data-scrollwheelzoom')).toBe(
      'false'
    )
  })

  it('highlights emails only when highlightEmails prop is true', () => {
    // Without highlighting
    const wrapperNoHighlight = createWrapper({ highlightEmails: false })
    expect(wrapperNoHighlight.find('.highlighter').exists()).toBe(false)

    // With highlighting
    const wrapperWithHighlight = createWrapper({ highlightEmails: true })
    expect(wrapperWithHighlight.find('.highlighter').exists()).toBe(true)
  })
})
