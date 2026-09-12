import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ShellSheet from '~/components/chatshell/ShellSheet.vue'

// One sheet for everything that rises over the chat: the body scrolls inside it, the
// page behind never grows, and the backdrop or the cross closes it.
describe('ShellSheet', () => {
  it('shows the title and slots, sizes itself, and closes from the cross or the backdrop but not the body', async () => {
    const w = mount(ShellSheet, {
      props: { title: 'Nearby', testid: 'nearby-sheet', height: '85%' },
      slots: {
        default: '<p class="row">A row</p>',
        tools: '<input class="tool" />',
        footer: '<button class="foot">More</button>',
      },
    })
    expect(w.find('.sheet-title').text()).toBe('Nearby')
    expect(w.find('[data-testid="nearby-sheet-body"] .row').exists()).toBe(true)
    expect(w.find('.sheet-tools .tool').exists()).toBe(true)
    expect(w.find('.sheet-footer .foot').exists()).toBe(true)
    expect(w.find('.sheet').attributes('style')).toContain('max-height: 85%')
    await w.find('.sheet').trigger('click')
    expect(w.emitted('close')).toBeUndefined()
    await w.find('[data-testid="nearby-sheet-close"]').trigger('click')
    await w.find('[data-testid="nearby-sheet"]').trigger('click')
    expect(w.emitted('close')).toHaveLength(2)
  })

  it('leaves out the tools and footer bars when those slots are empty', () => {
    const w = mount(ShellSheet, { props: { title: 'Who should have it?' } })
    expect(w.find('.sheet-tools').exists()).toBe(false)
    expect(w.find('.sheet-footer').exists()).toBe(false)
    expect(w.find('.sheet').attributes('aria-label')).toBe(
      'Who should have it?'
    )
    expect(w.find('.sheet').attributes('style')).toContain('max-height: 92%')
  })
})
