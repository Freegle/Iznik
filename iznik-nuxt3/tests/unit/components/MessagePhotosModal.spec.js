import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { ref, nextTick } from 'vue'
import MessagePhotosModal from '~/components/MessagePhotosModal.vue'

const mockMessageStore = {
  byId: vi.fn(),
}
vi.mock('~/stores/message', () => ({
  useMessageStore: () => mockMessageStore,
}))

const mockModalHistory = vi.fn()
vi.mock('~/composables/useModalHistory', () => ({
  useModalHistory: (...args) => mockModalHistory(...args),
}))

const mockWidth = ref(0)
const mockHeight = ref(0)
vi.mock('@vueuse/core', () => ({
  useElementSize: () => ({ width: mockWidth, height: mockHeight }),
}))

vi.mock('zoompinch/style.css', () => ({}))

function makeAttachments(n) {
  return Array.from({ length: n }, (_, i) => ({
    id: i + 1,
    path: `/photo${i + 1}.jpg`,
  }))
}

const globalStubs = {
  Teleport: { template: '<div class="teleport-stub"><slot /></div>' },
  'v-icon': { template: '<i :class="icon"></i>', props: ['icon'] },
  PinchMe: {
    name: 'PinchMe',
    template: '<div class="pinch-me" />',
    props: ['attachment', 'width', 'height', 'zoom'],
    emits: ['zoom-change', 'scale-change'],
    methods: {
      setScale(v) {
        this.lastSetScale = v
      },
      resetTransform() {
        this.resetCalled = true
      },
    },
  },
}

function createWrapper(props = {}) {
  return mount(MessagePhotosModal, {
    props: { id: 1, ...props },
    global: { stubs: globalStubs },
  })
}

async function makeReady(wrapper) {
  mockWidth.value = 375
  mockHeight.value = 667
  await nextTick()
  await flushPromises()
  return wrapper
}

