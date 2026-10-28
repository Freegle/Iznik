'use strict'

/**
 * Screenshots a support volunteer attaches to a question or a referral.
 *
 * No requires, so CI's bare `node --test` can load it. The browser sends each
 * picture as {name, mime, data} with `data` base64 (no data: prefix), already
 * scaled down. Nothing here trusts that: the type, the size and the base64 are
 * all checked again before a picture goes to the model or into an email.
 */

const MAX_IMAGES = 5
const MAX_REFERRAL_IMAGES = 10
const MAX_IMAGE_BYTES = 4 * 1024 * 1024
const MAX_TOTAL_BYTES = 12 * 1024 * 1024
const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']
const EXTENSIONS = { 'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp' }

// Four bytes of a PNG/JPEG/GIF/WebP header, so a file renamed to look like a
// picture is refused rather than forwarded to the model or a mailbox.
function looksLikeMime(buf, mime) {
  if (mime === 'image/png') return buf.length > 8 && buf[0] === 0x89 && buf[1] === 0x50 && buf[2] === 0x4e && buf[3] === 0x47
  if (mime === 'image/jpeg') return buf.length > 3 && buf[0] === 0xff && buf[1] === 0xd8 && buf[2] === 0xff
  if (mime === 'image/gif') return buf.length > 6 && buf.toString('latin1', 0, 3) === 'GIF'
  if (mime === 'image/webp') return buf.length > 12 && buf.toString('latin1', 0, 4) === 'RIFF' && buf.toString('latin1', 8, 12) === 'WEBP'
  return false
}

function safeName(name, mime, index) {
  const base = String(name || '')
    .replace(/[^A-Za-z0-9._ -]/g, '')
    .replace(/^[. ]+/, '')
    .replace(/\.[A-Za-z0-9]{1,5}$/, '')
    .trim()
    .slice(0, 60)
  return `${base || `screenshot-${index + 1}`}.${EXTENSIONS[mime]}`
}

/**
 * @param {unknown} list  what the client sent
 * @returns {{images: Array<{name:string, mime:string, data:string, bytes:number}>, error: string|null}}
 */
function validateImages(list, max = MAX_IMAGES) {
  if (list === undefined || list === null) return { images: [], error: null }
  if (!Array.isArray(list)) return { images: [], error: 'Screenshots must be a list.' }
  if (list.length > max) {
    return { images: [], error: `Please attach at most ${max} screenshots.` }
  }

  const images = []
  let total = 0
  for (let i = 0; i < list.length; i++) {
    const item = list[i] || {}
    const mime = String(item.mime || '').toLowerCase()
    if (!ALLOWED_MIMES.includes(mime)) {
      return { images: [], error: 'Screenshots must be PNG, JPEG, GIF or WebP pictures.' }
    }
    const data = String(item.data || '').replace(/^data:[^,]*,/, '').replace(/\s+/g, '')
    if (!data || !/^[A-Za-z0-9+/]+={0,2}$/.test(data)) {
      return { images: [], error: 'A screenshot could not be read.' }
    }
    const buf = Buffer.from(data, 'base64')
    if (!looksLikeMime(buf, mime)) {
      return { images: [], error: 'A screenshot is not the kind of picture it says it is.' }
    }
    if (buf.length > MAX_IMAGE_BYTES) {
      return { images: [], error: 'A screenshot is too big (4MB each at most).' }
    }
    total += buf.length
    if (total > MAX_TOTAL_BYTES) {
      return { images: [], error: 'The screenshots are too big together.' }
    }
    images.push({ name: safeName(item.name, mime, i), mime, data, bytes: buf.length })
  }
  return { images, error: null }
}

/**
 * The content blocks for one user turn: the question, then each picture, with
 * a line saying how many there are so the model knows to look at all of them.
 */
function promptContent(text, images) {
  const blocks = [{ type: 'text', text: String(text || '') }]
  if (images && images.length) {
    blocks[0].text +=
      `\n\n(${images.length === 1 ? 'A screenshot is' : `${images.length} screenshots are`} attached. ` +
      `Read ${images.length === 1 ? 'it' : 'them'} - the evidence may be only in the picture.)`
    for (const img of images) {
      blocks.push({ type: 'image', source: { type: 'base64', media_type: img.mime, data: img.data } })
    }
  }
  return blocks
}

/**
 * The prompt for query(): a plain string when there are no pictures, otherwise
 * a one-message stream, which is the only form the SDK accepts images in.
 */
function buildPrompt(text, images, sessionId) {
  if (!images || !images.length) return text
  return (async function* () {
    yield {
      type: 'user',
      message: { role: 'user', content: promptContent(text, images) },
      parent_tool_use_id: null,
      session_id: sessionId || '',
    }
  })()
}

module.exports = {
  MAX_IMAGES,
  MAX_REFERRAL_IMAGES,
  MAX_IMAGE_BYTES,
  MAX_TOTAL_BYTES,
  ALLOWED_MIMES,
  validateImages,
  promptContent,
  buildPrompt,
}
