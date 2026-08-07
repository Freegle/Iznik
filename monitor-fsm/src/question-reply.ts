/**
 * Composing replies to Discourse posts that are questions rather than bug
 * reports.
 *
 * A question is answered from the same evidence a fix would be: the code, the
 * docs, the live data. That makes a confident-sounding answer easy to produce
 * and occasionally wrong in a way the asker cannot see — the reply may be
 * fluent, specific, and about a question they did not ask, because the triage
 * step read the post differently than they meant it.
 *
 * So every question reply carries a closing line that says so plainly and
 * invites correction. It is appended here, in code, rather than asked for in a
 * prompt: an LLM told to "include a caveat" will sometimes decide this
 * particular answer is certain enough to skip it, and that is exactly the
 * answer where the caveat matters most.
 *
 * Nothing here posts anything. Replies are queued as drafts for a human to
 * approve — real volunteers read these in real time.
 */

/** Appended to every question reply. Kept short so it reads as honesty, not hedging. */
export const QUESTION_REPLY_CAVEAT =
  "I may have got the wrong end of the stick here — if I've answered a different question to the one you asked, say so and I'll have another go."

/** Sentence used when the answer itself is uncertain, not just the reading of the question. */
export const QUESTION_REPLY_UNSURE_PREFIX =
  "I'm not certain about this one, so treat it as a starting point rather than a definitive answer."

export interface QuestionReplyInput {
  /** The answer body, as researched by the delegate. */
  answer: string
  /** True when the delegate reported low confidence in the answer itself. */
  unsure?: boolean
}

/**
 * Build the reply body for a question.
 *
 * Returns null when there is no substantive answer — a draft consisting only of
 * caveats wastes a reviewer's time and tells the asker nothing.
 */
export function composeQuestionReply(input: QuestionReplyInput): string | null {
  const answer = (input.answer ?? '').trim()
  if (!answer) return null

  const parts: string[] = []
  if (input.unsure) parts.push(QUESTION_REPLY_UNSURE_PREFIX)
  parts.push(answer)

  // Never double up: a delegate that already wrote the caveat (or something
  // close enough to read as a repeat) should not get it twice.
  if (!hasCaveat(answer)) parts.push(QUESTION_REPLY_CAVEAT)

  return parts.join('\n\n')
}

/**
 * True when the text already admits the question may have been misread.
 *
 * Matches the exact caveat and the handful of phrasings a delegate reaches for
 * unprompted, so we neither duplicate it nor bolt a second one on.
 */
export function hasCaveat(text: string): boolean {
  const t = (text ?? '').toLowerCase()
  return (
    t.includes('wrong end of the stick') ||
    t.includes('misunderstood your question') ||
    t.includes('misread your question') ||
    (t.includes('different question') && t.includes('asked'))
  )
}
