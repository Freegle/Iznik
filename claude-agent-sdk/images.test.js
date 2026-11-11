'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const { validateImages, promptContent, buildPrompt, MAX_IMAGES } = require('./images')

// The smallest real PNG and JPEG headers, padded; only the leading bytes are checked.
const PNG = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.alloc(20)]).toString('base64')
const JPG = Buffer.concat([Buffer.from([0xff, 0xd8, 0xff, 0xe0]), Buffer.alloc(20)]).toString('base64')

test('nothing attached is fine and yields no pictures', () => {
  assert.deepStrictEqual(validateImages(undefined), { images: [], error: null })
  assert.deepStrictEqual(validateImages([]), { images: [], error: null })
})

test('several pictures are accepted and named safely with the right extension', () => {
  const { images, error } = validateImages([
    { name: '../../etc/passwd.png', mime: 'image/png', data: PNG },
    { name: 'IMG 1.jpeg', mime: 'image/jpeg', data: `data:image/jpeg;base64,${JPG}` },
    { mime: 'image/png', data: PNG },
  ])
  assert.strictEqual(error, null)
  assert.strictEqual(images.length, 3)
  assert.strictEqual(images[0].name, 'etcpasswd.png')
  assert.strictEqual(images[1].name, 'IMG 1.jpg')
  assert.strictEqual(images[2].name, 'screenshot-3.png')
})

test('a file that is not a picture is refused even if the type says it is', () => {
  const { error } = validateImages([{ mime: 'image/png', data: Buffer.from('<?php echo 1;').toString('base64') }])
  assert.match(error, /not the kind of picture/)
})

test('a non-image type, bad base64 and too many pictures are refused', () => {
  assert.match(validateImages([{ mime: 'application/pdf', data: PNG }]).error, /PNG, JPEG/)
  assert.match(validateImages([{ mime: 'image/png', data: '!!!' }]).error, /could not be read/)
  const many = Array.from({ length: MAX_IMAGES + 1 }, () => ({ mime: 'image/png', data: PNG }))
  assert.match(validateImages(many).error, /at most 5/)
  assert.match(validateImages('nope').error, /list/)
})

test('an oversized picture is refused', () => {
  const big = Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0, 0, 0, 0]), Buffer.alloc(5 * 1024 * 1024)]).toString('base64')
  assert.match(validateImages([{ mime: 'image/png', data: big }]).error, /too big/)
})

test('the prompt carries the question then every picture, and says how many', () => {
  const { images } = validateImages([
    { mime: 'image/png', data: PNG },
    { mime: 'image/jpeg', data: JPG },
  ])
  const blocks = promptContent('Why the error?', images)
  assert.strictEqual(blocks.length, 3)
  assert.strictEqual(blocks[0].type, 'text')
  assert.match(blocks[0].text, /2 screenshots are attached/)
  assert.deepStrictEqual(blocks[1].source, { type: 'base64', media_type: 'image/png', data: PNG })
  assert.strictEqual(blocks[2].source.media_type, 'image/jpeg')
})

test('with no pictures the prompt is the plain question, with pictures it is a one-message stream', async () => {
  assert.strictEqual(buildPrompt('hello', [], 'abc'), 'hello')
  const { images } = validateImages([{ mime: 'image/png', data: PNG }])
  const seen = []
  for await (const m of buildPrompt('hello', images, 'abc')) seen.push(m)
  assert.strictEqual(seen.length, 1)
  assert.strictEqual(seen[0].type, 'user')
  assert.strictEqual(seen[0].session_id, 'abc')
  assert.strictEqual(seen[0].message.content[1].type, 'image')
})

test('a referral may carry more pictures than one question', () => {
  const ten = Array.from({ length: 10 }, () => ({ mime: 'image/png', data: PNG }))
  assert.strictEqual(validateImages(ten, 10).images.length, 10)
  assert.match(validateImages(ten).error, /at most 5/)
})
