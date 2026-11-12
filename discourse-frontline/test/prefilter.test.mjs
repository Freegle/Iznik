import { test } from 'node:test'
import assert from 'node:assert/strict'
import { prefilter } from '../src/prefilter.mjs'
import { makePost, testConfig } from './helpers.mjs'

const cfg = testConfig()
const run = (text, over = {}) => prefilter(makePost({ author: 'mod_a', text, ...over }, 0), cfg)

test('the service never reads its own posts or a developer\'s as reports', () => {
  assert.equal(run('Thanks for reporting this.', { author: 'Helper' }).why, 'own-or-system')
  assert.equal(run('I have deployed a fix for this.', { author: 'dev_sam' }).signal, 'staff')
})

test('small actions and whispers are not posts', () => {
  assert.equal(run('closed', { postType: 3 }).pass, false)
})

test('a private message is never read', () => {
  assert.equal(run('The app shows a white screen when I open Chats on my phone.', { categoryId: null }).why, 'private-message')
})

test('a bare thank-you costs nothing', () => {
  for (const t of ['Thanks!', 'Brilliant, thank you so much', 'OK great', 'Perfect, cheers']) assert.equal(run(t).signal, 'thanks', t)
})

test('a thank-you with a complaint in it is not a thank-you', () => {
  for (const t of ['Thanks, but it is still wrong', 'Thanks. How do I turn it off?', 'Great, however the count has not changed']) {
    assert.notEqual(run(t).signal, 'thanks', t)
  }
})

test('me too, still broken and fixed are told apart by rule', () => {
  assert.equal(run('Same here on Firefox').signal, 'metoo')
  assert.equal(run('+1').signal, 'metoo')
  assert.equal(run('Still not working for me this morning').signal, 'still-broken')
  assert.equal(run('Thanks but it is still happening').signal, 'still-broken')
  assert.equal(run('All working now').signal, 'resolved')
})

test('a real report goes through', () => {
  assert.equal(run('When I approve a pending message in ModTools the spinner never stops and I have to reload the page.').pass, true)
})

test('a long post that says "same here" in passing is still read', () => {
  assert.equal(run('Same here, and I also noticed that when I open the member log for anyone who joined this week the page shows a different person entirely, which seems much more serious to me.').pass, true)
})

test('outside the support categories only something broken is read, and marked listen-only', () => {
  const only = { ...cfg, categories: [9] }
  const elsewhere = (text) => prefilter(makePost({ author: 'mod_a', text }, 0), only)
  assert.equal(elsewhere('I think three days is too long a wait before a repost, and I would vote for two.').why, 'category-not-watched')
  for (const t of [
    'Since this morning the app shows a white screen when I open Chats on my phone.',
    'Members tell me the digest emails are not arriving this week.',
    'ModTools keeps logging me out every few minutes today.',
    'I get an error when I try to approve anything.',
  ]) assert.deepEqual(elsewhere(t), { pass: true, listenOnly: true }, t)
  assert.deepEqual(prefilter(makePost({ author: 'mod_a', categoryId: 9, text: 'I think three days is too long a wait before a repost.' }, 0), only), { pass: true, listenOnly: false })
})
