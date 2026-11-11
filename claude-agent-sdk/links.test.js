'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const fs = require('fs')
const os = require('os')
const path = require('path')
const { unknownSiteLinks, withLinkWarning } = require('./links')

// A pages folder shaped like iznik-nuxt3/pages, with nuxt.config.ts beside it.
function pages(files, config = '') {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'site-'))
  const dir = path.join(root, 'pages')
  for (const f of files) {
    fs.mkdirSync(path.dirname(path.join(dir, f)), { recursive: true })
    fs.writeFileSync(path.join(dir, f), '<template />')
  }
  fs.writeFileSync(path.join(root, 'nuxt.config.ts'), config)
  return dir
}

const site = pages([
  'index.vue',
  'myposts.vue',
  'volunteering/[[id]].vue',
  'volunteerings/[[groupid]].vue',
  'mypost/[id]/[[action]].vue',
  'microvolunteering/index.client.vue',
  'explore/[...slug].vue',
], `
  routeRules: {
    '/councils': { redirect: '/partnerships' },
    '/find': { redirect: { to: '/ask', statusCode: 301 } },
    '/find/**': { redirect: { to: '/ask/**', statusCode: 301 } },
    '/chats/**': { ssr: false },
  },`)

test('a page the site has is not flagged, with or without its optional parts', () => {
  const text =
    'See https://www.ilovefreegle.org/volunteering/101029 and https://www.ilovefreegle.org/volunteering, ' +
    'https://www.ilovefreegle.org/mypost/5/repost, https://ilovefreegle.org/myposts?x=1#top, ' +
    'https://www.ilovefreegle.org/microvolunteering and https://www.ilovefreegle.org/.'
  assert.deepStrictEqual(unknownSiteLinks(text, site), [])
})

test('a page the site does not have is flagged', () => {
  const text = 'the easiest route is https://www.ilovefreegle.org/myvolunteering, where they are listed.'
  assert.deepStrictEqual(unknownSiteLinks(text, site), ['https://www.ilovefreegle.org/myvolunteering'])
})

test('a required part that is missing, or one part too many, is flagged', () => {
  const text = 'https://www.ilovefreegle.org/mypost and https://www.ilovefreegle.org/myposts/extra'
  assert.deepStrictEqual(unknownSiteLinks(text, site), [
    'https://www.ilovefreegle.org/mypost',
    'https://www.ilovefreegle.org/myposts/extra',
  ])
})

test('an old path the site redirects still works', () => {
  const text = 'https://www.ilovefreegle.org/find, https://www.ilovefreegle.org/find/whereami, https://www.ilovefreegle.org/councils'
  assert.deepStrictEqual(unknownSiteLinks(text, site), [])
})

test('a rule that is not a redirect does not make a path a page', () => {
  assert.deepStrictEqual(unknownSiteLinks('https://www.ilovefreegle.org/chats/5', site), ['https://www.ilovefreegle.org/chats/5'])
})

test('a placeholder such as <id> stands for any value', () => {
  assert.deepStrictEqual(unknownSiteLinks('Go to https://www.ilovefreegle.org/volunteering/<id> and click', site), [])
})

test('a catch-all page matches any depth below it', () => {
  assert.deepStrictEqual(unknownSiteLinks('https://www.ilovefreegle.org/explore/a/b/c', site), [])
})

test('other sites are left alone, and each bad link is listed once', () => {
  const text =
    'https://modtools.org/members https://example.com/myvolunteering ' +
    'https://www.ilovefreegle.org/nope https://www.ilovefreegle.org/nope.'
  assert.deepStrictEqual(unknownSiteLinks(text, site), ['https://www.ilovefreegle.org/nope'])
})

test('without the pages folder nothing can be checked, so nothing is flagged', () => {
  assert.deepStrictEqual(unknownSiteLinks('https://www.ilovefreegle.org/nope', '/no/such/dir'), [])
})

test('the warning is added only when a link does not match a page', () => {
  const good = 'Use https://www.ilovefreegle.org/volunteering/101029.'
  assert.strictEqual(withLinkWarning(good, site), good)

  const bad = withLinkWarning('Use https://www.ilovefreegle.org/myvolunteering.', site)
  assert.match(bad, /^Use https:\/\/www\.ilovefreegle\.org\/myvolunteering\./)
  assert.match(bad, /Check before sending/)
  assert.match(bad, /- https:\/\/www\.ilovefreegle\.org\/myvolunteering$/m)
})
