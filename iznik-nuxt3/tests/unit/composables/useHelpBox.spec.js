import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { useHelpBox } from '~/modtools/composables/useHelpBox'

function mountWithHelpBox() {
  let api
  const Harness = defineComponent({
    setup() {
      api = useHelpBox()
      return () => h('div')
    },
  })
  const wrapper = mount(Harness)
  return { wrapper, api: () => api }
}

describe('useHelpBox', () => {
  it('shows help automatically once mounted', () => {
    const { api } = mountWithHelpBox()
    expect(api().showHelp.value).toBe(true)
  })

  it('hide() turns help off', () => {
    const { api } = mountWithHelpBox()
    api().hide()
    expect(api().showHelp.value).toBe(false)
  })

  it('show() turns help back on', () => {
    const { api } = mountWithHelpBox()
    api().hide()
    api().show()
    expect(api().showHelp.value).toBe(true)
  })

  it('toggleHelp() flips from shown to hidden', () => {
    const { api } = mountWithHelpBox()
    expect(api().showHelp.value).toBe(true)
    api().toggleHelp()
    expect(api().showHelp.value).toBe(false)
  })

  it('toggleHelp() flips from hidden back to shown', () => {
    const { api } = mountWithHelpBox()
    api().hide()
    api().toggleHelp()
    expect(api().showHelp.value).toBe(true)
  })
})
