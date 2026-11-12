// The words the service says in public.
//
// Almost all of them are fixed. A reply is a template with a list chosen by rule, so
// the same report always gets the same reply and a forum post cannot talk the service
// into saying something else. The model's own words appear in three places only: the
// one-line summary read back to the reporter, one optional extra question, and the
// answer to a how-to question. Each of those goes through safeModelText first.

import { ASK_TEXT } from './slots.mjs'

export const FOOTER = '<small>Automated helper. I pass reports to the developers. I can\'t see member data or fix things myself.</small>'

/**
 * Model-written text that is fit to post, or null.
 *
 * It may hold no link, no markup, no address and no long number. Those are the ways
 * text copied out of a hostile post would do harm in the service's voice.
 */
export function safeModelText(s, maxLen = 600) {
  if (typeof s !== 'string') return null
  const t = s.replace(/\s+/g, ' ').trim()
  if (!t || t.length > maxLen) return null
  if (/https?:|www\.|[\w-]+\.(?:com|org|net|uk|io|co)\b/i.test(t)) return null
  if (/[[\]<>`]|!\(|\]\(/.test(t)) return null
  if (/@|\b\d{5,}\b|\b0\d{3,4}[ -]?\d{3}[ -]?\d{3,4}\b/.test(t)) return null
  return t
}

export function askBody(items, extraQuestion, round) {
  const lines = items.map((k) => `- ${ASK_TEXT[k]}`)
  const extra = safeModelText(extraQuestion, 200)
  if (extra && lines.length < 3) lines.push(`- ${extra.replace(/\?*$/, '?')}`)
  const lead = round > 1
    ? 'Thanks, that helps. One more thing would let the developers look into it:'
    : 'Thanks for reporting this. So the developers can look into it, could you tell me:'
  return `${lead}\n\n${lines.join('\n')}\n\n${FOOTER}`
}

export function ackBody(kind, summary, { incomplete = false } = {}) {
  const s = safeModelText(summary, 300)
  const what = kind === 'feature' ? 'a suggestion' : 'a problem report'
  const understood = s ? `I've written this up as ${what} for the developers:\n\n> ${s}\n\nIf that isn't right, reply here and I'll correct it.` : `I've written this up as ${what} for the developers.`
  const lead = incomplete ? 'Thanks. I\'ve passed on what we have so far.' : 'Thanks, that\'s clear.'
  return `${lead} ${understood}\n\n${FOOTER}`
}

export function duplicateBody(kind, url) {
  const what = kind === 'question' ? 'the same question as' : kind === 'feature' ? 'the same suggestion as' : 'the same problem as'
  const then = kind === 'question' ? 'The answer there should help.' : 'I\'ve added your post to that report, so the developers can see it affects you too.'
  return `Thanks. This looks like ${what} [this earlier post](${url}). ${then}\n\n${FOOTER}`
}

export function answerBody(answer, sources) {
  const a = safeModelText(answer, 700)
  if (!a || sources.length === 0) return null
  const links = sources.map((s) => `[${s.title.replace(/[[\]]/g, '')}](${s.url})`).join(', ')
  return `${a}\n\nFrom: ${links}\n\n${FOOTER}`
}
