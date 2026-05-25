import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MicroVolunteeringEeeLabel from '~/components/MicroVolunteeringEeeLabel.vue'

const mockMicroVolunteeringStore = {
  respond: vi.fn().mockResolvedValue(undefined),
}

vi.mock('~/stores/microvolunteering', () => ({
  useMicroVolunteeringStore: () => mockMicroVolunteeringStore,
}))

const testItem = {
  messageid: 12345,
  attid: 678,
  itemName: 'Microwave oven',
  imageUrl: 'https://images.ilovefreegle.org/test-microwave',
}

describe('MicroVolunteeringEeeLabel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function createWrapper(props = {}) {
    return mount(MicroVolunteeringEeeLabel, {
      props: {
        item: testItem,
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
              '<button class="b-button" :class="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
            props: ['variant', 'disabled'],
            emits: ['click'],
          },
        },
      },
    })
  }

  describe('rendering', () => {
    it('shows the item name as caption', () => {
      const wrapper = createWrapper()
      expect(wrapper.text()).toContain('Microwave oven')
    })

    it('displays the item image', () => {
      const wrapper = createWrapper()
      const img = wrapper.find('img.review-image')
      expect(img.exists()).toBe(true)
      expect(img.attributes('src')).toBe(testItem.imageUrl)
    })

    it('shows three questions: Condition, Weight, Size', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      expect(text.toLowerCase()).toContain('condition')
      expect(text.toLowerCase()).toContain('weight')
      expect(text.toLowerCase()).toContain('size')
    })

    it('explains the task is help-the-AI', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      // Must explain WHY the user is labelling — to help train EEE classifiers
      expect(text.toLowerCase()).toMatch(/help|train|teach|electrical|recycl/)
    })

    it('shows condition options matching the 3-value backend vocabulary', () => {
      // Must align with label.ts VALID_LABELS['Condition'] = ['reusable','damaged','unsure']
      // and with the normalisation applied during model scoring.
      const wrapper = createWrapper()
      const text = wrapper.text().toLowerCase()
      expect(text).toContain('reusable')
      expect(text).toContain('damaged')
    })

    it('shows weight bucket options', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      expect(text).toContain('Under 1 kg')
      expect(text).toContain('1 - 5 kg')
      expect(text).toContain('5 - 20 kg')
      expect(text).toContain('20 - 100 kg')
      expect(text).toContain('Over 100 kg')
    })

    it('shows size bucket options', () => {
      const wrapper = createWrapper()
      const text = wrapper.text()
      expect(text).toContain('Tiny')
      expect(text).toContain('Small')
      expect(text).toContain('Medium')
      expect(text).toContain('Large')
    })

    it('each question offers an "unsure" / "skip" option', () => {
      const wrapper = createWrapper()
      // We must allow users to say they don't know each question without
      // forcing them to answer — otherwise they'll guess and pollute the data.
      const unsures = wrapper.findAll('button').filter(b => /unsure|can'?t tell|don'?t know/i.test(b.text()))
      expect(unsures.length).toBeGreaterThanOrEqual(3)
    })
  })

  describe('submission', () => {
    it('submit button starts disabled when no answers given', () => {
      const wrapper = createWrapper()
      const submitBtn = wrapper.find('[data-testid="submit-labels"]')
      expect(submitBtn.exists()).toBe(true)
      expect(submitBtn.attributes('disabled')).toBeDefined()
    })

    it('submit button becomes enabled once all three are answered', async () => {
      const wrapper = createWrapper()

      // Click one option per question. We rely on data-testid attributes on
      // each option group so the test is independent of button text/layout.
      await wrapper.find('[data-testid="condition-reusable"]').trigger('click')
      await wrapper.find('[data-testid="weight-5_20kg"]').trigger('click')
      await wrapper.find('[data-testid="size-medium"]').trigger('click')

      const submitBtn = wrapper.find('[data-testid="submit-labels"]')
      expect(submitBtn.attributes('disabled')).toBeUndefined()
    })

    it('POSTs labels via microvolunteering store on submit', async () => {
      const wrapper = createWrapper()
      await wrapper.find('[data-testid="condition-reusable"]').trigger('click')
      await wrapper.find('[data-testid="weight-5_20kg"]').trigger('click')
      await wrapper.find('[data-testid="size-medium"]').trigger('click')

      await wrapper.find('[data-testid="submit-labels"]').trigger('click')
      await flushPromises()

      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledTimes(1)
      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledWith(
        expect.objectContaining({
          messageid: testItem.messageid,
          attid: testItem.attid,
          eeelabels: {
            condition: 'reusable',
            weight: '5_20kg',
            size: 'medium',
          },
        }),
      )
    })

    it('"unsure" answers are still submitted (the absence of data is signal)', async () => {
      const wrapper = createWrapper()
      await wrapper.find('[data-testid="condition-unsure"]').trigger('click')
      await wrapper.find('[data-testid="weight-unsure"]').trigger('click')
      await wrapper.find('[data-testid="size-unsure"]').trigger('click')

      await wrapper.find('[data-testid="submit-labels"]').trigger('click')
      await flushPromises()

      expect(mockMicroVolunteeringStore.respond).toHaveBeenCalledWith(
        expect.objectContaining({
          eeelabels: {
            condition: 'unsure',
            weight: 'unsure',
            size: 'unsure',
          },
        }),
      )
    })

    it('emits "next" after a successful submit', async () => {
      const wrapper = createWrapper()
      await wrapper.find('[data-testid="condition-reusable"]').trigger('click')
      await wrapper.find('[data-testid="weight-5_20kg"]').trigger('click')
      await wrapper.find('[data-testid="size-medium"]').trigger('click')

      await wrapper.find('[data-testid="submit-labels"]').trigger('click')
      await flushPromises()

      expect(wrapper.emitted('next')).toBeTruthy()
      expect(wrapper.emitted('next')).toHaveLength(1)
    })
  })
})
