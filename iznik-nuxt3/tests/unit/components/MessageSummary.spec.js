import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import MessageSummary from '~/components/MessageSummary.vue'
import { action } from '~/composables/useClientLog'

const mockMiscStore = {
  breakpoint: 'md',
}

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => mockMiscStore,
}))

// Mutable state, read afresh on every mount: a test sets what it needs, then mounts.
// beforeEach restores the defaults.
const { state, defaults } = vi.hoisted(() => {
  const defaults = () => ({
    message: {
      id: 123,
      type: 'Offer',
      subject: 'Offer: Test item (Location)',
      textbody: 'Test description',
      attachments: [{ id: 1, path: '/photo.jpg' }],
      successful: false,
      promised: false,
      promisedtoyou: false,
      area: 'Test Area',
    },
    display: {
      strippedSubject: 'Test item',
      subjectItemName: 'Test item',
      subjectLocation: 'Location',
      gotAttachments: true,
      attachmentCount: 1,
      timeAgo: '2h',
      timeAgoExpanded: '2 hours ago',
      fullTimeAgo: '2 hours ago',
      distanceText: '5mi',
      distanceTextExpanded: '5 miles away',
      distanceTooltip: '5 miles away',
      isPinned: false,
      isOffer: true,
      isWanted: false,
      successfulText: 'TAKEN',
      placeholderClass: 'placeholder-offer',
      categoryIcon: 'gift',
    },
    isLandscape: false,
  })
  return { state: defaults(), defaults }
})

vi.mock('~/composables/useMessageDisplay', () => ({
  useMessageDisplay: () => {
    const refs = { message: ref(state.message) }
    for (const [key, value] of Object.entries(state.display)) {
      refs[key] = ref(value)
    }
    return refs
  },
}))

vi.mock('~/composables/useOrientation', () => ({
  useOrientation: () => ({
    isLandscape: ref(state.isLandscape),
  }),
}))

vi.mock('~/composables/useClientLog', () => ({
  action: vi.fn(),
}))

const BImgStub = {
  name: 'BImg',
  template: '<img :src="src" :alt="alt" />',
  props: {
    src: String,
    alt: String,
    lazy: Boolean,
    width: [String, Number],
    height: [String, Number],
  },
}

const imageProps = ['src', 'alt', 'width', 'fit', 'sizes', 'preload']

const OurUploadedImageStub = {
  name: 'OurUploadedImage',
  template: '<div class="our-uploaded-image" />',
  props: [...imageProps, 'modifiers'],
}

const ProxyImageStub = {
  name: 'ProxyImage',
  template: '<div class="proxy-image" />',
  props: [...imageProps, 'className'],
}

const MessagePhotoPlaceholderStub = {
  name: 'MessagePhotoPlaceholder',
  template: '<div class="message-photo-placeholder" />',
  props: ['placeholderClass', 'icon'],
}

const MessageTagStub = {
  name: 'MessageTag',
  template: '<div class="message-tag" />',
  props: ['id', 'inline'],
}

// MessageSummary's own photo/tag components aren't in the global stub list in
// setup.ts, so mounting it for real needs its own stubs, same as
// ModMessageSummary.spec.js does for its siblings.
function mountMessageSummary(props = {}) {
  return mount(MessageSummary, {
    props: { id: 123, ...props },
    global: {
      stubs: {
        'b-img': BImgStub,
        OurUploadedImage: OurUploadedImageStub,
        ProxyImage: ProxyImageStub,
        MessagePhotoPlaceholder: MessagePhotoPlaceholderStub,
        MessageTag: MessageTagStub,
      },
    },
  })
}

