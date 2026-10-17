import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFunnelPicker from '~/modtools/components/ModSysAdminUxFunnelPicker.vue'

const flows = [
  { flow: 'give', short: 'Offering', started: 1240 },
  { flow: 'ask', short: 'Asking', started: 0 },
  { flow: 'reply', short: 'Replying', started: 55 },
]

const make = (props = {}) =>
  mount(ModSysAdminUxFunnelPicker, {
    props: {
      flows,
      current: 'give',
      hrefFor: (patch) =>
        `/sysadmin?ux_section=${patch.section}&ux_flow=${patch.flow}`,
      ...props,
    },
  })

describe('ModSysAdminUxFunnelPicker', () => {
  it('is a labelled group with one button for each funnel it is given', () => {
    const w = make()
    expect(w.find('[role="group"]').attributes('aria-label')).toBe('Funnel')
    expect(w.findAll('a.ux-pick').map((a) => a.find('.name').text())).toEqual([
      'Offering',
      'Asking',
      'Replying',
    ])
  })

  it('says how many started, and "Nobody started" for a quiet funnel', () => {
    const w = make()
    expect(w.findAll('.count').map((c) => c.text())).toEqual([
      '1,240 started',
      'Nobody started',
      '55 started',
    ])
  })

  it('leaves the count out when it is not known yet', () => {
    const w = make({
      flows: [{ flow: 'give', short: 'Offering', started: null }],
    })
    expect(w.find('.count').exists()).toBe(false)
  })

  it('marks the open funnel as pressed', () => {
    const w = make({ current: 'ask' })
    expect(
      w.findAll('a.ux-pick').map((a) => a.attributes('aria-pressed'))
    ).toEqual(['false', 'true', 'false'])
    expect(w.findAll('a.ux-pick')[1].classes()).toContain('current')
  })

  it('marks none as pressed when the open funnel is not one it lists', () => {
    const w = make({ current: 'something_else' })
    for (const a of w.findAll('a.ux-pick')) {
      expect(a.attributes('aria-pressed')).toBe('false')
    }
  })

  it('makes each a real link to that funnel', () => {
    const w = make()
    expect(w.findAll('a.ux-pick').map((a) => a.attributes('href'))).toEqual([
      '/sysadmin?ux_section=funnels&ux_flow=give',
      '/sysadmin?ux_section=funnels&ux_flow=ask',
      '/sysadmin?ux_section=funnels&ux_flow=reply',
    ])
  })

  it('says which funnel was chosen on a plain click', async () => {
    const w = make()
    await w.findAll('a.ux-pick')[2].trigger('click')
    expect(w.emitted('navigate')).toEqual([[{ flow: 'reply' }]])
  })

  it('leaves a click with a modifier key to the browser', async () => {
    const w = make()
    await w.findAll('a.ux-pick')[2].trigger('click', { ctrlKey: true })
    expect(w.emitted('navigate')).toBeUndefined()
  })

  it('can be chosen with the space bar, as a button can', async () => {
    const w = make()
    await w.findAll('a.ux-pick')[1].trigger('keydown', { key: ' ' })
    expect(w.emitted('navigate')).toEqual([[{ flow: 'ask' }]])
  })
})
