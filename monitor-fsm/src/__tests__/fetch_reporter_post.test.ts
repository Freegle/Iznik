import { describe, it, expect, vi, afterEach } from 'vitest'
import { fetchReporterPost } from '../actions/index.js'

afterEach(() => { vi.unstubAllGlobals() })

const topicWith = (cooked: string) => vi.fn(async () => new Response(JSON.stringify({
  post_stream: { posts: [{ post_number: 2, cooked }] },
})))

describe('fetchReporterPost', () => {
  it('sees an uploaded screenshot', async () => {
    vi.stubGlobal('fetch', topicWith('<p>It says 3 posts.</p><div class="lightbox-wrapper"><a class="lightbox" href="/u/x.png"><img src="/u/x.png"></a></div>'))
    expect(await fetchReporterPost(1, 2)).toEqual({ text: 'It says 3 posts.', hasImage: true })
  })

  it('does not count an emoji as a screenshot', async () => {
    vi.stubGlobal('fetch', topicWith('<p>Thanks <img src="/e/smile.png" class="emoji" alt=":smile:"></p>'))
    expect((await fetchReporterPost(1, 2)).hasImage).toBe(false)
  })

  it('does not count a picture inside a quote of someone else', async () => {
    vi.stubGlobal('fetch', topicWith('<aside class="quote"><img src="/u/theirs.png"></aside><p>Same here.</p>'))
    expect((await fetchReporterPost(1, 2)).hasImage).toBe(false)
  })
})
