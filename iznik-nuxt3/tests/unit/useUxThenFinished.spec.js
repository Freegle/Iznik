import { describe, it, expect } from 'vitest'
import {
  compareFinish,
  finishedCell,
  othersCell,
  verdictCell,
  harmFirst,
  flagSentence,
  minPeople,
} from '~/modtools/composables/useUxThenFinished'

const t = (people, finished) => ({ people, finished })

describe('compareFinish', () => {
  it('gives null when the server sent no base', () => {
    expect(compareFinish(t(10, 5), undefined)).toBeNull()
    expect(compareFinish(t(10, 5), t(0, 0))).toBeNull()
  })

  it('sets those with the sign against the others at the step', () => {
    // 100 reached the step and 70 finished; 30 went back and 8 of them finished.
    const c = compareFinish(t(30, 8), t(100, 70))
    expect(c.people).toBe(30)
    expect(c.finished).toBe(8)
    expect(c.others).toBe(70)
    expect(c.othersFinished).toBe(62)
    expect(c.share).toBeCloseTo(8 / 30)
    expect(c.othersShare).toBeCloseTo(62 / 70)
    expect(c.state).toBe('gave-up')
    // 0.886 - 0.267 = 0.62 of 30 people.
    expect(c.lost).toBe(19)
  })

  it('says most still finished when they finish about as often as the rest', () => {
    const c = compareFinish(t(40, 30), t(140, 105))
    expect(c.share).toBeCloseTo(0.75)
    expect(c.othersShare).toBeCloseTo(0.75)
    expect(c.state).toBe('finished')
  })

  it('does not call a small gap giving up', () => {
    // 70% against 75%: five points is inside the noise.
    expect(compareFinish(t(100, 70), t(300, 220)).state).toBe('finished')
  })

  it('calls it similar when few finish either way', () => {
    const c = compareFinish(t(50, 10), t(250, 55))
    expect(c.state).toBe('similar')
  })

  it('needs enough visits on both sides', () => {
    expect(compareFinish(t(19, 1), t(200, 150)).state).toBe('few')
    expect(compareFinish(t(30, 5), t(45, 40)).state).toBe('few')
    expect(compareFinish(t(20, 1), t(200, 150)).state).toBe('gave-up')
  })

  it('needs more with a filter', () => {
    expect(minPeople(false)).toBe(20)
    expect(minPeople(true)).toBe(50)
    expect(compareFinish(t(30, 1), t(200, 150), { min: 50 }).state).toBe('few')
  })

  it('treats nobody with the sign as nothing to compare, not as finishing', () => {
    const c = compareFinish(t(0, 0), t(100, 70))
    expect(c.state).toBe('few')
    expect(c.share).toBeNull()
    expect(finishedCell(c)).toBe('–')
    expect(verdictCell(c)).toBe('')
  })

  it('never lets a count run past its base', () => {
    const c = compareFinish(t(50, 60), t(40, 10))
    expect(c.finished).toBeLessThanOrEqual(c.people)
    expect(c.others).toBe(0)
    expect(othersCell(c)).toBe('–')
  })
})

describe('the cells', () => {
  it('print a whole percent with the counts', () => {
    const c = compareFinish(t(30, 8), t(100, 70))
    expect(finishedCell(c)).toBe('27% (8/30)')
    expect(othersCell(c)).toBe('89% (62/70)')
    expect(verdictCell(c)).toBe('Many gave up')
  })

  it('say why a row is not compared', () => {
    expect(verdictCell(compareFinish(t(5, 1), t(100, 70)))).toBe(
      'Too few to say'
    )
    expect(verdictCell(compareFinish(t(40, 30), t(140, 105)))).toBe(
      'Most still finished'
    )
  })
})

describe('harmFirst', () => {
  const row = (name, c) => ({ name, c })
  it('puts the ones who gave up first, the worst of them first, and keeps the rest in order', () => {
    const rows = [
      row('a', compareFinish(t(40, 30), t(140, 105))),
      row('b', compareFinish(t(30, 8), t(100, 70))),
      row('c', compareFinish(t(100, 40), t(300, 250))),
      row('d', null),
      row('e', compareFinish(t(5, 1), t(100, 70))),
    ]
    expect(harmFirst(rows).map((r) => r.name)).toEqual([
      'c',
      'b',
      'a',
      'd',
      'e',
    ])
  })
})

describe('flagSentence', () => {
  const r = (label, verb, c) => ({ label, verb, c })

  it('names the worst place and gives both shares', () => {
    const s = flagSentence(
      [
        r('Details', 'went back', compareFinish(t(30, 9), t(100, 70))),
        r('Options', 'went back', compareFinish(t(40, 30), t(140, 105))),
      ],
      'went back'
    )
    expect(s).toBe(
      'At Details, 30% of those who went back finished, against 87% who did not.'
    )
  })

  it('says it is less of a worry when those who can be compared mostly finished', () => {
    expect(
      flagSentence(
        [r('Details', 'went back', compareFinish(t(40, 30), t(140, 105)))],
        'went back'
      )
    ).toBe('Most who went back still finished, so this is less of a worry.')
  })

  it('does not say less of a worry when few finish either way', () => {
    expect(
      flagSentence(
        [r('Details', 'went back', compareFinish(t(50, 10), t(250, 55)))],
        'went back'
      )
    ).toBe('Those who went back finished about as often as those who did not.')
  })

  it('says nothing when nothing can be compared', () => {
    expect(flagSentence([], 'went back')).toBe('')
    expect(
      flagSentence(
        [r('Details', 'went back', compareFinish(t(5, 1), t(100, 70)))],
        'went back'
      )
    ).toBe('')
    expect(flagSentence([r('Details', 'went back', null)], 'went back')).toBe(
      ''
    )
  })

  it('uses no jargon or idiom', () => {
    const s = flagSentence(
      [r('Details', 'went back', compareFinish(t(30, 9), t(100, 70)))],
      'went back'
    )
    expect(s).not.toMatch(/cohort|funnel|conversion|churn|stood out/i)
  })
})
