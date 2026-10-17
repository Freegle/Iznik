import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ModSysAdminUxFunnelTable from '~/modtools/components/ModSysAdminUxFunnelTable.vue'

const row = (flow, over = {}) => ({
  flow,
  started: 1240,
  finished: 640,
  finishedRate: 640 / 1240,
  beforeRate: 0.49,
  loss: {
    state: 'step',
    step: 'give-mobile-photos',
    label: 'Photos',
    lossRate: 0.3,
    visits: 375,
    abandoned: 112,
  },
  ...over,
})

const make = (rows, props = {}) =>
  mount(ModSysAdminUxFunnelTable, {
    props: {
      rows,
      hrefFor: (patch) =>
        `/sysadmin?ux_section=${patch.section}&ux_flow=${patch.flow}${patch.step ? '&ux_step=' + patch.step : ''}`,
      ...props,
    },
  })

const cells = (w, i = 0) => w.findAll('tbody tr')[i].findAll('th, td')

describe('ModSysAdminUxFunnelTable', () => {
  it('has the four headings', () => {
    const w = make([row('give')])
    expect(w.findAll('thead th').map((h) => h.text())).toEqual([
      'Funnel',
      'Started',
      'Finished',
      'Biggest loss',
    ])
  })

  it('sits in a box that scrolls on a narrow screen', () => {
    expect(
      make([row('give')])
        .find('.table-responsive table')
        .exists()
    ).toBe(true)
  })

  it('names the funnel in words, as a link that opens it', async () => {
    const w = make([row('chat_outcome')])
    const link = cells(w)[0].find('a')
    expect(link.text()).toBe('Marking taken or received')
    expect(link.attributes('href')).toBe(
      '/sysadmin?ux_section=funnels&ux_flow=chat_outcome'
    )
    await link.trigger('click')
    expect(w.emitted('navigate')).toEqual([
      [{ section: 'funnels', flow: 'chat_outcome' }],
    ])
  })

  it('shows how many started, with a thousands separator', () => {
    expect(cells(make([row('give')]))[1].text()).toBe('1,240')
  })

  it('shows how many finished as a whole percent with the count', () => {
    expect(
      cells(make([row('give')]))[2]
        .find('.finished')
        .text()
    ).toBe('52% (640)')
  })

  describe('how the period before compares', () => {
    const before = (rate, finishedRate = 0.52) =>
      cells(make([row('give', { finishedRate, beforeRate: rate })]))[2]
        .find('.before')
        .text()

    it('says how many points higher', () => {
      expect(before(0.49)).toBe('Before: 49% (3 points higher)')
    })

    it('says how many points lower', () => {
      expect(before(0.54)).toBe('Before: 54% (2 points lower)')
    })

    it('says one point in the singular', () => {
      expect(before(0.51)).toBe('Before: 51% (1 point higher)')
    })

    it('says there was no change', () => {
      expect(before(0.52)).toBe('Before: 52% (no change)')
    })

    it('agrees with the whole percents on screen, not the exact figures', () => {
      // 52.4% and 51.6% show as 52% and 52%.
      expect(before(0.516, 0.524)).toBe('Before: 52% (no change)')
    })

    it('says so when there are no figures for the period before', () => {
      expect(before(null)).toBe('No figures for the period before.')
    })
  })

  describe('the biggest loss', () => {
    it('names the step, how much of its visits ended there, and links to it', async () => {
      const w = make([row('give')])
      const link = cells(w)[3].find('a')
      expect(link.text()).toBe('Photos: 30% of 375 visits ended there')
      expect(link.attributes('href')).toBe(
        '/sysadmin?ux_section=funnels&ux_flow=give&ux_step=give-mobile-photos'
      )
      await link.trigger('click')
      expect(w.emitted('navigate')).toEqual([
        [{ section: 'funnels', flow: 'give', step: 'give-mobile-photos' }],
      ])
    })

    it('says no step lost many people', () => {
      const w = make([row('give', { loss: { state: 'none' } })])
      expect(cells(w)[3].text()).toBe('No step lost many people')
      expect(cells(w)[3].find('a').exists()).toBe(false)
    })

    it('says there are too few visits to say', () => {
      const w = make([row('give', { loss: { state: 'few' } })])
      expect(cells(w)[3].text()).toBe('Too few visits to say')
    })
  })

  describe('a funnel nobody started', () => {
    const quiet = row('settings', {
      started: 0,
      finished: 0,
      finishedRate: null,
      beforeRate: null,
      loss: { state: 'nobody' },
    })

    it('reads 0 started and a dash in the other cells', () => {
      const c = cells(make([quiet]))
      expect(c[1].text()).toBe('0')
      expect(c[2].text()).toBe('–')
      expect(c[3].text()).toBe('–')
    })

    it('still links to the funnel', () => {
      expect(
        cells(make([quiet]))[0]
          .find('a')
          .text()
      ).toBe('Settings')
    })
  })

  it('keeps the rows in the order it is given', () => {
    const w = make([row('give'), row('ask'), row('reply')])
    expect(w.findAll('tbody tr').map((r) => r.attributes('data-flow'))).toEqual(
      ['give', 'ask', 'reply']
    )
  })

  it('leaves a click with a modifier key to the browser', async () => {
    const w = make([row('give')])
    await cells(w)[0].find('a').trigger('click', { metaKey: true })
    expect(w.emitted('navigate')).toBeUndefined()
  })
})
