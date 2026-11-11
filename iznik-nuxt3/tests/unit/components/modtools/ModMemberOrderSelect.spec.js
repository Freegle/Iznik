import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModMemberOrderSelect from '~/modtools/components/ModMemberOrderSelect.vue'

const mountIt = (modelValue) =>
  mount(ModMemberOrderSelect, {
    props: modelValue === undefined ? {} : { modelValue },
    global: {
      stubs: {
        'b-form-select': {
          props: ['modelValue'],
          emits: ['update:modelValue'],
          template:
            '<select :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><slot /></select>',
        },
      },
    },
  })

describe('ModMemberOrderSelect', () => {
  it('offers newest and oldest first, newest by default', () => {
    const w = mountIt()
    expect(w.findAll('option').map((o) => o.text())).toEqual([
      'Newest first',
      'Oldest first',
    ])
    expect(w.find('select').element.value).toBe('newest')
  })

  it('reports the choice', async () => {
    const w = mountIt('newest')
    await w.find('select').setValue('oldest')
    expect(w.emitted('update:modelValue')).toEqual([['oldest']])
  })
})
