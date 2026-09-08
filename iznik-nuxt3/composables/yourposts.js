// Your posts as one chat: the events worth telling the giver about, derived from the
// post records, the replies' chats, the repliers, the promises and the trysts. Nothing
// is invented; every line comes from data that exists. Pure functions, so the whole
// thing is testable without a browser.

const DAY = 24 * 60 * 60 * 1000

export function cleanTitle(subject) {
  return String(subject || '')
    .replace(/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i, '')
    .replace(/\s*\([^)]*\)\s*$/, '')
}

// How many the reply asked for, from its text ("could I have two", "2 please").
export function wantedCountFrom(text) {
  const t = String(text || '').toLowerCase()
  const words = { one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, a: 1, couple: 2, pair: 2 }
  const m = t.match(/\b(?:have|take|get|want|like|need|after)\s+(?:the\s+)?(\d{1,2}|one|two|three|four|five|six|a couple|a pair|couple|pair)\b/)
  if (m) {
    const w = m[1].replace(/^a /, '')
    return /^\d+$/.test(w) ? Math.max(1, Math.min(99, parseInt(w, 10))) : words[w] || 1
  }
  const n = t.match(/\b(\d{1,2})\s*(?:of them|please|pls|would be great)\b/)
  if (n) return Math.max(1, Math.min(99, parseInt(n[1], 10)))
  return null
}

// The deterministic ordering score and the reasons it shows. Owned here; not a model.
export function scoreReplier(reply, post, ctx = {}) {
  const reasons = []
  let score = 0
  const posted = post?.arrival ? new Date(post.arrival).getTime() : post?.date ? new Date(post.date).getTime() : null
  const replied = reply?.date ? new Date(reply.date).getTime() : null
  if (posted && replied) {
    const hours = (replied - posted) / 3600000
    if (hours <= 1) {
      score += 3
      reasons.push({ text: 'Replied first', weight: 3 })
    } else if (hours <= 24) {
      score += 2
      reasons.push({ text: 'Replied quickly', weight: 2 })
    } else {
      score += 1
    }
  }
  const up = reply?.ratings?.Up || 0
  const down = reply?.ratings?.Down || 0
  const net = Math.min(3, up - down)
  if (net > 0) {
    score += net
    reasons.push({ text: `${up} thumbs up`, weight: net })
  }
  if (reply?.miles !== null && reply?.miles !== undefined) {
    if (reply.miles <= 3) {
      score += 2
      reasons.push({ text: 'Nearest', weight: 2 })
    } else if (reply.miles <= 10) {
      score += 1
    }
  }
  const text = String(reply?.snippet || '')
  if (text.length > 40 || /\b(collect|pick up|tonight|tomorrow|weekend|monday|tuesday|wednesday|thursday|friday|saturday|sunday|morning|afternoon|evening)\b/i.test(text)) {
    score += 1
    reasons.push({ text: 'Said when they can collect', weight: 1 })
  }
  if (reply?.myRatingDown) {
    score -= 5
    reasons.push({ text: 'You gave them a thumbs down before', weight: -5 })
  }
  if (reply?.tn) reasons.push({ text: 'via Trash Nothing', weight: 0 })
  reasons.sort((a, b) => Math.abs(b.weight) - Math.abs(a.weight))
  return { score, reasons: reasons.slice(0, 2).map((r) => r.text) }
}

// Order repliers by score; ties keep reply order.
export function orderRepliers(replies, post) {
  return replies
    .map((r, i) => ({ ...r, ...scoreReplier(r, post), order: i }))
    .sort((a, b) => b.score - a.score || a.order - b.order)
}

// Prefill a split: what each replier asked for, else 1 each in order, never over the pool.
export function allocate(available, repliers) {
  let left = Math.max(0, available)
  return repliers.map((r) => {
    const want = r.wanted || 1
    const give = Math.min(want, left)
    left -= give
    return { userid: r.userid, count: give }
  })
}

// Left after promises and outcomes.
export function remaining(post) {
  const total = post?.availablenow ?? 1
  const promised = (post?.promises || []).reduce((n, p) => n + (p.count || 1), 0)
  return Math.max(0, total - promised)
}

