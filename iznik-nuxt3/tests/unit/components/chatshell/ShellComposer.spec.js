import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ShellComposer from '~/components/chatshell/ShellComposer.vue'

const stubs = { 'v-icon': true }

describe('ShellComposer', () => {
  it('sends trimmed text on Enter and clears the box', async () => {
    const w = mount(ShellComposer, { global: { stubs } })
    const ta = w.find('[data-testid="composer-input"]')
    await ta.setValue('  sofa to give away  ')
    await ta.trigger('keydown.enter')
    expect(w.emitted('send')[0]).toEqual(['sofa to give away'])
    expect(ta.element.value).toBe('')
  })
  it('shows the progress line with a stop button during a flow and hides the action row', async () => {
    const w = mount(ShellComposer, { props: { progress: { label: 'Giving', item: 'sofa', step: 2, total: 5 }, actions: [] }, global: { stubs } })
    expect(w.find('[data-testid="flow-progress"]').text()).toContain('Giving · sofa · 2 of 5')
    await w.find('[data-testid="flow-cancel"]').trigger('click')
    expect(w.emitted('cancel')).toHaveLength(1)
  })
  it('shows the persistent action chips and emits action on tap', async () => {
    const w = mount(ShellComposer, { props: { actions: [{ value: 'give', label: 'Give' }] }, global: { stubs } })
    await w.find('[data-testid="chip-give"]').trigger('click')
    expect(w.emitted('action')[0][0]).toEqual({ value: 'give', label: 'Give' })
  })
  it('keeps typing available while busy but holds send', async () => {
    const w = mount(ShellComposer, { props: { busy: true }, global: { stubs } })
    const ta = w.find('[data-testid="composer-input"]')
    expect(ta.attributes('disabled')).toBeUndefined()
    await ta.setValue('hello')
    expect(w.find('[data-testid="composer-send"]').attributes('disabled')).toBeDefined()
  })
})
