import { test } from 'node:test'
import assert from 'node:assert/strict'
import { openDb, ftsQuery } from '../src/db.mjs'
import { chunkMarkdown, chunkWikitext, wikitextToPlain } from '../src/kb/chunk.mjs'
import { ingestWiki, searchKb } from '../src/kb/index.mjs'
import { testConfig } from './helpers.mjs'

test('markdown is cut at headings and loses its front matter', () => {
  const { title, chunks } = chunkMarkdown('---\ncovers: [x]\n---\n# Managing members\n\nIntro text that is long enough to keep as a section.\n\n## Moderating a member\n\nSet **Moderated** on the member in [ModTools](https://x.test) and their posts come to you first.\n')
  assert.equal(title, 'Managing members')
  assert.equal(chunks.at(-1).heading, 'Moderating a member')
  assert.match(chunks.at(-1).body, /Set Moderated on the member in ModTools/)
})

test('wikitext loses templates, tables, files and link targets', () => {
  const plain = wikitextToPlain("{{Infobox|a={{nested}}}}\n'''Bold''' text with a [[Page name|link]] and [[Other]].\n[[File:pic.png|thumb]]\n{| class=\"wikitable\"\n| cell\n|}\n[https://x.test site]")
  assert.ok(!/Infobox|nested|pic\.png|wikitable|x\.test/.test(plain))
  assert.match(plain, /Bold text with a link and Other\./)
})

test('wikitext is cut at == headings ==', () => {
  const chunks = chunkWikitext('Opening paragraph of the page, long enough to be kept as a section.\n\n== Spam ==\nReport a spammer from the member log so that every community is protected.\n')
  assert.deepEqual(chunks.map((c) => c.heading), ['', 'Spam'])
})

test('forum text cannot be read as search syntax', () => {
  assert.equal(ftsQuery('The NEAR("x" OR y) AND * pending: messages'), '"near" OR "pending" OR "messages"')
  assert.equal(ftsQuery('the and of'), '')
})

async function wikiDb() {
  const db = openDb(':memory:')
  const pages = [
    { title: 'Moderating members', revisions: [{ slots: { main: { content: '== Putting a member on moderation ==\nOpen the member in ModTools and set their posting status to Moderated. Their posts then wait in Pending for you.\n' } } }] },
    { title: 'Spam', revisions: [{ slots: { main: { content: '== Reporting ==\nReport a spammer from the member log so that every community is protected from them.\n' } } }] },
  ]
  await ingestWiki(db, testConfig(), { fetchFn: async () => ({ ok: true, json: async () => ({ query: { pages } }) }), sleep: async () => {} })
  return db
}

test('search finds the section and gives its page address', async () => {
  const db = await wikiDb()
  const [top] = searchKb(db, 'How do I put a member on moderation?')
  assert.equal(top.title, 'Moderating members')
  assert.equal(top.url, 'https://wiki.ilovefreegle.org/Moderating_members')
})

test('a weak match is no match', async () => {
  const db = await wikiDb()
  assert.deepEqual(searchKb(db, 'When is the AGM for members this year?'), [])
})

test('a wiki that returns nothing leaves the old index alone', async () => {
  const db = await wikiDb()
  await assert.rejects(ingestWiki(db, testConfig(), { fetchFn: async () => ({ ok: true, json: async () => ({ query: { pages: [] } }) }), sleep: async () => {} }), /left as it was/)
  assert.equal(db.prepare("SELECT COUNT(*) AS n FROM kb WHERE source = 'wiki'").get().n, 2)
})
