import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref, h, nextTick } from 'vue'
import ModStdMessageSegments from '~/modtools/components/ModStdMessageSegments.vue'
import {
  parseSegments,
  assembleSegments,
} from '~/modtools/utils/stdMessageDirectives'

const stubs = {
  'b-button': {
    template:
      '<button :data-variant="variant" @click="$emit(\'click\')"><slot /></button>',
    props: ['variant', 'size'],
  },
  'v-icon': {
    template: '<i :class="icon" />',
    props: ['icon'],
  },
  AutoHeightTextarea: {
    template:
      '<textarea :value="modelValue" :placeholder="placeholder" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'rows', 'maxRows', 'placeholder'],
  },
}

// Mount the editor the way the modal does: segments held by the parent and
// bound with v-model, so the tests see what the parent would assemble.
function mountEditor(text, props = {}) {
  const segments = ref(parseSegments(text))
  const wrapper = mount(
    {
      render: () =>
        h(ModStdMessageSegments, {
          modelValue: segments.value,
          'onUpdate:modelValue': (v) => (segments.value = v),
          ...props,
        }),
    },
    { global: { stubs } }
  )
  return { wrapper, segments }
}

const button = (wrapper, label) =>
  wrapper.findAll('button').find((b) => b.text() === label)

describe('ModStdMessageSegments', () => {
  const text =
    'Hello,\n\n<editthis>say why</editthis>\n\n' +
    '<optional>Firewood must be dry.</optional>\n\nThanks.'

  it('says what the wording becomes', () => {
    const { wrapper } = mountEditor(text, {
      description: 'This is what the post will say.',
    })
    expect(wrapper.text()).toContain('This is what the post will say.')
  })

  it('says it is the message to the member unless told otherwise', () => {
    const { wrapper } = mountEditor(text)
    expect(wrapper.text()).toContain(
      'This is the message the member will receive.'
    )
  })

  it('keeps, removes and restores an optional bit in the segments it was given', async () => {
    const { wrapper, segments } = mountEditor(text)
    await wrapper.find('textarea.seg-editthis').setValue('Thanks for posting.')

    await button(wrapper, 'Keep').trigger('click')
    expect(assembleSegments(segments.value)).toContain('Firewood must be dry.')

    await button(wrapper, 'remove this bit after all').trigger('click')
    expect(assembleSegments(segments.value)).not.toContain('Firewood')
    expect(wrapper.find('.seg-optional-removed s').text()).toBe(
      'Firewood must be dry.'
    )

    await button(wrapper, 'undo').trigger('click')
    expect(assembleSegments(segments.value)).toBe(
      'Hello,\n\nThanks for posting.\n\nFirewood must be dry.\n\nThanks.'
    )
  })

  it('highlights only the segments it is told are blocking', () => {
    const { wrapper, segments } = mountEditor(text, { blocked: [] })
    expect(wrapper.find('.seg-blocked').exists()).toBe(false)

    const optional = segments.value.findIndex((s) => s.type === 'optional')
    const { wrapper: blocked } = mountEditor(text, { blocked: [optional] })
    const marked = blocked.findAll('.seg-blocked')
    expect(marked).toHaveLength(1)
    expect(marked[0].classes()).toContain('seg-optional-undecided')
  })

  it('scrolls a segment into view', async () => {
    const { wrapper, segments } = mountEditor(text)
    const optional = segments.value.findIndex((s) => s.type === 'optional')
    const el = wrapper.find(`[data-seg="${optional}"]`).element
    el.scrollIntoView = vi.fn()

    await wrapper
      .findComponent(ModStdMessageSegments)
      .vm.scrollToSegment(optional)
    await nextTick()

    expect(el.scrollIntoView).toHaveBeenCalledWith({ block: 'center' })
  })
})
