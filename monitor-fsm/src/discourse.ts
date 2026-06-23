// Shared Discourse configuration.
// Override DISCOURSE_URL in .env to change the target instance.

// Use `||` (not `?.replace() ??`) so an EMPTY DISCOURSE_URL also falls back:
// '' is not nullish, so `?? fallback` would leave DISCOURSE_BASE = '' and every
// request would become a relative URL (e.g. "/latest.json" → urllib ValueError:
// "unknown url type"), which silently broke discover_active_topics.
export const DISCOURSE_BASE =
  process.env.DISCOURSE_URL?.replace(/\/$/, '') ||
  'https://discourse.ilovefreegle.org'

/**
 * Build the `raw` Markdown for a Discourse reply, prepending a [quote] block of
 * the reporting post so the reply visibly quotes what it answers.
 *
 * This is the SINGLE source of truth for reply formatting. Both posting paths
 * use it so they can't diverge again:
 *   - human-approved drafts (dashboard /api/drafts/:id/send), and
 *   - auto-posted verified-live "fix applied, please retest" replies
 *     (queue_deployed_reply_drafts), which previously posted the bare body with
 *     NO quote block — the bug this fixes.
 *
 * Rules:
 *   - If `body` already opens with a [quote] block (some drafts bake the full
 *     quote into the body), it's returned unchanged — never double-wrap.
 *   - With no quote text, the body is returned as-is (nothing to quote).
 *   - A real `username` produces an attributed, linked quote
 *     ([quote="name, post:N, topic:M"]); a missing/placeholder username falls
 *     back to a plain [quote] (the reply is still threaded via
 *     reply_to_post_number, so the post link isn't lost).
 */
export function formatReplyRaw(d: {
  username?: string | null
  post: number
  topic: number
  quote?: string | null
  body: string
}): string {
  const body = d.body
  if (/^\s*\[quote[=\]]/i.test(body)) return body

  const quote = (d.quote ?? '').trim()
  if (!quote) return body

  const username = (d.username ?? '').trim()
  const attribution =
    username && username.toLowerCase() !== 'there'
      ? `[quote="${username}, post:${d.post}, topic:${d.topic}"]`
      : '[quote]'

  return `${attribution}\n${quote}\n[/quote]\n\n${body}`
}
