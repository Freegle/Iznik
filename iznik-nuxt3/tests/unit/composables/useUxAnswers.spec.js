import { describe, it, expect } from 'vitest'
import {
  PERCENT_MIN_ANSWERS,
  ofAnswered,
  countOf,
  ofWrittenAnswers,
  mentionedIn,
  mentionCount,
  rankedLine,
  kindOf,
  kindLabel,
  KIND_LABELS,
  standingText,
  signed,
  balanceWord,
  areaBalances,
  balanceSummary,
  MINUS,
} from '~/modtools/composables/useUxAnswers'

// A tally as the questions route sends it.
const t = (key, label, most, least, times_shown = 0) => ({
  key,
  label,
  most,
  least,
  times_shown,
  count: 0,
})

describe('useUxAnswers', () => {
  it('gives a percentage only from 100 answers', () => {
    expect(PERCENT_MIN_ANSWERS).toBe(100)
  })

  it('ofAnswered shows a count only below 100 answers and a percentage from 100', () => {
    expect(ofAnswered(18, 40)).toBe('18 of 40')
    expect(ofAnswered(18, 99)).toBe('18 of 99')
    expect(ofAnswered(18, 100)).toBe('18 of 100 (18%)')
    expect(ofAnswered(1200, 4800)).toBe('1,200 of 4,800 (25%)')
    expect(ofAnswered(3, 0)).toBe('3')
    expect(ofAnswered(undefined, undefined)).toBe('0')
    // The base can differ from the answers: a percentage is decided by how many members answered.
    expect(ofAnswered(30, 150, 60)).toBe('30 of 150')
    expect(ofAnswered(30, 150, 150)).toBe('30 of 150 (20%)')
  })

  describe('countOf', () => {
    it('uses the singular for exactly one and the plural for the rest, including none', () => {
      expect(countOf(1, 'problem')).toBe('1 problem')
      expect(countOf(0, 'problem')).toBe('0 problems')
      expect(countOf(3, 'problem')).toBe('3 problems')
    })

    it('puts a separator in a large number, and takes a plural of its own', () => {
      expect(countOf(1200, 'answer')).toBe('1,200 answers')
      expect(countOf(2, 'person', 'people')).toBe('2 people')
      expect(countOf(1, 'person', 'people')).toBe('1 person')
    })

    it('reads a missing or bad number as none', () => {
      expect(countOf(undefined, 'time')).toBe('0 times')
      expect(countOf('x', 'time')).toBe('0 times')
    })
  })

  // The base of a problem or a value is the written answers the coder read for its question. It is not
  // the count of members who answered that question, which also holds the ones who wrote nothing, so
  // the words say "written answers" and keep "who answered" for the questions.
  describe('ofWrittenAnswers', () => {
    it('gives a count out of the written answers below 100 answers', () => {
      expect(ofWrittenAnswers(14, 45)).toBe('14 of 45 written answers')
    })

    it('adds the percentage after a comma from 100 answers, not in brackets of its own', () => {
      expect(ofWrittenAnswers(14, 120)).toBe('14 of 120 written answers, 12%')
      expect(ofWrittenAnswers(30, 100)).toBe('30 of 100 written answers, 30%')
      expect(ofWrittenAnswers(14, 120)).not.toContain('(')
    })

    it('has no "of" without a base', () => {
      expect(ofWrittenAnswers(14, 0)).toBe('14 members')
      expect(ofWrittenAnswers(1, undefined)).toBe('1 member')
    })

    it('separates thousands', () => {
      expect(ofWrittenAnswers(1200, 4800)).toBe(
        '1,200 of 4,800 written answers, 25%'
      )
    })

    it('says "answer" for a base of one', () => {
      expect(ofWrittenAnswers(1, 1)).toBe('1 of 1 written answer')
    })
  })

  describe('mentionedIn', () => {
    it('gives a count out of the written answers below 100 answers, with no percentage', () => {
      expect(mentionedIn(14, 45)).toBe(
        'Mentioned in 14 of the 45 written answers.'
      )
    })

    it('adds the percentage from 100 answers', () => {
      expect(mentionedIn(14, 120)).toBe(
        'Mentioned in 14 of the 120 written answers (12%).'
      )
      expect(mentionedIn(30, 100)).toBe(
        'Mentioned in 30 of the 100 written answers (30%).'
      )
    })

    it('has no "of" when the problem did not come from a question', () => {
      expect(mentionedIn(14, 0)).toBe('Mentioned by 14 members.')
      expect(mentionedIn(1, 0)).toBe('Mentioned by 1 member.')
      expect(mentionedIn(undefined, undefined)).toBe('Mentioned by 0 members.')
    })

    it('separates thousands', () => {
      expect(mentionedIn(1200, 4800)).toBe(
        'Mentioned in 1,200 of the 4,800 written answers (25%).'
      )
    })

    it('says "answer" for a base of one', () => {
      expect(mentionedIn(1, 1)).toBe('Mentioned in 1 of the 1 written answer.')
    })
  })

  describe('mentionCount', () => {
    it('is the count out of its base, with the percentage from 100 answers', () => {
      expect(mentionCount(14, 45)).toBe('14 of 45')
      expect(mentionCount(14, 120)).toBe('14 of 120 (12%)')
    })

    it('is a count of members, never a bare number, when there is no base', () => {
      expect(mentionCount(6, 0)).toBe('6 members')
      expect(mentionCount(1, 0)).toBe('1 member')
      expect(mentionCount(6, undefined)).toBe('6 members')
      expect(mentionCount(undefined, undefined)).toBe('0 members')
    })
  })

  describe('rankedLine', () => {
    it('says nothing while nobody has been asked about the problem', () => {
      expect(rankedLine({ times_shown: null, most: null, asked: 0 })).toBe('')
      expect(rankedLine({})).toBe('')
      expect(rankedLine(undefined)).toBe('')
      expect(rankedLine({ times_shown: 0, most: 0, asked: 20 })).toBe('')
    })

    it('gives the counts below 100 members asked, with no percentage', () => {
      expect(rankedLine({ times_shown: 40, most: 12, asked: 80 })).toBe(
        'Other members were shown it 40 times and 12 picked it as the biggest problem.'
      )
    })

    it('adds the percentage of the times it was shown from 100 members asked', () => {
      expect(rankedLine({ times_shown: 40, most: 12, asked: 100 })).toBe(
        'Other members were shown it 40 times and 12 picked it as the biggest problem (30%).'
      )
    })

    it('says "1 time" for one showing and keeps a count of none', () => {
      expect(rankedLine({ times_shown: 1, most: 0, asked: 3 })).toBe(
        'Other members were shown it 1 time and 0 picked it as the biggest problem.'
      )
    })
  })

  describe('kindOf', () => {
    const survey = (keys) => ({
      kind: 'survey',
      tallies: keys.map((key) => ({ key })),
    })

    it('calls a survey whose options are keyed problem: the problem ranking', () => {
      expect(kindOf(survey(['problem:1', 'problem:2']))).toBe('problems')
      expect(kindLabel(survey(['problem:1']))).toBe('Problem ranking')
    })

    it('calls any other survey the area ranking', () => {
      expect(kindOf(survey(['give', 'ask']))).toBe('areas')
      expect(kindOf({ kind: 'survey' })).toBe('areas')
      expect(kindLabel(survey(['give']))).toBe('Area ranking')
    })

    it('knows an awareness question and an open question', () => {
      expect(kindOf({ kind: 'awareness', tallies: [] })).toBe('awareness')
      expect(kindLabel({ kind: 'awareness' })).toBe('Awareness')
      expect(kindOf({ kind: 'open', tallies: [] })).toBe('open')
      expect(kindLabel({ kind: 'open' })).toBe('Written answers')
    })

    it('does not read an awareness question by its option keys', () => {
      expect(
        kindOf({ kind: 'awareness', tallies: [{ key: 'problem:1' }] })
      ).toBe('awareness')
    })

    it('has plain labels with no funnel key or the word flow in them', () => {
      expect(Object.values(KIND_LABELS)).toEqual([
        'Problem ranking',
        'Area ranking',
        'Awareness',
        'Written answers',
      ])
      for (const label of Object.values(KIND_LABELS)) {
        expect(label).not.toMatch(/\bflow\b/i)
      }
    })

    // The state of a question is Open or Closed. No name for a kind may be the word Open, or a row
    // reads "Open question ... Closed" and "Open question ... Open".
    it('names no kind with the word open, which a question already is or is not', () => {
      for (const label of Object.values(KIND_LABELS)) {
        expect(label).not.toMatch(/\bopen\b|\bclosed\b/i)
      }
    })
  })
})