describe('MessageSummary', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mockMiscStore.breakpoint = 'md'
    Object.assign(state, defaults())
  })

  describe('rendering', () => {
    it('renders nothing when there is no message', () => {
      state.message = null
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.message-summary-mobile').exists()).toBe(false)
    })

    it('renders the card when the message exists', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.message-summary-mobile').exists()).toBe(true)
    })
  })

  describe('css classes', () => {
    it('applies offer class for offers', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.classes()).toContain('offer')
      expect(wrapper.classes()).not.toContain('wanted')
    })

    it('applies wanted class for wanteds', () => {
      state.display.isOffer = false
      state.display.isWanted = true
      const wrapper = mountMessageSummary()
      expect(wrapper.classes()).toContain('wanted')
      expect(wrapper.classes()).not.toContain('offer')
    })

    it('applies freegled class for successful messages', () => {
      state.message.successful = true
      expect(mountMessageSummary().classes()).toContain('freegled')
    })

    it('does not apply freegled class when showFreegled is false', () => {
      state.message.successful = true
      const wrapper = mountMessageSummary({ showFreegled: false })
      expect(wrapper.classes()).not.toContain('freegled')
    })

    it('applies promisedfade class when promised to someone else', () => {
      state.message.promised = true
      state.message.promisedtoyou = false
      expect(mountMessageSummary().classes()).toContain('promisedfade')
    })

    // Regression test for https://discourse.ilovefreegle.org/t/10189/1 : the Go API sends
    // promisedtoyou, not the fictional promisedtome the class binding used to read, so a
    // viewer the item IS promised to used to see the faded "promised to someone else" state.
    it('does not apply promisedfade class when promised to you', () => {
      state.message.promised = true
      state.message.promisedtoyou = true
      expect(mountMessageSummary().classes()).not.toContain('promisedfade')
    })

    it('does not apply promisedfade class when showPromised is false', () => {
      state.message.promised = true
      const wrapper = mountMessageSummary({ showPromised: false })
      expect(wrapper.classes()).not.toContain('promisedfade')
    })

    it('applies mobile-landscape class in landscape on a small breakpoint', () => {
      state.isLandscape = true
      mockMiscStore.breakpoint = 'sm'
      expect(mountMessageSummary().classes()).toContain('mobile-landscape')
    })

    it('does not apply mobile-landscape class in landscape on lg+', () => {
      state.isLandscape = true
      mockMiscStore.breakpoint = 'lg'
      expect(mountMessageSummary().classes()).not.toContain('mobile-landscape')
    })

    it('does not apply mobile-landscape class in portrait', () => {
      mockMiscStore.breakpoint = 'sm'
      expect(mountMessageSummary().classes()).not.toContain('mobile-landscape')
    })
  })

  describe('status overlay images', () => {
    const overlays = (wrapper) => wrapper.findAll('img')

    it('shows freegled.jpg for successful messages', () => {
      state.message.successful = true
      const imgs = overlays(mountMessageSummary())
      expect(imgs).toHaveLength(1)
      expect(imgs[0].attributes('src')).toBe('/freegled.jpg')
      expect(imgs[0].attributes('alt')).toBe('TAKEN')
    })

    it('shows promised.jpg for promised messages', () => {
      state.message.promised = true
      const imgs = overlays(mountMessageSummary())
      expect(imgs).toHaveLength(1)
      expect(imgs[0].attributes('src')).toBe('/promised.jpg')
    })

    it('shows only freegled.jpg when a message is both successful and promised', () => {
      state.message.successful = true
      state.message.promised = true
      const imgs = overlays(mountMessageSummary())
      expect(imgs).toHaveLength(1)
      expect(imgs[0].attributes('src')).toBe('/freegled.jpg')
    })

    it('shows no promised overlay when showPromised is false', () => {
      state.message.promised = true
      const wrapper = mountMessageSummary({ showPromised: false })
      expect(overlays(wrapper)).toHaveLength(0)
    })

    it('shows no overlay for an ordinary message', () => {
      expect(overlays(mountMessageSummary())).toHaveLength(0)
    })
  })

  describe('photo area', () => {
    it('uses OurUploadedImage for an ouruid attachment', () => {
      state.message.attachments = [{ id: 1, ouruid: 'abc', externalmods: {} }]
      const wrapper = mountMessageSummary()
      const img = wrapper.findComponent(OurUploadedImageStub)
      expect(img.exists()).toBe(true)
      expect(img.props('src')).toBe('abc')
      expect(wrapper.findComponent(ProxyImageStub).exists()).toBe(false)
    })

    it('uses ProxyImage for a path attachment', () => {
      const wrapper = mountMessageSummary()
      const img = wrapper.findComponent(ProxyImageStub)
      expect(img.exists()).toBe(true)
      expect(img.props('src')).toBe('/photo.jpg')
      expect(wrapper.findComponent(OurUploadedImageStub).exists()).toBe(false)
    })

    it('sizes the photo for retina and passes preload through', () => {
      const img = mountMessageSummary({ preload: true }).findComponent(
        ProxyImageStub
      )
      expect(img.props('preload')).toBe(true)
      expect(img.props('width')).toBe(400)
      expect(img.props('fit')).toBe('inside')
      expect(img.props('sizes')).toBe(
        '(orientation: landscape) and (max-width: 991px) 100px, 200px'
      )
    })

    it('names the item in the photo alt text', () => {
      const img = mountMessageSummary().findComponent(ProxyImageStub)
      expect(img.props('alt')).toBe('Test item')
    })

    it('falls back to generic alt text when there is no item name', () => {
      state.display.subjectItemName = ''
      const img = mountMessageSummary().findComponent(ProxyImageStub)
      expect(img.props('alt')).toBe('Item photo')
    })

    it('shows the photo count only when there is more than one photo', () => {
      expect(mountMessageSummary().find('.photo-count').exists()).toBe(false)
      state.display.attachmentCount = 3
      const count = mountMessageSummary().find('.photo-count')
      expect(count.exists()).toBe(true)
      expect(count.text()).toContain('3')
    })
  })

  describe('placeholder', () => {
    it('shows MessagePhotoPlaceholder with its class and icon when there are no photos', () => {
      state.display.gotAttachments = false
      const wrapper = mountMessageSummary()
      const placeholder = wrapper.findComponent(MessagePhotoPlaceholderStub)
      expect(placeholder.exists()).toBe(true)
      expect(placeholder.props('placeholderClass')).toBe('placeholder-offer')
      expect(placeholder.props('icon')).toBe('gift')
      expect(wrapper.findComponent(ProxyImageStub).exists()).toBe(false)
    })

    it('does not show the placeholder when there are photos', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.findComponent(MessagePhotoPlaceholderStub).exists()).toBe(
        false
      )
    })
  })

  describe('pinned badge', () => {
    it('shows only for a pinned post', () => {
      expect(mountMessageSummary().find('.pinned-badge').exists()).toBe(false)
      state.display.isPinned = true
      expect(mountMessageSummary().find('.pinned-badge').exists()).toBe(true)
    })
  })

  describe('bulk badge', () => {
    const badges = (wrapper) => wrapper.findAll('.bulk-badge')

    it('shows availablenow for a bulk offer', () => {
      state.message.bulkitems = [{ quantity: 2 }, { quantity: 3 }]
      state.message.availablenow = 4
      const found = badges(mountMessageSummary())
      expect(found.length).toBeGreaterThan(0)
      for (const badge of found) {
        expect(badge.text()).toBe('4 available')
      }
    })

    it('falls back to summing item quantities', () => {
      state.message.bulkitems = [{ quantity: '2' }, { quantity: 3 }]
      const found = badges(mountMessageSummary())
      expect(found.length).toBeGreaterThan(0)
      for (const badge of found) {
        expect(badge.text()).toBe('5 available')
      }
    })

    it('is absent for an ordinary post', () => {
      expect(badges(mountMessageSummary())).toHaveLength(0)
    })
  })

  describe('title and content', () => {
    it('shows the stripped subject in the mobile overlay', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.title-subject').text()).toBe('Test item')
    })

    it('shows item name and location in the content section', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.content-subject').text()).toBe('Test item')
      expect(wrapper.find('.content-location').text()).toBe('Location')
    })

    it('omits the content location when the subject has none', () => {
      state.display.subjectLocation = null
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.content-location').exists()).toBe(false)
    })

    it('passes the id to both MessageTags', () => {
      const tags = mountMessageSummary().findAllComponents(MessageTagStub)
      expect(tags).toHaveLength(2)
      for (const tag of tags) {
        expect(tag.props('id')).toBe(123)
        expect(tag.props('inline')).toBe(true)
      }
    })
  })

  describe('description text', () => {
    const description = (wrapper) => wrapper.find('.content-description').text()

    it('shows short text unchanged', () => {
      expect(description(mountMessageSummary())).toBe('Test description')
    })

    it('truncates long text to 120 characters below lg', () => {
      state.message.textbody = 'A'.repeat(150)
      expect(description(mountMessageSummary())).toBe('A'.repeat(120) + '...')
    })

    it('leaves long text whole on lg+ for CSS to clamp', () => {
      mockMiscStore.breakpoint = 'lg'
      state.message.textbody = 'A'.repeat(150)
      expect(description(mountMessageSummary())).toBe('A'.repeat(150))
    })

    it.each([[''], ['null'], [null]])(
      'falls back to a prompt for empty body %j',
      (body) => {
        state.message.textbody = body
        expect(description(mountMessageSummary())).toBe(
          'Click to see more details.'
        )
      }
    )
  })

  describe('location display', () => {
    const location = (wrapper) => wrapper.find('.location')

    it('shows the area name when there is one', () => {
      expect(location(mountMessageSummary()).text()).toBe('Test Area')
    })

    it('falls back to compact distance below lg', () => {
      state.message.area = null
      expect(location(mountMessageSummary()).text()).toBe('5mi')
    })

    it('falls back to expanded distance on lg+', () => {
      state.message.area = null
      mockMiscStore.breakpoint = 'xl'
      expect(location(mountMessageSummary()).text()).toBe('5 miles away')
    })

    it.each([['Unknown'], ['unknown location'], ['   ']])(
      'hides location %j',
      (area) => {
        state.message.area = area
        const wrapper = mountMessageSummary()
        expect(location(wrapper).exists()).toBe(false)
        expect(wrapper.find('.meta-location').exists()).toBe(false)
      }
    )

    it('hides location when there is neither area nor distance', () => {
      state.message.area = null
      state.display.distanceText = ''
      expect(location(mountMessageSummary()).exists()).toBe(false)
    })

    it('explains the distance in its tooltip', () => {
      expect(location(mountMessageSummary()).attributes('title')).toBe(
        '5 miles away'
      )
    })
  })

  describe('time display', () => {
    it('shows compact time in the overlay and content below lg', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.time').text()).toBe('2h')
      expect(wrapper.find('.meta-time').text()).toBe('2h')
    })

    it('shows expanded time in the content on lg+', () => {
      mockMiscStore.breakpoint = 'lg'
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.meta-time').text()).toBe('2 hours ago')
    })

    it('explains the time in its tooltip', () => {
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.time').attributes('title')).toBe('2 hours ago')
    })

    it('falls back to a generic tooltip when there is no full time', () => {
      state.display.fullTimeAgo = ''
      const wrapper = mountMessageSummary()
      expect(wrapper.find('.time').attributes('title')).toBe(
        'When this was posted'
      )
    })
  })

  describe('expand behaviour', () => {
    it('emits expand on click and stops the event', () => {
      const wrapper = mountMessageSummary()
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const stop = vi.spyOn(event, 'stopPropagation')
      wrapper.element.dispatchEvent(event)
      expect(wrapper.emitted('expand')).toHaveLength(1)
      expect(event.defaultPrevented).toBe(true)
      expect(stop).toHaveBeenCalled()
    })

    it('does not expand a successful message', async () => {
      state.message.successful = true
      const wrapper = mountMessageSummary()
      await wrapper.trigger('click')
      expect(wrapper.emitted('expand')).toBeUndefined()
    })
  })

  describe('client logging', () => {
    it('logs the click with message, breakpoint, coordinates and viewport', async () => {
      mockMiscStore.breakpoint = 'sm'
      const wrapper = mountMessageSummary()
      await wrapper.trigger('click', { clientX: 100, clientY: 200 })
      expect(action).toHaveBeenCalledWith('message_card_click', {
        message_id: 123,
        is_successful: false,
        breakpoint: 'sm',
        click_x: 100,
        click_y: 200,
        viewport_width: window.innerWidth,
        viewport_height: window.innerHeight,
      })
    })

    it('logs clicks on successful messages too', async () => {
      state.message.successful = true
      const wrapper = mountMessageSummary()
      await wrapper.trigger('click')
      expect(action).toHaveBeenCalledWith(
        'message_card_click',
        expect.objectContaining({ is_successful: true })
      )
    })
  })

  describe('image lazy loading', () => {
    it('has no NuxtPicture that could load eagerly on mobile', () => {
      // Reporter bug: "30 seconds for pictures to load / can't do anything until photos load"
      // Root cause: the photo rendered through a NuxtPicture with no loading attribute, so the
      // browser loaded all images eagerly, saturating bandwidth and freezing the page on slow
      // mobile. That branch served Uploadcare images and has gone with Uploadcare; the photo now
      // renders through OurUploadedImage, which defaults to loading="lazy". Assert the property
      // rather than the element, so neither the old branch nor a new one can regress it.
      const { readFileSync } = require('fs')
      const { resolve } = require('path')
      const source = readFileSync(
        resolve(__dirname, '../../../components/MessageSummary.vue'),
        'utf-8'
      )
      expect(source).toContain('<OurUploadedImage')
      for (const block of source.match(/<NuxtPicture\b[\s\S]*?\/>/g) || []) {
        expect(block).toContain(':loading=')
      }
    })

    it('lazy loads the status overlay', () => {
      state.message.successful = true
      const wrapper = mountMessageSummary()
      expect(wrapper.findComponent(BImgStub).props('lazy')).toBe(true)
    })
  })
})
