import { describe, it, expect } from 'vitest'
import {
  composeQuestionReply,
  hasCaveat,
  QUESTION_REPLY_CAVEAT,
  QUESTION_REPLY_UNSURE_PREFIX,
} from '../question-reply.js'

// The caveat is appended in code rather than requested in a prompt precisely so
// it cannot be omitted on the answers that most need it — the fluent, confident
// ones that answer a question nobody asked.

describe('composeQuestionReply — the caveat is not optional', () => {
  it('appends it to a plain answer', () => {
    const reply = composeQuestionReply({ answer: 'Posts expire after 28 days.' })
    expect(reply).toContain('Posts expire after 28 days.')
    expect(reply).toContain(QUESTION_REPLY_CAVEAT)
  })

  it('puts it last, after the answer', () => {
    const reply = composeQuestionReply({ answer: 'Posts expire after 28 days.' })!
    expect(reply.indexOf('28 days')).toBeLessThan(reply.indexOf(QUESTION_REPLY_CAVEAT))
  })

  it('adds it however long or short the answer is', () => {
    for (const answer of ['Yes.', 'A '.repeat(400) + 'end.']) {
      expect(composeQuestionReply({ answer })).toContain(QUESTION_REPLY_CAVEAT)
    }
  })
})

describe('composeQuestionReply — no duplicate caveats', () => {
  it('does not add a second one when the answer already has it verbatim', () => {
    const answer = `Posts expire after 28 days.\n\n${QUESTION_REPLY_CAVEAT}`
    const reply = composeQuestionReply({ answer })!
    const occurrences = reply.split(QUESTION_REPLY_CAVEAT).length - 1
    expect(occurrences).toBe(1)
  })

  it('recognises a delegate writing its own version', () => {
    const reply = composeQuestionReply({
      answer: 'Posts expire after 28 days. I may have misread your question, though.',
    })!
    expect(reply).not.toContain(QUESTION_REPLY_CAVEAT)
  })
})

describe('composeQuestionReply — flagging an uncertain answer', () => {
  it('leads with the unsure line when the delegate was not confident', () => {
    const reply = composeQuestionReply({ answer: 'Probably 28 days.', unsure: true })!
    expect(reply.startsWith(QUESTION_REPLY_UNSURE_PREFIX)).toBe(true)
    expect(reply).toContain(QUESTION_REPLY_CAVEAT)
  })

  it('omits it when the delegate was confident', () => {
    const reply = composeQuestionReply({ answer: 'Posts expire after 28 days.' })!
    expect(reply).not.toContain(QUESTION_REPLY_UNSURE_PREFIX)
  })
})

describe('composeQuestionReply — nothing to say means no draft', () => {
  // A draft made only of caveats costs a reviewer time and tells the asker
  // nothing, so it must not reach the queue at all.
  it('returns null for an empty answer', () => {
    expect(composeQuestionReply({ answer: '' })).toBeNull()
    expect(composeQuestionReply({ answer: '   \n  ' })).toBeNull()
  })

  it('returns null even when flagged unsure', () => {
    expect(composeQuestionReply({ answer: '', unsure: true })).toBeNull()
  })
})

describe('hasCaveat', () => {
  it('spots the phrasings a delegate reaches for unprompted', () => {
    expect(hasCaveat(QUESTION_REPLY_CAVEAT)).toBe(true)
    expect(hasCaveat('I might have MISREAD YOUR QUESTION')).toBe(true)
    expect(hasCaveat('Sorry if I misunderstood your question')).toBe(true)
    expect(hasCaveat('If this answers a different question to the one you asked, tell me')).toBe(true)
  })

  it('does not fire on an ordinary answer', () => {
    expect(hasCaveat('Posts expire after 28 days.')).toBe(false)
    expect(hasCaveat('')).toBe(false)
  })
})