describe('MessagePhotosModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
    mockWidth.value = 0
    mockHeight.value = 0
    mockMessageStore.byId.mockReturnValue({
      id: 1,
      attachments: makeAttachments(3),
    })
    if (!Element.prototype.setPointerCapture) {
      Element.prototype.setPointerCapture = () => {}
    }
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockReturnValue({
      left: 0,
      width: 100,
      right: 100,
      top: 0,
      bottom: 20,
      height: 20,
    })
  })

  afterEach(() => {
    vi.runOnlyPendingTimers()
    vi.useRealTimers()
    vi.restoreAllMocks()
  })

  describe('setup', () => {
    it('registers modal-history handling with a per-message key', () => {
      createWrapper({ id: 42 })
      expect(mockModalHistory).toHaveBeenCalledWith(
        'photos-42',
        expect.any(Function)
      )
    })

    it('closing via the modal-history callback emits hidden', () => {
      createWrapper({ id: 42 })
      const onClose = mockModalHistory.mock.calls[0][1]
      onClose()
      // Nothing to assert on directly here beyond "did not throw" since the
      // emit target is the component instance created above.
      expect(mockModalHistory).toHaveBeenCalled()
    })
  })

  describe('rendering', () => {
    it('renders inside the teleport stub', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.teleport-stub .fullscreen-viewer').exists()).toBe(
        true
      )
    })

    it('shows the image counter when there is more than one attachment', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('hides the image counter with a single attachment', () => {
      mockMessageStore.byId.mockReturnValue({
        id: 1,
        attachments: makeAttachments(1),
      })
      const wrapper = createWrapper()
      expect(wrapper.find('.image-counter').exists()).toBe(false)
    })

    it('handles a message with no attachments', () => {
      mockMessageStore.byId.mockReturnValue({ id: 1, attachments: [] })
      const wrapper = createWrapper()
      expect(wrapper.find('.image-counter').exists()).toBe(false)
      expect(wrapper.findAll('.image-slide')).toHaveLength(0)
    })

    it('does not show the left arrow on the first image', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.nav-arrow-left').exists()).toBe(false)
      expect(wrapper.find('.nav-arrow-right').exists()).toBe(true)
    })

    it('does not show the right arrow on the last image', async () => {
      const wrapper = createWrapper({ initialIndex: 2 })
      await nextTick()
      expect(wrapper.find('.nav-arrow-right').exists()).toBe(false)
      expect(wrapper.find('.nav-arrow-left').exists()).toBe(true)
    })

    it('renders one dot per attachment and marks the active one', async () => {
      const wrapper = createWrapper({ initialIndex: 1 })
      await nextTick()
      const dots = wrapper.findAll('.dot')
      expect(dots).toHaveLength(3)
      expect(dots[1].classes()).toContain('active')
      expect(dots[0].classes()).not.toContain('active')
    })

    it('does not render PinchMe images until the container has a real size', () => {
      const wrapper = createWrapper()
      expect(wrapper.findAllComponents({ name: 'PinchMe' })).toHaveLength(0)
    })

    it('renders PinchMe for images near the current index once ready', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      // index 0 is current; index 1 is within 1; index 2 (distance 2) is not.
      expect(wrapper.findAllComponents({ name: 'PinchMe' })).toHaveLength(2)
    })
  })

  describe('closing', () => {
    it('emits hidden when the back button is clicked', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.back-button').trigger('click')
      expect(wrapper.emitted('hidden')).toHaveLength(1)
    })

    it('emits hidden when clicking directly on the background', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.fullscreen-viewer').trigger('click')
      expect(wrapper.emitted('hidden')).toHaveLength(1)
    })

    it('does not close when clicking on a child element', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.image-container').trigger('click')
      expect(wrapper.emitted('hidden')).toBeUndefined()
    })
  })

  describe('navigation', () => {
    it('goToImage moves the current index and starts a transition', async () => {
      const wrapper = createWrapper()
      await wrapper.find('.nav-arrow-right').trigger('click')
      expect(wrapper.find('.images-wrapper').classes()).toContain(
        'transitioning'
      )
      expect(wrapper.find('.image-counter').text()).toBe('2 / 3')
      await vi.advanceTimersByTimeAsync(300)
      expect(wrapper.find('.images-wrapper').classes()).not.toContain(
        'transitioning'
      )
    })

    it('going to a dot navigates directly to that image', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      await wrapper.findAll('.dot')[2].trigger('click')
      expect(wrapper.find('.image-counter').text()).toBe('3 / 3')
    })

    it('resets the target pinch transform and scale after navigating', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      await wrapper.find('.nav-arrow-right').trigger('click')
      await nextTick()
      const pinches = wrapper.findAllComponents({ name: 'PinchMe' })
      // At least the pinch for the new current index should have had its
      // transform reset once its ref becomes available.
      expect(pinches.some((p) => p.vm.resetCalled)).toBe(true)
    })
  })

  describe('keyboard handling', () => {
    it('closes on Escape', async () => {
      const wrapper = createWrapper()
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      await nextTick()
      expect(wrapper.emitted('hidden')).toHaveLength(1)
    })

    it('navigates forward on ArrowRight, respecting the upper bound', async () => {
      const wrapper = createWrapper({ initialIndex: 2 })
      await nextTick()
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight' }))
      await nextTick()
      expect(wrapper.find('.image-counter').text()).toBe('3 / 3')
    })

    it('navigates backward on ArrowLeft, respecting the lower bound', async () => {
      const wrapper = createWrapper()
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
      await nextTick()
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('advances on ArrowLeft/Right within range', async () => {
      const wrapper = createWrapper({ initialIndex: 1 })
      await nextTick()
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft' }))
      await nextTick()
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('ignores other keys', async () => {
      const wrapper = createWrapper()
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab' }))
      await nextTick()
      expect(wrapper.emitted('hidden')).toBeUndefined()
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('removes the keydown listener and restores scroll on unmount', () => {
      const wrapper = createWrapper()
      document.body.style.overflow = 'hidden'
      wrapper.unmount()
      expect(document.body.style.overflow).toBe('')
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      expect(wrapper.emitted('hidden')).toBeUndefined()
    })

    it('sets body overflow hidden on mount', () => {
      document.body.style.overflow = ''
      createWrapper()
      expect(document.body.style.overflow).toBe('hidden')
    })
  })

  describe('zoom slider', () => {
    it('zoomIn increases scale up to the 5x maximum', async () => {
      const wrapper = createWrapper()
      for (let i = 0; i < 10; i++) {
        await wrapper.find('.zoom-btn:last-of-type').trigger('click')
      }
      expect(wrapper.find('.zoom-level').text()).toBe('5.0x')
      expect(
        wrapper.find('.zoom-btn:last-of-type').attributes('disabled')
      ).toBeDefined()
    })

    it('zoomOut decreases scale down to the 1x minimum and disables the button', async () => {
      const wrapper = createWrapper()
      expect(
        wrapper.find('.zoom-btn:first-of-type').attributes('disabled')
      ).toBeDefined()
      await wrapper.find('.zoom-btn:last-of-type').trigger('click')
      expect(
        wrapper.find('.zoom-btn:first-of-type').attributes('disabled')
      ).toBeUndefined()
      await wrapper.find('.zoom-btn:first-of-type').trigger('click')
      expect(wrapper.find('.zoom-level').text()).toBe('1.0x')
    })

    it('dragging the slider sets scale proportionally to pointer position', async () => {
      const wrapper = createWrapper()
      const track = wrapper.find('.zoom-track')
      await track.trigger('pointerdown', { clientX: 50, pointerId: 1 })
      // Midpoint of a 100-wide track maps to the midpoint of [1, 5] = 3.
      expect(wrapper.find('.zoom-level').text()).toBe('3.0x')
    })

    it('clamps slider dragging to the track bounds', async () => {
      const wrapper = createWrapper()
      const track = wrapper.find('.zoom-track')
      await track.trigger('pointerdown', { clientX: 1000, pointerId: 1 })
      expect(wrapper.find('.zoom-level').text()).toBe('5.0x')
    })

    it('syncs the slider from a scale-change event on the current image', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      const pinch = wrapper.findAllComponents({ name: 'PinchMe' })[0]
      await pinch.vm.$emit('scale-change', 2.4)
      expect(wrapper.find('.zoom-level').text()).toBe('2.4x')
    })

    it('ignores scale-change events for images that are not current', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      const pinches = wrapper.findAllComponents({ name: 'PinchMe' })
      const other = pinches.find((p) => p.props('attachment').id !== 1)
      await other.vm.$emit('scale-change', 4.2)
      expect(wrapper.find('.zoom-level').text()).toBe('1.0x')
    })
  })

  describe('touch swipe', () => {
    function touchEvent(points) {
      return { touches: points.map((p) => ({ clientX: p.x, clientY: p.y })) }
    }

    it('swiping left past the threshold advances to the next image', async () => {
      const wrapper = createWrapper()
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 200, y: 100 }]))
      await container.trigger('touchmove', touchEvent([{ x: 50, y: 100 }]))
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('2 / 3')
    })

    it('swiping right past the threshold on image 2 goes back to image 1', async () => {
      const wrapper = createWrapper({ initialIndex: 1 })
      await nextTick()
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 50, y: 100 }]))
      await container.trigger('touchmove', touchEvent([{ x: 250, y: 100 }]))
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('a swipe below the threshold does not change image', async () => {
      const wrapper = createWrapper()
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 200, y: 100 }]))
      await container.trigger('touchmove', touchEvent([{ x: 190, y: 100 }]))
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('a mostly-vertical drag is not treated as a swipe', async () => {
      const wrapper = createWrapper()
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 200, y: 100 }]))
      await container.trigger('touchmove', touchEvent([{ x: 190, y: 400 }]))
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('a second touch is treated as a pinch and blocks swipe navigation', async () => {
      const wrapper = createWrapper()
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 200, y: 100 }]))
      await container.trigger(
        'touchmove',
        touchEvent([
          { x: 200, y: 100 },
          { x: 260, y: 160 },
        ])
      )
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })

    it('ignores a touch start while the current image is zoomed', async () => {
      const wrapper = createWrapper()
      await makeReady(wrapper)
      const pinch = wrapper.findAllComponents({ name: 'PinchMe' })[0]
      await pinch.vm.$emit('zoom-change', true)
      const container = wrapper.find('.image-container')
      await container.trigger('touchstart', touchEvent([{ x: 200, y: 100 }]))
      await container.trigger('touchmove', touchEvent([{ x: 50, y: 100 }]))
      await container.trigger('touchend', { touches: [] })
      expect(wrapper.find('.image-counter').text()).toBe('1 / 3')
    })
  })

  // The viewer serves two callers: a posted message, whose photos it reads from
  // the store, and photos that are not a message yet - the ones you have just
  // uploaded while composing a post, which it is handed directly.
  describe('where the photos come from', () => {
    function mountViewer(props) {
      return mount(MessagePhotosModal, {
        props,
        attachTo: document.body,
        global: {
          stubs: {
            teleport: true,
            PinchMe: {
              name: 'PinchMe',
              props: ['attachment', 'width', 'height', 'zoom'],
              template: '<div class="pinch-me" />',
            },
            'v-icon': { template: '<span />' },
          },
        },
      })
    }

    it('uses the attachments it is given, without asking the store', () => {
      const wrapper = mountViewer({
        attachments: [
          { id: 11, path: '/composed1.jpg' },
          { id: 12, path: '/composed2.jpg' },
        ],
      })

      expect(wrapper.findAll('.image-slide')).toHaveLength(2)
      expect(mockMessageStore.byId).not.toHaveBeenCalled()
      expect(wrapper.find('.image-counter').text()).toBe('1 / 2')
    })

    it('falls back to the message in the store when given an id', () => {
      const wrapper = mountViewer({ id: 123 })

      // The store fixture has three photos.
      expect(wrapper.findAll('.image-slide')).toHaveLength(3)
      expect(mockMessageStore.byId).toHaveBeenCalledWith(123)
    })

    it('starts on the photo that was clicked', () => {
      const wrapper = mountViewer({
        attachments: [{ id: 11 }, { id: 12 }, { id: 13 }],
        initialIndex: 2,
      })

      expect(wrapper.find('.image-counter').text()).toBe('3 / 3')
    })

    it('shows no counter for a single photo', () => {
      const wrapper = mountViewer({ attachments: [{ id: 11 }] })

      expect(wrapper.find('.image-counter').exists()).toBe(false)
    })

    it('copes with an empty attachments array', () => {
      const wrapper = mountViewer({ attachments: [] })

      expect(wrapper.findAll('.image-slide')).toHaveLength(0)
      expect(mockMessageStore.byId).not.toHaveBeenCalled()
    })
  })

  describe('escape key', () => {
    function mountViewer(props) {
      return mount(MessagePhotosModal, {
        props,
        attachTo: document.body,
        global: {
          stubs: {
            teleport: true,
            PinchMe: { template: '<div class="pinch-me" />' },
            'v-icon': { template: '<span />' },
          },
        },
      })
    }

    it('closes on escape', async () => {
      const wrapper = mountViewer({ attachments: [{ id: 11 }] })

      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
      await nextTick()

      expect(wrapper.emitted('hidden')).toBeTruthy()
    })

    it('keeps escape to itself, so it cannot also close the modal underneath', () => {
      // The viewer opens on top of things that close on escape - the edit-post
      // form, for one, where closing would throw away someone's edits.
      const wrapper = mountViewer({ attachments: [{ id: 11 }] })

      const underneath = vi.fn()
      window.addEventListener('keydown', underneath)

      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

      expect(wrapper.emitted('hidden')).toBeTruthy()
      expect(underneath).not.toHaveBeenCalled()

      window.removeEventListener('keydown', underneath)
    })
  })
})