describe('standingText', () => {
  const asked = (over) => ({
    prompt_id: 5,
    question: 'Did you have a problem the last time you used Freegle?',
    responses: 49,
    skipped: 26,
    written: 0,
    nothing: 23,
    nothing_label: 'I had no problems',
    dont_understand: 0,
    analysed: false,
    ...over,
  })

  const FALLBACK = 'old text'

  it('is the fallback for a server that sends no open_questions', () => {
    expect(standingText(undefined, FALLBACK)).toBe(FALLBACK)
    expect(standingText(null, FALLBACK)).toBe(FALLBACK)
  })

  it('says no question has been asked when there is none', () => {
    expect(standingText([], FALLBACK)).toBe('No question has been asked yet.')
    expect(standingText([], FALLBACK, 'Other.')).toBe('Other.')
  })

  it('says where a question with no written answers stands', () => {
    expect(standingText([asked()], FALLBACK)).toBe(
      'No written answers yet. 49 members answered "Did you have a problem the last time you used Freegle?": 23 said "I had no problems", 26 skipped.'
    )
  })

  it('says nobody has answered when no one has', () => {
    expect(
      standingText([asked({ responses: 0, skipped: 0, nothing: 0 })], FALLBACK)
    ).toBe(
      'Nobody has answered "Did you have a problem the last time you used Freegle?" yet.'
    )
  })

  it('says written answers are waiting, and which command sorts them', () => {
    expect(standingText([asked({ written: 12 })], FALLBACK)).toBe(
      '12 written answers to "Did you have a problem the last time you used Freegle?" are waiting to be analysed. Someone runs ux:prompt analyse 5 to sort them.'
    )
    expect(standingText([asked({ written: 1 })], FALLBACK)).toContain(
      '1 written answer to'
    )
    expect(standingText([asked({ written: 1 })], FALLBACK)).toContain(
      'is waiting to be analysed'
    )
  })

  it('says when analysed answers gave nothing', () => {
    expect(
      standingText([asked({ written: 3, analysed: true })], FALLBACK)
    ).toBe(
      '3 written answers to "Did you have a problem the last time you used Freegle?" were analysed and nothing came out of them.'
    )
  })

  it('counts members who did not understand', () => {
    expect(
      standingText(
        [asked({ nothing: 0, skipped: 0, dont_understand: 4, responses: 4 })],
        FALLBACK
      )
    ).toContain('4 members answered')
    expect(
      standingText(
        [asked({ nothing: 0, skipped: 0, dont_understand: 4, responses: 4 })],
        FALLBACK
      )
    ).toContain('4 did not understand it')
  })
})

