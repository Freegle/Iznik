import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringAIImageReview from '~/components/MicroVolunteeringAIImageReview.vue'

const mockMicroVolunteeringStore = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => mockMicroVolunteeringStore,
}))

const mockAIImagesAPI = {
  regenerate: vi.fn(),
}

vi.mock('~/api/AIImagesAPI', () => ({
  default: vi.fn().mockImplementation(() => mockAIImagesAPI),
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

  describe('button state', () => {
    it('disables quality buttons until people question answered', () => {
      const wrapper = createWrapper()
      const spinButtons = wrapper.findAll('.spin-button')
      spinButtons.forEach((btn) => {
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

  // Regression tests for: "lost image after Regenerate — no undo available"
  // The component must retain image history so moderators can recover a prior image
  // when Regenerate produces something worse.  Accept and Regenerate must also be
  // separated so accidental Accept clicks are harder.
  describe('image regeneration history', () => {
    const urlInitial = testAIImage.url
    const urlA = 'https://images.ilovefreegle.org/ai-regen-first.jpg'
    const urlB = 'https://images.ilovefreegle.org/ai-regen-second.jpg'

    beforeEach(() => {
      mockAIImagesAPI.regenerate
        .mockResolvedValueOnce({ url: urlA })
        .mockResolvedValueOnce({ url: urlB })
    })

    it('renders a Regenerate button in the action area', () => {
      const wrapper = createWrapper()
      const allButtons = wrapper.findAll('button')
      const regenBtn = allButtons.find((b) => /regenerate/i.test(b.text()))
      // FAILS today: the component has no Regenerate button
      expect(regenBtn).toBeDefined()
      expect(regenBtn.exists()).toBe(true)
    })

    it('updates the image src after two Regenerates and exposes a Previous/Undo control', async () => {
      const wrapper = createWrapper()

      expect(wrapper.find('.review-image').attributes('src')).toBe(urlInitial)

      const regenBtn = wrapper
        .findAll('button')
        .find((b) => /regenerate/i.test(b.text()))
      // FAILS today: no Regenerate button exists
      expect(regenBtn).toBeDefined()

      // first regenerate → urlA
      await regenBtn.trigger('click')
      await flushPromises()

      // second regenerate → urlB
      await regenBtn.trigger('click')
      await flushPromises()

      // image should show the most recently generated URL
      expect(wrapper.find('.review-image').attributes('src')).toBe(urlB)

      // a Previous / Undo control must now be visible so the prior image is recoverable
      const prevBtn = wrapper
        .findAll('button')
        .find((b) => /previous|undo/i.test(b.text()))
      // FAILS today: no image history is retained, no Previous/Undo control
      expect(prevBtn).toBeDefined()
      expect(prevBtn.exists()).toBe(true)
    })

    it('reverts the displayed image to the prior URL when Previous is clicked', async () => {
      const wrapper = createWrapper()

      const regenBtn = wrapper
        .findAll('button')
        .find((b) => /regenerate/i.test(b.text()))
      // FAILS today: no Regenerate button exists
      expect(regenBtn).toBeDefined()

      // first regenerate → urlA
      await regenBtn.trigger('click')
      await flushPromises()

      // second regenerate → urlB
      await regenBtn.trigger('click')
      await flushPromises()

      expect(wrapper.find('.review-image').attributes('src')).toBe(urlB)

      const prevBtn = wrapper
        .findAll('button')
        .find((b) => /previous|undo/i.test(b.text()))
      // FAILS today: no Previous/Undo button
      expect(prevBtn).toBeDefined()

      await prevBtn.trigger('click')
      await flushPromises()

      // image must revert to the result of the first Regenerate
      expect(wrapper.find('.review-image').attributes('src')).toBe(urlA)
    })

    it('does not place Accept immediately adjacent to Regenerate', () => {
      const wrapper = createWrapper()
      const buttons = wrapper.findAll('button')
      const acceptIdx = buttons.findIndex((b) => /accept/i.test(b.text()))
      const regenIdx = buttons.findIndex((b) => /regenerate/i.test(b.text()))

      // Both buttons must be present before the layout assertion is meaningful
      // FAILS today: neither Accept nor Regenerate exists in the current component
      expect(acceptIdx).toBeGreaterThanOrEqual(0)
      expect(regenIdx).toBeGreaterThanOrEqual(0)

      // Buttons must not be directly adjacent in DOM order (|index diff| > 1)
      expect(Math.abs(acceptIdx - regenIdx)).toBeGreaterThan(1)
    })

    it('shows Next button after going to Previous and re-advances with Next click', async () => {
      const wrapper = createWrapper()

      const regenBtn = wrapper
        .findAll('button')
        .find((b) => /regenerate/i.test(b.text()))
      expect(regenBtn).toBeDefined()

      // regenerate once so there are two images in history
      await regenBtn.trigger('click')
      await flushPromises()

      // image is now urlA; Previous button should appear
      expect(wrapper.find('.review-image').attributes('src')).toBe(urlA)

      const prevBtn = wrapper
        .findAll('button')
        .find((b) => /previous|undo/i.test(b.text()))
      expect(prevBtn).toBeDefined()

      // go back to the initial image
      await prevBtn.trigger('click')
      expect(wrapper.find('.review-image').attributes('src')).toBe(urlInitial)

      // Next button should now appear since there is a newer image ahead
      const nextBtn = wrapper
        .findAll('button')
        .find((b) => /^next$/i.test(b.text()))
      expect(nextBtn).toBeDefined()
      expect(nextBtn.exists()).toBe(true)

      // clicking Next should advance back to urlA
      await nextBtn.trigger('click')
      expect(wrapper.find('.review-image').attributes('src')).toBe(urlA)
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
