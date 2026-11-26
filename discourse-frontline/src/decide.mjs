// What to do about a post, given the form the model filled in.
//
// A pure function: the same inputs always give the same decision, and nothing here
// reads the forum, the database or the clock. That is what lets test/decide.test.mjs
// pin the behaviour down one case at a time.
//
// The service does first-line support. Its only aims are a coherent report and a
// reporter who knows they were heard. It never decides what caused a problem.

import { missingForBug } from './slots.mjs'
import { soundsBroken } from './prefilter.mjs'
import { ackBody, answerBody, askBody, duplicateBody } from './replies.mjs'

const MAX_ITEMS = 3

/**
 * @param {object} a
 * @param {object} a.form        checked model form (see checkForm)
 * @param {object} a.post        the post being read
 * @param {object|null} a.anchor    the existing case this post adds to (see pickAnchor)
 * @param {object|null} a.sameAs    the case the model said this matches
 * @param {object} a.facts       facts over every post by the reporter in the case
 * @param {string} a.caseText    those posts' text, joined
 * @param {object[]} a.sources   kb rows for form.answerSources
 * @param {object} a.cfg
 * @returns {{ op: string, [k: string]: any }}
 */
export function decide({ form: read, post, anchor, sameAs, facts, caseText, sources, cfg, postUrl }) {
  if (anchor) return followup(anchor.row, anchor.type, { form: read, post, facts, caseText, cfg })
  // A follow-up that pickAnchor did not take: somebody else, in another topic,
  // reporting what an open case already covers. That is a report of its own.
  // Unless all it says is that the thing works again, which needs no reply.
  if (read.kind === 'followup' && read.followupType === 'resolved') return { op: 'skip', why: 'resolved-elsewhere' }
  const form = read.kind === 'followup' && sameAs ? { ...read, kind: sameAs.kind } : read

  if (form.kind === 'other') return { op: 'skip', why: form.confidence === 'low' ? 'other-unsure' : 'other' }

  // A new post about something already on the books.
  if (sameAs && (sameAs.kind === form.kind) && sameAs.state !== 'needs-human') {
    return {
      op: 'duplicate',
      of: sameAs.id,
      countsAsAffected: post.author !== sameAs.reporter,
      newCase: caseFields(form, 'duplicate', facts, []),
      reply: { kind: 'duplicate', body: duplicateBody(form.kind, postUrl(sameAs.topic_id, sameAs.post_number)) },
    }
  }

  if (form.kind === 'question') {
    const body = form.confidence !== 'low' ? answerBody(form.answer, sources) : null
    if (body) return { op: 'open', newCase: caseFields(form, 'answered', facts, []), reply: { kind: 'answer', body } }
    return { op: 'open', newCase: caseFields(form, 'needs-human', facts, []), reply: null }
  }

  // Not sure it is a report at all: keep it where a person will see it, and say
  // nothing. A wrong "thanks for reporting this" in a discussion is worse than silence.
  // The exception is a thin post that plainly says something is broken ("the app is
  // not working again"): the model is unsure because there is so little, and asking
  // is exactly what that needs.
  if (form.confidence === 'low' && !(form.kind === 'bug' && soundsBroken(caseText))) return { op: 'open', newCase: caseFields(form, 'needs-human', facts, []), reply: null }

  if (form.kind === 'feature') {
    return { op: 'open', newCase: caseFields(form, 'ready', facts, []), reply: { kind: 'ack', body: ackBody('feature', form.summary) } }
  }

  // A bug.
  const { needed, wanted } = missingForBug(caseText, facts, form.has)
  if (needed.length === 0) {
    return { op: 'open', newCase: caseFields(form, 'ready', facts, wanted), reply: { kind: 'ack', body: ackBody('bug', form.summary) } }
  }
  const items = [...needed, ...wanted].slice(0, MAX_ITEMS)
  return {
    op: 'open',
    newCase: { ...caseFields(form, 'waiting-reporter', facts, [...needed, ...wanted]), asks: 1 },
    reply: { kind: 'ask', round: 1, body: askBody(items, form.extraQuestion, 1) },
  }
}

/**
 * The existing case a post adds to, if any, and in what way.
 *
 * Worked out before the decision because the facts a decision rests on are the
 * reporter's posts in that case, and those have to be gathered first.
 */
export function pickAnchor({ form, post, openCase, sameAs, threadedBy = 'reply' }) {
  // A reporter replying while we wait on them is answering us, whatever the post
  // looks like on its own. "Chrome on my laptop" is not a new report.
  if (openCase && openCase.state === 'waiting-reporter' && post.author === openCase.reporter && form.kind !== 'question' && form.kind !== 'feature') {
    // Unless nothing ties the post to the case but its author, and the model is
    // sure it is a full report of something else. Then it is a second problem.
    const elsewhere = threadedBy === 'author' && form.kind === 'bug' && form.confidence === 'high' && form.has.actual && sameAs?.id !== openCase.id
    if (!elsewhere) return { row: openCase, type: 'detail' }
  }
  // In the same topic, or from the reporter, a follow-up adds to the case quietly.
  // From somebody else in another topic it is a new report of the same thing, and
  // that person should be told where it has gone (see decide).
  if (form.kind === 'followup' && sameAs && (post.topicId === sameAs.topic_id || post.author === sameAs.reporter)) {
    return { row: sameAs, type: form.followupType }
  }
  // The same person on the same subject again is adding to their report, not
  // opening a second one to be told it matches their first.
  if (sameAs && sameAs.kind === form.kind && post.author === sameAs.reporter) return { row: sameAs, type: 'detail' }
  return null
}

function caseFields(form, state, facts, unknowns) {
  return { kind: form.kind, state, summary: form.summary, area: form.area, urgency: form.urgency, facts, unknowns, asks: 0 }
}

function followup(target, type, { form, post, facts, caseText, cfg }) {
  const base = { op: 'followup', caseId: target.id, type, reply: null, patch: {} }
  if (type === 'me-too') return { ...base, patch: { affectedDelta: post.author === target.reporter ? 0 : 1 } }
  if (type === 'still-broken') return { ...base, patch: { stillBroken: true } }
  if (type === 'resolved') return base

  // More detail. Only the reporter's own detail can complete their report.
  if (target.kind !== 'bug' || target.state !== 'waiting-reporter' || post.author !== target.reporter) {
    return { ...base, patch: { facts } }
  }
  const { needed, wanted } = missingForBug(caseText, facts, form.has)
  const summary = form.summary || target.summary
  if (needed.length === 0) {
    return { ...base, patch: { facts, state: 'ready', unknowns: wanted, summary }, reply: { kind: 'ack', body: ackBody('bug', summary) } }
  }
  if (target.asks < cfg.maxAsks) {
    const round = target.asks + 1
    return {
      ...base,
      patch: { facts, unknowns: [...needed, ...wanted], asks: round, summary },
      reply: { kind: 'ask', round, body: askBody(needed.slice(0, MAX_ITEMS), null, round) },
    }
  }
  // Asked as often as we are allowed. Hand over what there is and say what is not known.
  return {
    ...base,
    patch: { facts, state: 'ready', unknowns: [...needed, ...wanted], summary },
    reply: { kind: 'ack', body: ackBody('bug', summary, { incomplete: true }) },
  }
}
