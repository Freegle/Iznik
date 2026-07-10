import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringAIImageReview from '~/components/MicroVolunteeringAIImageReview.vue'

const mockMicroVolunteeringStore = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => mockMicroVolunteeringStore,
}))

const testAIImage = {
  id: 42,
  name: 'Sofa',
  url: 'https://images.ilovefreegle.org/freegletusd-test-sofa',
  usage_count: 150,
}

describe('MicroVolunteeringAIImageReview', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(MicroVolunteeringAIImageReview, {
      props: {
        aiimage: testAIImage,
        ...props,
      },
      global: {
        stubs: {
          SpinButton: {
            template:
              '<button class="spin-button" :disabled="disabled" @click="$emit(\'handle\', () => {})">{{ label }}</button>',
            props: ['iconName', 'variant', 'label', 'disabled'],
            emits: ['handle'],
          },
          'v-icon': {
            template: '<span class="v-icon" />',
            props: ['icon'],
          },
          'b-button': {
            template:
              '<button class="b-button" :class="variant" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant'],
            emits: ['click'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('shows intro text about AI images', () => {
      const wrapper = createWrapper()
      const text = wrapper.find('.intro-text').text()
      expect(text).toContain('AI-generated images')
      expect(text).toContain('Can you help')
    })

    it('displays the AI image', () => {
      const wrapper = createWrapper()
      const img = wrapper.find('.review-image')
      expect(img.exists()).toBe(true)
      expect(img.attributes('src')).toBe(testAIImage.url)
      expect(img.attributes('alt')).toContain('Sofa')
    })

    it('shows the item name as caption', () => {
      const wrapper = createWrapper()
      const caption = wrapper.find('.image-caption')
      expect(caption.text()).toContain('Sofa')
    })

    it('shows people question', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      expect(text).toContain('Does this image contain pictures of people')
    })

    it('shows quality question with item name', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      expect(text).toContain('Is this a good image')
      expect(text).toContain('Sofa')
    })
  })

  describe('people question', () => {
    it('starts with containsPeople as null', () => {
      const wrapper = createWrapper()
      expect(wrapper.vm.containsPeople).toBeNull()
    })

    it('sets containsPeople to true on Yes click', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('.b-button')
      // First b-button is "Yes"
      await buttons[0].trigger('click')
      expect(wrapper.vm.containsPeople).toBe(true)
    })

    it('sets containsPeople to false on No click', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('.b-button')
      // Second b-button is "No"
      await buttons[1].trigger('click')
      expect(wrapper.vm.containsPeople).toBe(false)
    })
  })

  describe('approve flow', () => {
    it('calls store respond with correct params on approve', async () => {
      const wrapper = createWrapper()

      // Answer people question first
      const buttons = wrapper.findAll('.b-button')
      await buttons[1].trigger('click') // No people

      // Find and click approve button
      const spinButtons = wrapper.findAll('.spin-button')
      const approveBtn = spinButtons.find((b) =>
        b.text().includes('looks good')
      )
      await approveBtn.trigger('click')
      await flushPromises()

      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledWith({
        aiimageid: 42,
        response: 'Approve',
        containspeople: false,
      })
    })

    it('emits next event after approve', async () => {
      const wrapper = createWrapper()

      const buttons = wrapper.findAll('.b-button')
      await buttons[1].trigger('click')

      const spinButtons = wrapper.findAll('.spin-button')
      const approveBtn = spinButtons.find((b) =>
        b.text().includes('looks good')
      )
      await approveBtn.trigger('click')
      await flushPromises()

      expect(wrapper.emitted('next')).toHaveLength(1)
    })
  })

  describe('reject flow', () => {
    it('calls store respond with correct params on reject', async () => {
      const wrapper = createWrapper()

      // Answer people question — yes
      const buttons = wrapper.findAll('.b-button')
      await buttons[0].trigger('click') // Yes people

      // Click reject
      const spinButtons = wrapper.findAll('.spin-button')
      const rejectBtn = spinButtons.find((b) => b.text().includes('not great'))
      await rejectBtn.trigger('click')
      await flushPromises()

      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledWith({
        aiimageid: 42,
        response: 'Reject',
        containspeople: true,
      })
    })

    it('emits next event after reject', async () => {
      const wrapper = createWrapper()

      const buttons = wrapper.findAll('.b-button')
      await buttons[0].trigger('click')

      const spinButtons = wrapper.findAll('.spin-button')
      const rejectBtn = spinButtons.find((b) => b.text().includes('not great'))
      await rejectBtn.trigger('click')
      await flushPromises()

      expect(wrapper.emitted('next')).toHaveLength(1)
    })
  })

  describe('suppress flow', () => {
    it('calls store respond with Suppress when the item is unsuitable for any image', async () => {
      const wrapper = createWrapper()

      // Answer people question — no
      const buttons = wrapper.findAll('.b-button')
      await buttons[1].trigger('click')

      const spinButtons = wrapper.findAll('.spin-button')
      const suppressBtn = spinButtons.find((b) =>
        b.text().includes("shouldn't have an AI image")
      )
      await suppressBtn.trigger('click')
      await flushPromises()

      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledWith({
        aiimageid: 42,
        response: 'Suppress',
        containspeople: false,
      })
    })

    it('emits next event after suppress', async () => {
      const wrapper = createWrapper()

      const spinButtons = wrapper.findAll('.spin-button')
      const suppressBtn = spinButtons.find((b) =>
        b.text().includes("shouldn't have an AI image")
      )
      await suppressBtn.trigger('click')
      await flushPromises()

      expect(wrapper.emitted('next')).toHaveLength(1)
    })
  })

  describe('button state', () => {
    it('disables quality buttons until people question answered', () => {
      const wrapper = createWrapper()
      // Only the image-judgement buttons (Approve / Reject) are gated on the
      // people question. The Suppress action ("this item shouldn't have an AI
      // image") is about the item itself, not this image, so it is intentionally
      // always available and excluded here.
      const gated = wrapper
        .findAll('.spin-button')
        .filter((btn) => !btn.text().includes("shouldn't have an AI image"))
      expect(gated.length).toBeGreaterThan(0)
      gated.forEach((btn) => {
        expect(btn.attributes('disabled')).toBeDefined()
      })
    })

    it('enables quality buttons after people question answered', async () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('.b-button')
      await buttons[0].trigger('click') // Yes people

      const spinButtons = wrapper.findAll('.spin-button')
      spinButtons.forEach((btn) => {
        expect(btn.attributes('disabled')).toBeUndefined()
      })
    })
  })

  // Regeneration is an Admin/Support-only action (the /admin/ai-images/:id/regenerate
  // endpoint requires IsAdminOrSupport and spends Cloudflare AI credits). It lives in the
  // ModTools admin review page, not the volunteer microvolunteering flow — an ordinary
  // volunteer clicking it only ever gets a 403. So this component must NOT offer Regenerate
  // or the Previous/Next carousel that only made sense for browsing regenerated variants.
  describe('no admin-only regeneration controls', () => {
    it('does not render a Regenerate button', () => {
      const wrapper = createWrapper()
      const regenBtn = wrapper
        .findAll('button')
        .find((b) => /regenerate/i.test(b.text()))
      expect(regenBtn).toBeUndefined()
    })

    it('does not render Previous/Next carousel controls', () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      expect(buttons.find((b) => /previous|undo/i.test(b.text()))).toBeUndefined()
      expect(buttons.find((b) => /^next$/i.test(b.text()))).toBeUndefined()
    })
  })

  describe('broken image fallback', () => {
    it('replaces broken image src with default profile picture on error event', async () => {
      const wrapper = createWrapper()
      const img = wrapper.find('.review-image')

      await img.trigger('error')

      // brokenImage sets event.target.src directly on the DOM element
      expect(img.element.src).toContain('defaultprofile')
    })

    it('brokenImage handler sets fallback src on the event target', () => {
      const wrapper = createWrapper()

      const fakeTarget = { src: testAIImage.url }
      wrapper.vm.brokenImage({ target: fakeTarget })

      expect(fakeTarget.src).toBe('/defaultprofile.png')
    })
  })
})
