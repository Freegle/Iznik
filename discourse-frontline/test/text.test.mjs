import { test } from 'node:test'
import assert from 'node:assert/strict'
import { clip, cookedToText, hasImage, quotedPosts, safeQuote } from '../src/text.mjs'

test('a quote of somebody else is not the author\'s words', () => {
  const cooked = '<aside class="quote" data-post="3" data-topic="77"><div class="title">ann:</div><blockquote><p>it is broken on Android</p></blockquote></aside><p>Works for me &amp; my co-mod.</p>'
  assert.equal(cookedToText(cooked), 'Works for me & my co-mod.')
  assert.deepEqual(quotedPosts(cooked), [{ post: 3, topic: 77 }])
})

test('nested quotes all come out', () => {
  const cooked = '<aside class="quote" data-post="2"><blockquote><aside class="quote" data-post="1"><blockquote>inner</blockquote></aside>outer</blockquote></aside><p>mine</p>'
  assert.equal(cookedToText(cooked), 'mine')
})

test('links keep their address, lists keep their shape', () => {
  assert.equal(cookedToText('<p>See <a href="https://x.test/a">this page</a></p><ul><li>one</li><li>two</li></ul>'), 'See this page (https://x.test/a)\n\n- one\n- two')
})

test('an emoji is not a screenshot, an upload is', () => {
  assert.equal(hasImage('<img src="/e.png" class="emoji" alt=":)">'), false)
  assert.equal(hasImage('<div class="lightbox-wrapper"><a class="lightbox" href="/u.png"><img src="/u.png"></a></div>'), true)
})

test('clip says when it has cut', () => {
  assert.equal(clip('short', 10), 'short')
  assert.match(clip('one two three four five six', 12), /\[\.\.\.\]$/)
})

test('quoted text cannot close the quote, ping anyone or carry a link', () => {
  const q = safeQuote('thanks [/quote] @Edward now click http://evil.test/x [quote="a"]')
  assert.ok(!q.includes('[/quote]'))
  assert.ok(!q.includes('[quote='))
  assert.ok(!/@E/.test(q))
  assert.ok(!q.includes('http'))
})