// The events for one post, oldest first. Each has a kind, a text the shell renders
// (plain and factual; Freegle's composed line comes from the assistant), the post id and
// the chips that make sense.
export function eventsFor(post, { replies = [], trysts = [], now = Date.now() } = {}) {
  const events = []
  const id = post.id
  const title = cleanTitle(post.subject)
  const isOffer = post.type !== 'Wanted'
  const posted = post.arrival || post.date
  const pending = !!post.groups?.some?.((g) => g.collection === 'Pending')
  const reposted = post.repostedat || null

  const baseTs = posted ? new Date(posted).getTime() : now
  events.push({ kind: 'posted', ts: baseTs, id, text: pending ? `Posted: ${title}` : `Posted: ${title}`, chips: [] })

  const activeReplies = replies.filter((r) => !reposted || new Date(r.date).getTime() >= new Date(reposted).getTime())
  if (reposted) {
    events.push({ kind: 'reposted', ts: new Date(reposted).getTime(), id, text: 'Reposted', chips: [] })
  }
  for (const r of activeReplies) {
    events.push({
      kind: 'reply',
      ts: new Date(r.date).getTime(),
      id,
      userid: r.userid,
      name: r.displayname,
      chatid: r.chatid,
      snippet: r.snippet || '',
      miles: r.miles ?? null,
      text: `${r.displayname} ${isOffer ? 'is interested in' : 'has'} your ${title}`,
      chips: [
        { value: `promise:${id}:${r.userid}`, label: isOffer ? `Promise to ${r.displayname}` : `Choose ${r.displayname}` },
        { value: `chat:${r.chatid}`, label: 'Reply' },
      ],
    })
  }
  if (post.heldreplies > 0) {
    events.push({ kind: 'held', ts: now, id, text: `${post.heldreplies} ${post.heldreplies === 1 ? 'reply is' : 'replies are'} waiting for a volunteer to check`, chips: [] })
  }
  if (activeReplies.length >= 2 && !post.outcomes?.length) {
    events.push({ kind: 'chooser', ts: Math.max(...activeReplies.map((r) => new Date(r.date).getTime())) + 1, id, count: activeReplies.length, text: `${activeReplies.length} people interested`, chips: [{ value: `choose:${id}`, label: 'Who should have it?' }] })
  }
  for (const p of post.promises || []) {
    const who = activeReplies.find((r) => r.userid === p.userid)
    const tryst = trysts.find((t) => t.msgid === id && (t.user1 === p.userid || t.user2 === p.userid)) || trysts.find((t) => !t.msgid && (t.user1 === p.userid || t.user2 === p.userid))
    const when = tryst?.arrangedfor ? new Date(tryst.arrangedfor) : null
    const name = who?.displayname || 'someone'
    const countText = p.count > 1 ? ` (${p.count})` : ''
    events.push({
      kind: 'promised',
      ts: new Date(p.promisedat).getTime(),
      id,
      userid: p.userid,
      name,
      when: when ? when.getTime() : null,
      text: when ? `Promised to ${name}${countText}, ${when.toLocaleString([], { weekday: 'short', hour: '2-digit', minute: '2-digit' })}` : `Promised to ${name}${countText}`,
      chips: [
        { value: `tryst:${id}:${p.userid}`, label: when ? 'Change time' : 'Set a time' },
        { value: `taken:${id}:${p.userid}`, label: isOffer ? 'Taken' : 'Received' },
        { value: `unpromise:${id}:${p.userid}`, label: 'Unpromise' },
      ],
    })
    if (when && when.getTime() < now && !post.outcomes?.length) {
      events.push({
        kind: 'collected?',
        ts: when.getTime() + 60000,
        id,
        userid: p.userid,
        name,
        text: `Did ${name} collect the ${title}?`,
        chips: [
          { value: `taken:${id}:${p.userid}`, label: 'Yes' },
          { value: `notyet:${id}:${p.userid}`, label: 'Not yet' },
          { value: `noshow:${id}:${p.userid}`, label: `${name} didn't come` },
        ],
      })
    }
  }
  const outcomes = post.outcomes || []
  for (const o of outcomes) {
    events.push({ kind: 'outcome', ts: new Date(o.timestamp || now).getTime(), id, text: o.outcome === 'Withdrawn' ? 'Withdrawn' : isOffer ? 'All gone' : 'Received', chips: [] })
  }
  if (!activeReplies.length && !post.heldreplies && !outcomes.length && baseTs && now - baseTs > 3 * DAY) {
    events.push({ kind: 'quiet', ts: baseTs + 3 * DAY, id, text: `No one has asked about the ${title} yet`, chips: [{ value: `repost:${id}`, label: 'Repost' }, { value: `withdraw:${id}`, label: 'Withdraw' }, { value: `edit:${id}`, label: 'Edit' }] })
  }
  return events.sort((a, b) => a.ts - b.ts)
}

// All posts' events merged, newest last, with the post attached.
export function timeline(posts, ctxFor, now = Date.now()) {
  const all = []
  for (const post of posts) {
    for (const ev of eventsFor(post, { ...(ctxFor(post) || {}), now })) all.push({ ...ev, post })
  }
  return all.sort((a, b) => a.ts - b.ts)
}

// Unread events: newer than the member's watermark for that post.
export function unreadCount(posts, ctxFor, seen = {}, now = Date.now()) {
  let n = 0
  for (const ev of timeline(posts, ctxFor, now)) {
    if (ev.kind === 'posted') continue
    const mark = seen[ev.id] || 0
    if (ev.ts > mark) n++
  }
  return n
}
