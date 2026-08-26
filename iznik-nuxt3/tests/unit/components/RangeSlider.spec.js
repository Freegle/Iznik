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

  // The "clicking back" jank: the native range drag must be decoupled from parent reactivity.
  // The input is driven by an internal localValue, so a parent re-render that echoes the value
  // we just emitted must NOT rewrite the input and yank the thumb back mid-drag - only a genuine
  // external change (reset/clamp/programmatic set) may move it.
  describe('drag is decoupled from parent reactivity (no clicking-back)', () => {
    it('does not reset the input when the parent echoes the value we just emitted', async () => {
      const wrapper = createWrapper({ modelValue: 5 })
      const input = wrapper.find('input')

      // Drag to 8: the input emits update:modelValue.
      input.element.value = '8'
      await input.trigger('input')
      expect(wrapper.emitted('update:modelValue').at(-1)).toEqual([8])

      // Parent applies the v-model update, echoing 8 straight back as modelValue (the round-trip
      // that happens on every drag tick). This must NOT disturb the input - it stays at 8.
      await wrapper.setProps({ modelValue: 8 })
      expect(input.element.value).toBe('8')
    })

    it('does not regress the input when the parent echoes a mid-drag value', async () => {
      const wrapper = createWrapper({ modelValue: 2 })
      const input = wrapper.find('input')

      // Simulate a forward drag 2 -> 3 -> 4, each tick echoed back by the parent, and assert the
      // thumb never jumps backwards to an earlier position.
      for (const v of [3, 4]) {
        input.element.value = String(v)
        await input.trigger('input')
        await wrapper.setProps({ modelValue: v }) // parent echo
        expect(Number(input.element.value)).toBe(v)
      }
    })

    it('DOES update the input when the parent sends a genuinely new (external) value', async () => {
      const wrapper = createWrapper({ modelValue: 5 })
      const input = wrapper.find('input')

      // User drags to 8...
      input.element.value = '8'
      await input.trigger('input')

      // ...then something external resets the distance to 3 (e.g. a clamp or a reset). The thumb
      // must follow the external change.
      await wrapper.setProps({ modelValue: 3 })
      expect(input.element.value).toBe('3')
    })
  })
  // A shared axis: several sliders with different maxima stacked on one scale, so the thumb
  // positions can be read against each other. The unavailable tail is drawn as an inert stub rather
  // than by widening the input, so keyboard and assistive tech cannot reach a value the caller has
  // ruled out.
  describe('shared axis dead zone', () => {
    it('draws no dead zone by default', () => {
      const wrapper = createWrapper({ min: 5, max: 20 })
      expect(wrapper.find('.range-slider__deadzone').exists()).toBe(false)
    })

    it('draws no dead zone when the axis matches the maximum', () => {
      const wrapper = createWrapper({ min: 5, max: 45, axisMax: 45 })
      expect(wrapper.find('.range-slider__deadzone').exists()).toBe(false)
    })

    it('gives the input its share of the axis and the stub the rest', () => {
      // 5..20 of a 5..45 axis is 15/40 = 37.5%.
      const wrapper = createWrapper({ min: 5, max: 20, axisMax: 45 })
      expect(wrapper.find('input').attributes('style')).toContain('37.5')
      expect(wrapper.find('.range-slider__deadzone').exists()).toBe(true)
    })

    it('keeps the input max at the reachable value, not the axis', () => {
      const wrapper = createWrapper({ min: 5, max: 20, axisMax: 45 })
      expect(wrapper.find('input').attributes('max')).toBe('20')
    })

    it('hides the stub from assistive tech and explains it on hover', () => {
      const wrapper = createWrapper({
        min: 5,
        max: 20,
        axisMax: 45,
        deadZoneTitle: 'Not shown where you live',
      })
      const stub = wrapper.find('.range-slider__deadzone')
      expect(stub.attributes('aria-hidden')).toBe('true')
      expect(stub.attributes('title')).toBe('Not shown where you live')
    })
  })
})
