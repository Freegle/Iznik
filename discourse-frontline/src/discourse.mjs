// The only place that talks to the forum.
//
// Every read fails loudly. A failed fetch that came back as "no new posts" would look
// exactly like a quiet day, and the service would sit there doing nothing while
// reports went unread. So an error here stops the lap and the cursor does not move.

import { cookedToText, hasImage, quotedPosts } from './text.mjs'

export class DiscourseError extends Error {
  constructor(message, status) {
    super(message)
    this.status = status
  }
}

/** A post in the shape the rest of the service uses. */
export function normalisePost(p) {
  return {
    postId: p.id,
    topicId: p.topic_id,
    postNumber: p.post_number,
    replyTo: p.reply_to_post_number ?? null,
    author: p.username,
    createdAt: p.created_at,
    version: p.version ?? 1,
    postType: p.post_type ?? 1,
    topicTitle: p.topic_title ?? p.topic?.title ?? null,
    categoryId: p.category_id ?? null,
    archetype: p.archetype ?? null,
    text: cookedToText(p.cooked ?? ''),
    hasImage: hasImage(p.cooked ?? ''),
    quotes: quotedPosts(p.cooked ?? ''),
  }
}

export function createDiscourse(cfg, { fetchFn = fetch, sleep = (ms) => new Promise((r) => setTimeout(r, ms)) } = {}) {
  function headers(json) {
    const h = { Accept: 'application/json' }
    if (cfg.discourseKey) h[cfg.discourseKeyHeader] = cfg.discourseKey
    if (cfg.discourseUser) h['Api-Username'] = cfg.discourseUser
    if (json) h['Content-Type'] = 'application/json'
    return h
  }

  async function request(path, init = {}) {
    let wait = 2000
    for (let attempt = 0; ; attempt++) {
      let resp
      try {
        // A connection that hangs must not hang the lap with it.
        resp = await fetchFn(`${cfg.discourseBase}${path}`, { ...init, headers: headers(Boolean(init.body)), signal: AbortSignal.timeout(30000) })
      } catch (e) {
        throw new DiscourseError(`${path}: ${e.message}`, 0)
      }
      if (resp.status === 429 && attempt < 4) {
        const asked = Number(resp.headers.get('Retry-After'))
        await sleep(Math.min(Number.isFinite(asked) && asked > 0 ? asked * 1000 : wait, 60000))
        wait *= 2
        continue
      }
      if (!resp.ok) throw new DiscourseError(`${path}: HTTP ${resp.status}`, resp.status)
      return resp.json()
    }
  }

  return {
    /**
     * Posts newer than `afterId`, oldest first. The forum lists newest first, a page
     * at a time, so this walks back until it meets the cursor.
     */
    async postsAfter(afterId, maxPages = 100) {
      const out = []
      let before = null
      for (let page = 0; page < maxPages; page++) {
        const data = await request(`/posts.json${before ? `?before=${before}` : ''}`)
        const posts = data.latest_posts ?? []
        if (posts.length === 0) break
        for (const p of posts) if (p.id > afterId) out.push(normalisePost(p))
        const lowest = Math.min(...posts.map((p) => p.id))
        if (lowest <= afterId + 1) break
        before = lowest
        if (page === maxPages - 1) throw new DiscourseError(`more than ${maxPages} pages of unread posts: raise the page limit or move the cursor with backfill`, 0)
        await sleep(500)
      }
      return out.sort((a, b) => a.postId - b.postId)
    },

    async latestPostId() {
      const data = await request('/posts.json')
      return Math.max(0, ...(data.latest_posts ?? []).map((p) => p.id))
    },

    async post(postId) {
      return normalisePost(await request(`/posts/${postId}.json`))
    },

    async reply(topicId, raw, replyToPostNumber) {
      const body = { topic_id: topicId, raw }
      if (replyToPostNumber > 1) body.reply_to_post_number = replyToPostNumber
      const data = await request('/posts.json', { method: 'POST', body: JSON.stringify(body) })
      return data.id
    },
  }
}