describe('the balance of the area survey', () => {
  it('writes a balance with its sign, and a real minus sign', () => {
    expect(MINUS.charCodeAt(0)).toBe(0x2212)
    expect(signed(17)).toBe('+17')
    expect(signed(-5)).toBe(`${MINUS}5`)
    expect(signed(0)).toBe('0')
    expect(signed(1234)).toBe('+1,234')
    expect(signed(undefined)).toBe('0')
  })

  it('says in words what a balance means, so it never rests on a sign or a colour', () => {
    expect(balanceWord(18, 1)).toBe('works well')
    expect(balanceWord(0, 5)).toBe('needs improving')
    expect(balanceWord(4, 4)).toBe('even')
    expect(balanceWord(0, 0)).toBe('no picks')
  })

  const areas = [
    t('ask', 'Asking for something', 4, 5, 28),
    t('chat_promise', 'Arranging a collection in chat', 7, 5, 28),
    t('settings', 'Settings and email choices', 0, 5, 28),
    t('reply', 'Replying to a post', 6, 4, 28),
    t('chat_outcome', 'Marking a post taken or received', 7, 4, 28),
    t('login', 'Signing in or joining', 4, 4, 28),
    t('give', 'Offering something', 18, 1, 28),
  ]

  it('puts the lowest balance first, and breaks a tie by the area with more for improving', () => {
    expect(areaBalances(areas, 52).map((r) => r.key)).toEqual([
      'settings',
      'ask',
      'login',
      'chat_promise',
      'reply',
      'chat_outcome',
      'give',
    ])
  })

  it('is the same whatever order the server sends the areas in', () => {
    const forwards = areaBalances(areas, 52).map((r) => r.key)
    const backwards = areaBalances([...areas].reverse(), 52).map((r) => r.key)
    expect(backwards).toEqual(forwards)
  })

  it('works each balance out of the times shown, and says what it is out of', () => {
    const [first] = areaBalances(areas, 52)
    expect(first).toMatchObject({
      key: 'settings',
      best: 0,
      improve: 5,
      net: -5,
      base: 28,
      shown: 28,
      word: 'needs improving',
      tone: 'bad',
    })
    expect(areaBalances(areas, 52).at(-1)).toMatchObject({
      key: 'give',
      net: 17,
      word: 'works well',
      tone: 'good',
    })
  })

  it('ranks by the balance as a share of the times shown, so an area shown less is not unfairly low', () => {
    // 6 of 12 less 2 is +4 of 12 (a third), against +5 of 40 (an eighth): the first is better although the
    // second has the bigger count.
    const rows = areaBalances(
      [t('a', 'Shown often', 8, 3, 40), t('b', 'Shown seldom', 6, 2, 12)],
      30
    )
    expect(rows.map((r) => r.key)).toEqual(['a', 'b'])
  })

  it('uses the members who answered as the base when the server sends no times shown', () => {
    const [row] = areaBalances([t('a', 'Area', 6, 2, 0)], 40)
    expect(row.base).toBe(40)
    expect(row.shown).toBe(0)
  })

  it('draws every bar on one scale: the longest side is the whole half, and a pick is never invisible', () => {
    const rows = areaBalances(
      [t('a', 'Long', 20, 1, 40), t('b', 'Short', 1, 10, 40)],
      40
    )
    const byKey = Object.fromEntries(rows.map((r) => [r.key, r]))
    expect(byKey.a.bestWidth).toBe(100)
    expect(byKey.b.improveWidth).toBe(50)
    // One pick of 40 is a twentieth of the longest side, but is still drawn.
    expect(byKey.b.bestWidth).toBe(5)
    const tiny = areaBalances(
      [t('a', 'Long', 200, 0, 400), t('b', 'One', 1, 0, 400)],
      400
    )
    expect(tiny.find((r) => r.key === 'b').bestWidth).toBe(3)
    // Nothing picked is nothing drawn.
    expect(byKey.a.improveWidth).toBeGreaterThan(0)
    expect(areaBalances([t('a', 'None', 0, 0, 10)], 10)[0]).toMatchObject({
      bestWidth: 0,
      improveWidth: 0,
      word: 'no picks',
      tone: 'none',
    })
  })

  it('copes with no tallies and with bad figures', () => {
    expect(areaBalances(undefined, 10)).toEqual([])
    expect(areaBalances([], 0)).toEqual([])
    const [row] = areaBalances([{ key: 'a', label: 'A' }], 0)
    expect(row).toMatchObject({ best: 0, improve: 0, net: 0, base: 0 })
  })

  describe('the summary', () => {
    it('names the best and the lowest, each with what it is out of, and gives the base for both picks', () => {
      expect(balanceSummary(areaBalances(areas, 52))).toBe(
        'Offering something has the best balance (+17 of 28). Settings and email choices has the lowest (' +
          MINUS +
          '5 of 28). 46 picked an area that works best, and 28 of them also picked one that most needs improving.'
      )
    })

    it('never adds the balances into one total', () => {
      const line = balanceSummary(areaBalances(areas, 52))
      expect(line).not.toMatch(/overall|total/i)
      expect(line).not.toContain('+18')
    })

    it('names every area that shares the best or the lowest', () => {
      const line = balanceSummary(
        areaBalances(
          [
            t('a', 'Alpha', 10, 0, 20),
            t('b', 'Beta', 10, 0, 20),
            t('c', 'Gamma', 2, 6, 20),
            t('d', 'Delta', 2, 6, 20),
            t('e', 'Epsilon', 2, 6, 20),
            t('f', 'Zeta', 2, 6, 20),
          ],
          40
        )
      )
      expect(line).toContain(
        'Alpha and Beta have the best balance (+10 of 20).'
      )
      expect(line).toContain(
        'Delta, Epsilon, Gamma and 1 more have the lowest (' +
          MINUS +
          '4 of 20).'
      )
    })

    it('names three areas without "more", and says the areas are level when they are', () => {
      const three = balanceSummary(
        areaBalances(
          [
            t('a', 'Alpha', 1, 3, 10),
            t('b', 'Beta', 1, 3, 10),
            t('c', 'Gamma', 1, 3, 10),
            t('d', 'Delta', 5, 0, 10),
          ],
          20
        )
      )
      expect(three).toContain('Alpha, Beta and Gamma have the lowest')
      const level = balanceSummary(
        areaBalances([t('a', 'Alpha', 3, 3, 10), t('b', 'Beta', 3, 3, 10)], 20)
      )
      expect(level.startsWith('The areas are level. ')).toBe(true)
    })

    it('counts an area nobody picked as the lowest when every area that was picked is good', () => {
      const line = balanceSummary(
        areaBalances([t('a', 'Alpha', 6, 1, 10), t('b', 'Beta', 0, 0, 10)], 20)
      )
      expect(line).toContain('Beta has the lowest (0 of 10)')
    })

    it('is empty when nobody has picked anything, or there is nothing to compare', () => {
      expect(balanceSummary([])).toBe('')
      expect(balanceSummary(undefined)).toBe('')
      expect(balanceSummary(areaBalances([t('a', 'A', 0, 0, 10)], 10))).toBe('')
      // Picks with no base at all (no times shown and nobody answered) have nothing to be out of.
      expect(balanceSummary(areaBalances([t('a', 'A', 3, 1, 0)], 0))).toBe('')
    })
  })
})
