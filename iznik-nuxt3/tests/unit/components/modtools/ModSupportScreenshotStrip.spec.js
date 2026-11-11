import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSupportScreenshotStrip from '~/modtools/components/ModSupportScreenshotStrip.vue'

const SHOT = {
  name: 'err.png',
  mime: 'image/png',
  data: 'A',
  preview: 'data:image/png;base64,A',
}

function mountStrip(props = {}) {
  return mount(ModSupportScreenshotStrip, {
    props: { screenshots: [], ...props },
    global: {
      stubs: {
        'b-button': {
          template:
            '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
          props: ['disabled'],
        },
        'v-icon': true,
      },
    },
  })
}

describe('ModSupportScreenshotStrip', () => {
  it('shows a thumbnail for each picture and a remove button that says which', async () => {
    const w = mountStrip({ screenshots: [SHOT, { ...SHOT, name: 'two.png' }] })
    expect(w.findAll('.screenshot-thumb')).toHaveLength(2)
    await w.findAll('.screenshot-remove')[1].trigger('click')
    expect(w.emitted('remove')[0]).toEqual([1])
    expect(w.findAll('.screenshot-remove')[0].attributes('aria-label')).toBe(
      'Remove err.png'
    )
  })

  it('emits the chosen files', async () => {
    const w = mountStrip()
    const input = w.find('[data-testid="screenshot-input"]')
    expect(input.attributes('multiple')).toBeDefined()
    await input.trigger('change')
    expect(w.emitted('add')).toHaveLength(1)
  })

  it('shows an error and stops adding at the limit', () => {
    const w = mountStrip({
      screenshots: [SHOT, SHOT],
      max: 2,
      error: 'Too many.',
    })
    expect(w.text()).toContain('Too many.')
    expect(w.find('button').attributes('disabled')).toBeDefined()
  })
})
