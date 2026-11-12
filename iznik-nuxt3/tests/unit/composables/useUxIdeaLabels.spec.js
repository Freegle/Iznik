import { describe, it, expect } from 'vitest'
import {
  FILES_SHOWN,
  KEPT_FOR_NOW,
  NO_IDEAS,
  NO_IDEAS_HERE,
  SCORE_NOTE,
  STATUS_ORDER,
  categoryWord,
  evidenceLines,
  experimentWord,
  filesOf,
  funnelLine,
  gainText,
  ideaSourceLabel,
  percentText,
  scoreText,
  statusWord,
  typeWord,
} from '~/modtools/composables/useUxIdeaLabels'

describe('useUxIdeaLabels', () => {
  describe('statusWord', () => {
    it('has a plain word for every status an idea can have, in the order of the strip', () => {
      expect(STATUS_ORDER).toEqual([
        'open',
        'scheduled',
        'running',
        'done',
        'rejected',
      ])
      expect(STATUS_ORDER.map(statusWord)).toEqual([
        'Waiting',
        'Queued',
        'Being tried',
        'Tried',
        'Set aside',
      ])
    })

    it('prints a status it does not know as it is, and nothing for nothing', () => {
      expect(statusWord('paused')).toBe('paused')
      expect(statusWord(undefined)).toBe('')
      expect(statusWord(null)).toBe('')
    })
  })

  describe('typeWord', () => {
    it('names the two kinds of idea', () => {
      expect(typeWord('fix')).toBe('Fix')
      expect(typeWord('experiment')).toBe('Experiment')
      expect(typeWord('other')).toBe('other')
      expect(typeWord(undefined)).toBe('')
    })
  })

  describe('ideaSourceLabel', () => {
    it('says that an idea made by the generator is from the idea generator', () => {
      expect(ideaSourceLabel('ai')).toBe('From the idea generator')
    })

    it('prints any other source as it is, including the ones an older version labelled', () => {
      // The loop is not a word a reader knows, so no source is labelled with it any more.
      for (const source of ['human', 'member', 'survey', 'loop', 'reports']) {
        expect(ideaSourceLabel(source), source).toBe(source)
      }
      expect(ideaSourceLabel(undefined)).toBe('')
      expect(ideaSourceLabel(null)).toBe('')
    })

    it('never says loop on screen', () => {
      expect(ideaSourceLabel('ai')).not.toMatch(/loop/i)
    })
  })

  describe('experimentWord', () => {
    it('uses the words the Experiments section uses for the same status', () => {
      expect(
        [
          'draft',
          'running',
          'adopted',
          'closed',
          'reverted',
          'inconclusive',
          'stopped',
        ].map((status) => experimentWord({ status }))
      ).toEqual([
        'Not started',
        'Running',
        'Kept',
        'Kept, cleaned up',
        'Taken off',
        'Could not tell',
        'Stopped',
      ])
      expect(experimentWord({ status: 'odd' })).toBe('odd')
      expect(experimentWord({})).toBe('')
      expect(experimentWord(undefined)).toBe('')
      expect(experimentWord(null)).toBe('')
    })

    it('says an adopted experiment is kept for now while its holdout lasts, as the Experiments section does', () => {
      expect(KEPT_FOR_NOW).toBe('Kept for now')
      expect(
        experimentWord({
          status: 'adopted',
          holdout_until: '2026-10-30 09:00:00',
        })
      ).toBe('Kept for now')
      // The holdout is over (the route sends none), or there never was one.
      expect(experimentWord({ status: 'adopted', holdout_until: null })).toBe(
        'Kept'
      )
      expect(experimentWord({ status: 'adopted' })).toBe('Kept')
      // Only an adopted experiment has a holdout, whatever else is sent.
      for (const status of ['running', 'closed', 'reverted', 'stopped']) {
        expect(
          experimentWord({ status, holdout_until: '2026-10-30 09:00:00' }),
          status
        ).not.toBe('Kept for now')
      }
    })
  })

  describe('categoryWord', () => {
    it('turns the generator’s kinds into words, and prints one it does not know as it is', () => {
      expect(categoryWord('copy')).toBe('Wording')
      expect(categoryWord('loggedout')).toBe('People not signed in')
      expect(categoryWord('retire')).toBe('Taking something away')
      expect(categoryWord('mystery')).toBe('mystery')
      expect(categoryWord(undefined)).toBe('')
    })
  })

  describe('scoreText and percentText', () => {
    it('keeps a score as the database does, to two places, without trailing zeros', () => {
      expect(scoreText(8.25)).toBe('8.25')
      expect(scoreText('4.00')).toBe('4')
      expect(scoreText(1.6)).toBe('1.6')
      expect(scoreText(0)).toBe('0')
      expect(scoreText(-1.5)).toBe('-1.5')
    })

    it('says nothing for a score that is not a number', () => {
      for (const v of [null, undefined, '', 'high', NaN]) {
        expect(scoreText(v), String(v)).toBe('')
      }
    })

    it('shows a share as a whole percent', () => {
      expect(percentText(0.5)).toBe('50%')
      expect(percentText('0.4')).toBe('40%')
      expect(percentText(1)).toBe('100%')
      expect(percentText(null)).toBe('')
      expect(percentText('')).toBe('')
      expect(percentText('x')).toBe('')
    })
  })

  describe('funnelLine', () => {
    it('says how many are waiting and which is best', () => {
      expect(
        funnelLine('Offering something', {
          flow: 'give',
          open: 5,
          best: { id: 3, title: 'Ask to sign in first', score: 8.2 },
        })
      ).toBe(
        'Offering something. 5 waiting. Best: Ask to sign in first (score 8.2)'
      )
    })

    it('names the number as a score, so a reader does not have to guess', () => {
      const line = funnelLine('Settings', {
        flow: 'settings',
        open: 1,
        best: { id: 1, title: 'Shorter', score: 0 },
      })
      expect(line).toBe('Settings. 1 waiting. Best: Shorter (score 0)')
    })

    it('says none are waiting when none are, or when the funnel has no row', () => {
      const none = 'Offering something. No ideas waiting.'
      expect(
        funnelLine('Offering something', { flow: 'give', open: 0, best: null })
      ).toBe(none)
      expect(funnelLine('Offering something', undefined)).toBe(none)
      expect(funnelLine('Offering something', {})).toBe(none)
    })

    it('leaves the best out when the route sent none', () => {
      expect(funnelLine('Settings', { flow: 'settings', open: 2 })).toBe(
        'Settings. 2 waiting.'
      )
    })
  })

  describe('evidenceLines', () => {
    it('writes what the generator kept in words', () => {
      const lines = evidenceLines({
        category: 'copy',
        expected_points: 4,
        confidence: 0.5,
        locality: 5,
        risk: 'Fewer photos.',
      })
      expect(lines.map((l) => [l.key, l.label, l.text])).toEqual([
        ['category', 'Kind of change', 'Wording'],
        [
          'points',
          'Expected gain',
          '4 more people finish in every 100 who start',
        ],
        ['confidence', 'How sure', '50%'],
        ['locality', 'How small the change is', '5 of 5'],
        ['risk', 'Risk', 'Fewer photos.'],
      ])
    })

    it('leaves out a line the generator gave nothing for', () => {
      expect(evidenceLines({ category: 'copy' }).map((l) => l.key)).toEqual([
        'category',
      ])
      expect(
        evidenceLines({ confidence: null, locality: '', risk: '' })
      ).toEqual([])
      expect(evidenceLines(null)).toEqual([])
      expect(evidenceLines('text')).toEqual([])
      expect(evidenceLines(undefined)).toEqual([])
    })

    it('keeps a zero, which is a figure and not a gap', () => {
      const lines = evidenceLines({ expected_points: 0, confidence: 0 })
      expect(lines.map((l) => l.text)).toEqual([
        'No change in how many finish',
        '0%',
      ])
    })

    it('says one person in the singular', () => {
      const text = (expected_points) =>
        evidenceLines({ expected_points })[0].text
      expect(text(1)).toBe('1 more person finishes in every 100 who start')
      expect(text('1')).toBe('1 more person finishes in every 100 who start')
      expect(text(1.0)).toBe('1 more person finishes in every 100 who start')
      expect(text(0.5)).toBe('0.5 more people finish in every 100 who start')
      expect(text(2)).toBe('2 more people finish in every 100 who start')
      expect(text(6)).toBe('6 more people finish in every 100 who start')
    })
  })

  describe('gainText', () => {
    it('says a gain in people in every 100 who start, one in the singular', () => {
      expect(gainText(6)).toBe('6 more people finish in every 100 who start')
      expect(gainText(2.5)).toBe(
        '2.5 more people finish in every 100 who start'
      )
      expect(gainText(1)).toBe('1 more person finishes in every 100 who start')
      expect(gainText(0.5)).toBe(
        '0.5 more people finish in every 100 who start'
      )
    })

    it('shows one place and no trailing zero, and one that rounds to one is still one', () => {
      expect(gainText(3.0)).toBe('3 more people finish in every 100 who start')
      expect(gainText(2.96)).toBe('3 more people finish in every 100 who start')
      expect(gainText(1.04)).toBe(
        '1 more person finishes in every 100 who start'
      )
    })

    it('says fewer for a fall, and says so when nothing changes', () => {
      expect(gainText(-2)).toBe('2 fewer people finish in every 100 who start')
      expect(gainText(-1)).toBe(
        '1 fewer person finishes in every 100 who start'
      )
      expect(gainText(0)).toBe('No change in how many finish')
      expect(gainText(0.04)).toBe('No change in how many finish')
    })

    it('says nothing for what is not a number', () => {
      for (const v of [null, undefined, '', 'many', NaN]) {
        expect(gainText(v), String(v)).toBe('')
      }
    })

    it('uses no word for a measure that a reader has to be told', () => {
      for (const v of [0, 1, 2.5, -1]) {
        expect(gainText(v)).not.toMatch(/points|rate|guardrail|flow/i)
      }
    })
  })

  describe('SCORE_NOTE', () => {
    it('says what the score is made of and which end is first, in plain words', () => {
      expect(SCORE_NOTE).toBe(
        'Score is the expected gain, times how sure, times how small the change is. The highest score is listed first.'
      )
    })
  })

  describe('filesOf', () => {
    it('lists the first few files and says how many more there are', () => {
      const files = Array.from(
        { length: FILES_SHOWN + 3 },
        (_, i) => `f${i}.vue`
      )
      const out = filesOf({ files })
      expect(out.shown).toEqual(files.slice(0, FILES_SHOWN))
      expect(out.more).toBe(3)
    })

    it('shows them all when there are few, and ignores what is not a file name', () => {
      expect(filesOf({ files: ['a.vue', 3, ' ', null, 'b.vue'] })).toEqual({
        shown: ['a.vue', 'b.vue'],
        more: 0,
      })
      expect(filesOf({})).toEqual({ shown: [], more: 0 })
      expect(filesOf(null)).toEqual({ shown: [], more: 0 })
      expect(filesOf({ files: 'a.vue' })).toEqual({ shown: [], more: 0 })
    })
  })

  describe('the empty texts', () => {
    it('are plain and kind', () => {
      expect(NO_IDEAS).toBe(
        'No ideas yet. The idea generator adds them once a day.'
      )
      expect(NO_IDEAS_HERE).toBe('No ideas here.')
    })
  })
})
