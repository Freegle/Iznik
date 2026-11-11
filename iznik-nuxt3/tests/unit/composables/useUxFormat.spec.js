import { describe, it, expect } from 'vitest'
import {
  pct,
  withCount,
  ms,
  signed,
  sumBy,
  stepsWithPrevious,
  regretLabel,
  healthLabel,
  filterNotes,
  comparedWith,
  viewSummary,
  isPlainClick,
  DEVICE_LABELS,
  OLD_SERVER_NOTE,
} from '~/modtools/composables/useUxFormat'

describe('useUxFormat', () => {
  it('pct gives one decimal, or a dash when there is no denominator', () => {
    expect(pct(1, 3)).toBe('33.3%')
    expect(pct(0, 10)).toBe('0.0%')
    expect(pct(5, 0)).toBe('–')
    expect(pct(undefined, undefined)).toBe('–')
  })

  it('withCount always shows the counts beside the percentage', () => {
    expect(withCount(1200, 4800)).toBe('25.0% (1,200/4,800)')
    expect(withCount(0, 0)).toBe('– (0/0)')
    expect(withCount(null, null)).toBe('– (0/0)')
  })

  it('ms shows milliseconds under a second and seconds above', () => {
    expect(ms(850)).toBe('850 ms')
    expect(ms(12500)).toBe('12.5 s')
    expect(ms(null)).toBe('–')
    expect(ms(undefined)).toBe('–')
  })

  it('signed puts a sign on a number', () => {
    expect(signed(12.34)).toBe('+12.3')
    expect(signed(-3)).toBe('-3.0')
    expect(signed(0)).toBe('0.0')
    expect(signed(null)).toBe('–')
  })

  it('sumBy adds a field across rows, ignoring missing values', () => {
    expect(sumBy([{ a: 1 }, { a: 2 }, {}], 'a')).toBe(3)
    expect(sumBy(undefined, 'a')).toBe(0)
  })

  it('stepsWithPrevious pairs each step with the same step last period', () => {
    const rows = stepsWithPrevious({
      flows: [
        {
          flow: 'post',
          steps: [
            { step: 'a', entered: 5 },
            { step: 'b', entered: 6 },
          ],
        },
      ],
      previous: {
        flows: [{ flow: 'post', steps: [{ step: 'a', entered: 9 }] }],
      },
    })
    expect(rows).toHaveLength(2)
    expect(rows[0]).toMatchObject({ flow: 'post', step: 'a', entered: 5 })
    expect(rows[0].prev.entered).toBe(9)
    expect(rows[1].prev).toBeNull()
    expect(stepsWithPrevious(undefined)).toEqual([])
  })

  describe('regretLabel', () => {
    it.each([
      ['withdrawn_24h', 'Post withdrawn within 24 hours'],
      ['duplicate_10m', 'Posted the same thing twice within 10 minutes'],
      ['promise_reneged', 'Promise broken'],
      ['reply_after_taken', 'Replied after the item was taken'],
    ])('says %s in words', (key, words) => {
      expect(regretLabel(key)).toBe(words)
    })

    it('prints a key it does not know as it is', () => {
      expect(regretLabel('something_new')).toBe('something_new')
    })
  })

  describe('healthLabel', () => {
    it('says what a funnel start check counts, with the funnel in words', () => {
      expect(healthLabel('flow_start:give')).toBe(
        'Starts of Offering something'
      )
      expect(healthLabel('flow_start:ask')).toBe(
        'Starts of Asking for something'
      )
    })

    it.each([
      ['event:page_view', 'Page views'],
      ['event:interaction', 'Clicks and scrolls'],
      ['event:impressions', 'Things shown on screen'],
      ['event:flow', 'Funnel steps'],
      ['event:session_start', 'Visits starting'],
    ])('says %s in words', (key, words) => {
      expect(healthLabel(key)).toBe(words)
    })

    it('never uses the stored word for a funnel', () => {
      for (const key of [
        'flow_start:give',
        'flow_start:login',
        'event:flow',
        'event:page_view',
      ]) {
        expect(healthLabel(key)).not.toMatch(/flow/i)
      }
    })

    it('prints a name it does not know as it is', () => {
      expect(healthLabel('event:something_new')).toBe('event:something_new')
      expect(healthLabel('something_new')).toBe('something_new')
      expect(healthLabel('flow_start:newflow')).toBe('Starts of newflow')
    })

    it('prints a start check with no funnel named as it is', () => {
      expect(healthLabel('flow_start:')).toBe('flow_start:')
    })

    it('copes with no name', () => {
      expect(healthLabel(undefined)).toBe('')
      expect(healthLabel('')).toBe('')
    })
  })

  describe('filterNotes', () => {
    const both = { device: 'mobile', signed_in: '1' }

    it('says nothing when no filter was asked for', () => {
      expect(filterNotes('funnels', { filters: {}, ignored: [] }, {})).toEqual(
        []
      )
      expect(
        filterNotes(
          'funnels',
          { filters: {}, ignored: [] },
          { start: 'a', end: 'b' }
        )
      ).toEqual([])
    })

    it('says nothing before the answer has arrived', () => {
      expect(filterNotes('funnels', null, both)).toEqual([])
      expect(filterNotes('funnels', undefined, both)).toEqual([])
    })

    it('says nothing when the server used every filter asked for', () => {
      const data = {
        filters: { device: 'mobile', signed_in: '1' },
        ignored: [],
      }
      expect(filterNotes('funnels', data, both)).toEqual([])
    })

    it('warns once when the server does not say which filters it used', () => {
      // An old server ignores the parameters and sends no filters key.
      expect(filterNotes('adoption', { features: [] }, both)).toEqual([
        OLD_SERVER_NOTE,
      ])
      expect(OLD_SERVER_NOTE).toBe(
        'The server did not say which filters it used, so these figures may not be filtered.'
      )
    })

    it('warns when a filter was asked for and the server neither applied nor ignored it', () => {
      const data = { filters: { device: '', signed_in: '' }, ignored: [] }
      expect(filterNotes('funnels', data, { device: 'mobile' })).toEqual([
        OLD_SERVER_NOTE,
      ])
    })

    it('says the device was ignored', () => {
      const data = {
        filters: { device: '', signed_in: '' },
        ignored: ['device'],
      }
      expect(filterNotes('adoption', data, { device: 'mobile' })).toEqual([
        'Not filtered by device. Shows every device.',
      ])
    })

    it('says sign-in was ignored', () => {
      const data = {
        filters: { device: 'tablet', signed_in: '' },
        ignored: ['signed_in'],
      }
      expect(
        filterNotes('friction', data, { device: 'tablet', signed_in: '0' })
      ).toEqual([
        'Not filtered by sign-in. Shows signed-in and signed-out people together.',
      ])
    })

    it('gives one sentence when both were ignored', () => {
      const data = {
        filters: { device: '', signed_in: '' },
        ignored: ['device', 'signed_in'],
      }
      expect(filterNotes('regret', data, both)).toEqual([
        'Not filtered by device or sign-in. Shows everyone.',
      ])
    })

    it('has its own sentence for the outcomes, which count everyone', () => {
      const data = {
        filters: { device: 'mobile', signed_in: '' },
        ignored: ['signed_in'],
      }
      expect(filterNotes('outcomes', data, both)).toEqual([
        'Started and finished count everyone. Signed in changes the steps only.',
      ])
    })

    it('only mentions an ignored filter that was asked for', () => {
      const data = {
        filters: { device: '', signed_in: '' },
        ignored: ['signed_in'],
      }
      expect(filterNotes('funnels', data, { device: '' })).toEqual([])
    })
  })

  describe('viewSummary', () => {
    const seven = {
      period: '7days',
      from: '',
      to: '',
      device: '',
      signed: '',
      uses: ['period', 'device'],
    }

    it("prints the server's dates and the period before", () => {
      const data = {
        start: '2026-10-08',
        end: '2026-10-14',
        previous: { start: '2026-10-01', end: '2026-10-07' },
      }
      expect(viewSummary(seven, data)).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC). Compared with 1 Oct to 7 Oct.'
      )
    })

    it('leaves out the comparison when the server gave no dates for it', () => {
      expect(
        viewSummary(seven, { start: '2026-10-08', end: '2026-10-14' })
      ).toBe('Showing 8 Oct to 14 Oct 2026 (7 days, UTC).')
    })

    it('uses the dates of a custom range before the server has answered', () => {
      const custom = {
        ...seven,
        period: 'custom',
        from: '2026-09-01',
        to: '2026-09-30',
      }
      expect(viewSummary(custom, null)).toBe(
        'Showing 1 Sep to 30 Sep 2026 (30 days, UTC).'
      )
    })

    it('says which period when it has no dates yet', () => {
      expect(viewSummary(seven, null)).toBe('Showing the last 7 days.')
      expect(viewSummary({ ...seven, period: '90days' }, null)).toBe(
        'Showing the last 90 days.'
      )
    })

    it('writes the years when a range crosses new year', () => {
      const data = {
        start: '2026-12-29',
        end: '2027-01-04',
        previous: { start: '2026-12-22', end: '2026-12-28' },
      }
      expect(viewSummary(seven, data)).toBe(
        'Showing 29 Dec 2026 to 4 Jan 2027 (7 days, UTC). Compared with 22 Dec to 28 Dec 2026.'
      )
    })

    it('says one day for a range of one day', () => {
      expect(
        viewSummary(seven, { start: '2026-10-14', end: '2026-10-14' })
      ).toBe('Showing 14 Oct 2026 (1 day, UTC).')
    })

    it('adds the device and sign-in when a section uses them', () => {
      const view = {
        ...seven,
        device: 'app-ios',
        signed: 'in',
        uses: ['period', 'device', 'signed'],
      }
      const data = { start: '2026-10-08', end: '2026-10-14' }
      expect(viewSummary(view, data)).toBe(
        'Showing 8 Oct to 14 Oct 2026 (7 days, UTC). Device: iOS app. Signed in only.'
      )
      expect(viewSummary({ ...view, signed: 'out' }, data)).toContain(
        'Not signed in only.'
      )
    })

    it('does not print Signed in for a section that has no Signed in select', () => {
      const view = {
        ...seven,
        device: 'mobile',
        signed: 'in',
        uses: ['period', 'device'],
      }
      const t = viewSummary(view, { start: '2026-10-08', end: '2026-10-14' })
      expect(t).toContain('Device: Website on a phone.')
      expect(t).not.toContain('Signed in only')
    })

    it('does not print a device for a section that does not use one', () => {
      const view = { ...seven, device: 'mobile', uses: ['period'] }
      expect(viewSummary(view, null)).not.toContain('Device')
    })
  })

  it('comparedWith gives the sentence, and nothing without the dates', () => {
    expect(
      comparedWith({
        end: '2026-10-14',
        previous: { start: '2026-10-01', end: '2026-10-07' },
      })
    ).toBe('Compared with 1 Oct to 7 Oct.')
    expect(comparedWith({ end: '2026-10-14' })).toBe('')
    expect(comparedWith(null)).toBe('')
  })

  it('names every device the filter offers', () => {
    expect(Object.keys(DEVICE_LABELS)).toEqual([
      'web',
      'app',
      'mobile',
      'tablet',
      'desktop',
      'app-ios',
      'app-android',
    ])
    expect(DEVICE_LABELS.mobile).toBe('Website on a phone')
    expect(DEVICE_LABELS['app-android']).toBe('Android app')
  })

  it('leaves a click with a modifier key to the browser', () => {
    expect(isPlainClick({ button: 0 })).toBe(true)
    expect(isPlainClick({})).toBe(true)
    expect(isPlainClick({ button: 0, ctrlKey: true })).toBe(false)
    expect(isPlainClick({ button: 0, metaKey: true })).toBe(false)
    expect(isPlainClick({ button: 0, shiftKey: true })).toBe(false)
    expect(isPlainClick({ button: 1 })).toBe(false)
  })
})
