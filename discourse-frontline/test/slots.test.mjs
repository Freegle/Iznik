import { test } from 'node:test'
import assert from 'node:assert/strict'
import { extractFacts, mergeFacts, missingForBug } from '../src/slots.mjs'

const all = { actual: true, expected: true, steps: true, platform: false, when: false }

test('facts are read off the text', () => {
  const f = extractFacts('On my Samsung in the Android app since yesterday, every time I open Chats it says "Something went wrong". Member 12345678.')
  assert.equal(f.platform, 'app')
  assert.deepEqual(f.devices, ['samsung', 'android'])
  assert.equal(f.when, 'since yesterday')
  assert.equal(f.frequency, 'every time')
  assert.equal(f.errorText, 'Something went wrong')
  assert.deepEqual(f.ids, ['12345678'])
})

test('a number inside a link is a link, not an id', () => {
  const f = extractFacts('See https://www.ilovefreegle.org/message/98765432')
  assert.deepEqual(f.ids, [])
  assert.equal(f.links.length, 1)
})

test('a complete report needs nothing', () => {
  const text = 'In ModTools on Chrome, clicking Approve spins for ever. https://modtools.org/messages/pending'
  assert.deepEqual(missingForBug(text, extractFacts(text), all).needed, [])
})

test('an unnamed member is asked for, a named one is not', () => {
  const vague = 'One of our members cannot see her post on the website in Firefox.'
  assert.ok(missingForBug(vague, extractFacts(vague), all).needed.includes('which-member'))
  const named = `${vague} Her user number is 12345678.`
  assert.ok(!missingForBug(named, extractFacts(named), all).needed.includes('which-member'))
})

test('counted but unnamed things are asked for', () => {
  const text = 'Several posts were duplicated on the website in Chrome this morning.'
  assert.ok(missingForBug(text, extractFacts(text), all).needed.includes('which-post'))
})

test('the model cannot make us ask for a screenshot that is there', () => {
  const text = 'The button looks squashed on the page in Safari on my iPhone.'
  assert.ok(missingForBug(text, extractFacts(text), all).wanted.includes('screenshot'))
  assert.ok(!missingForBug(text, extractFacts(text, { hasImage: true }), all).wanted.includes('screenshot'))
})

test('where it happened is needed unless the text or the model has it', () => {
  const text = 'Clicking Approve spins for ever. https://modtools.org/x'
  assert.ok(missingForBug(text, extractFacts(text), all).needed.includes('where'))
  assert.ok(!missingForBug(text, extractFacts(text), { ...all, platform: true }).needed.includes('where'))
})

test('a later post fills in what an earlier one lacked', () => {
  const m = mergeFacts(extractFacts('It is broken'), extractFacts('Android app, since Monday', { hasImage: true }))
  assert.equal(m.platform, 'app')
  assert.equal(m.hasScreenshot, true)
  assert.match(m.when, /monday/i)
})
