import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RangeSlider from '~/components/RangeSlider.vue'

describe('RangeSlider', () => {
  function createWrapper(props = {}) {
    return mount(RangeSlider, {
      props: {
        modelValue: 5,
        ...props,
      },
    })
  }

  describe('rendering', () => {
    it('renders a native range input', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('input[type="range"]').exists()).toBe(true)
    })

    it('applies min/max/step to the input', () => {
      const wrapper = createWrapper({ min: 0.5, max: 10, step: 0.5 })
      const input = wrapper.find('input')
      expect(input.attributes('min')).toBe('0.5')
      expect(input.attributes('max')).toBe('10')
      expect(input.attributes('step')).toBe('0.5')
    })

    it('defaults min/max/step when not provided', () => {
      const wrapper = createWrapper()
      const input = wrapper.find('input')
      expect(input.attributes('min')).toBe('0')
      expect(input.attributes('max')).toBe('10')
      expect(input.attributes('step')).toBe('1')
    })

    it('reflects modelValue as the input value', () => {
      const wrapper = createWrapper({ modelValue: 3.5 })
      expect(wrapper.find('input').element.value).toBe('3.5')
    })

    it('renders left and right end labels only - no numeric readout', () => {
      const wrapper = createWrapper({
        leftLabel: 'Nearer',
        rightLabel: 'Further',
      })
      const text = wrapper.text()
      expect(text).toContain('Nearer')
      expect(text).toContain('Further')
      // No numeric readout of the current value anywhere in the markup.
      expect(text).not.toContain('5')
    })

    it('omits the labels row when no labels are given', () => {
      const wrapper = createWrapper()
      expect(wrapper.find('.range-slider__labels').exists()).toBe(false)
    })

    it('sets the aria-label for accessibility', () => {
      const wrapper = createWrapper({ ariaLabel: 'Maximum distance' })
      expect(wrapper.find('input').attributes('aria-label')).toBe(
        'Maximum distance'
      )
    })

    it('applies the id to the input for label association', () => {
      const wrapper = createWrapper({ id: 'distanceSlider' })
      expect(wrapper.find('input').attributes('id')).toBe('distanceSlider')
    })
  })

  describe('variant', () => {
    it('defaults to the green variant', () => {
      const wrapper = createWrapper()
      expect(wrapper.classes()).toContain('range-slider--green')
    })

    it('supports the blue variant', () => {
      const wrapper = createWrapper({ variant: 'blue' })
      expect(wrapper.classes()).toContain('range-slider--blue')
    })
  })

  describe('two-way binding and change semantics', () => {
    it('emits update:modelValue on every input tick (instant visual)', async () => {
      const wrapper = createWrapper({ modelValue: 5 })
      const input = wrapper.find('input')
      input.element.value = '7'
      await input.trigger('input')
      expect(wrapper.emitted('update:modelValue')[0]).toEqual([7])
      // input alone must not emit change - callers debounce expensive work on change.
      expect(wrapper.emitted('change')).toBeFalsy()
    })

    it('emits change only when the drag/keypress settles', async () => {
      const wrapper = createWrapper({ modelValue: 5 })
      const input = wrapper.find('input')
      input.element.value = '7'
      await input.trigger('change')
      expect(wrapper.emitted('change')[0]).toEqual([7])
    })

    it('emits numbers, not strings, for both events', async () => {
      const wrapper = createWrapper({ modelValue: 0.5, step: 0.5 })
      const input = wrapper.find('input')
      input.element.value = '2.5'
      await input.trigger('input')
      await input.trigger('change')
      expect(wrapper.emitted('update:modelValue')[0][0]).toBe(2.5)
      expect(wrapper.emitted('change')[0][0]).toBe(2.5)
      expect(typeof wrapper.emitted('update:modelValue')[0][0]).toBe('number')
    })
  })
})
