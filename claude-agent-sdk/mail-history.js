/**
 * Past mail between a member and the support@ and info@ mailboxes.
 *
 * Read-only IMAP against Gmail with each mailbox's app password: the mailbox is opened with EXAMINE
 * and every body is fetched with BODY.PEEK, so nothing is marked read or changed. Mail is matched on
 * the member's own email addresses, two years back, and comes back as dated headers with a short
 * excerpt: ['available', 'reason', 'entries' => [{date, mailbox, direction, subject, excerpt}]].
 * When available is false the reason says why in plain words; the brief then says "not available",
 * never "none".
 *
 * Used by the first pass (its PAST_MAIL_WITH_SUPPORT block) and by the support_mail_history tool.
 * See docs/developers/reference/ai-support-helper.md.
 */
const tls = require('tls')

const IMAP_HOST = 'imap.gmail.com'
const IMAP_PORT = 993
const YEARS_BACK = 2
const PER_MAILBOX = 15
const MAX_ENTRIES = 20
const MAX_ADDRESSES = 8
const EXCERPT_CHARS = 300
const SUBJECT_CHARS = 150
const PART_BYTES = 6000
const HTML_PART_BYTES = 30000
const MAILBOX_TIMEOUT_MS = 30000
const CACHE_TTL_MS = 6 * 60 * 60 * 1000
const CACHE_MAX = 200

// Our own addresses are volunteers' and the system's, not the member's: searching on them would pull
// in the team's own mail, which is a separate data-protection question.
const OWN_DOMAIN = /(^|\.)ilovefreegle\.org$/i
const ADDRESS = /^[A-Za-z0-9._%+'-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/

const REASON_NOT_SET_UP = 'mailbox access is not set up yet'
const REASON_NO_MEMBER = 'there is no member in this case'
const REASON_NO_ADDRESS = 'the member has no email address on record to search for'
const REASON_UNREADABLE = 'the support mailboxes could not be read just now'

/** The mailboxes with credentials, in a fixed order. */
function mailboxesFromEnv(env = process.env) {
  return [
    { name: 'support', user: env.SUPPORT_MAIL_USER, pass: env.SUPPORT_MAIL_PASS },
    { name: 'info', user: env.INFO_MAIL_USER, pass: env.INFO_MAIL_PASS },
  ]
    .filter((m) => m.user && m.pass)
    .map((m) => ({ ...m, pass: String(m.pass).replace(/\s+/g, '') }))
}

// ---------------------------------------------------------------------------
// IMAP responses
// ---------------------------------------------------------------------------

/**
 * Length of the first complete response in buf (a line, plus any {n} literals and the lines after
 * them), or -1 while more is needed. buf is latin1, so one character is one byte.
 */
function completeResponseLength(buf) {
  let pos = 0
  for (;;) {
    const eol = buf.indexOf('\r\n', pos)
    if (eol < 0) return -1
    const m = /\{(\d+)\+?\}$/.exec(buf.slice(pos, eol))
    if (!m) return eol + 2
    const end = eol + 2 + Number(m[1])
    if (buf.length < end) return -1
    pos = end
  }
}

/**
 * Parse one response into nested arrays: atoms and strings as strings, NIL as null, a parenthesised
 * list as an array. An atom keeps any [section] in it whole, so BODY[1.2]<0> is one atom.
 */
function parseResponse(s) {
  let i = 0
  const list = () => {
    const out = []
    for (;;) {
      while (s[i] === ' ') i++
      if (i >= s.length || s[i] === ')' || s.startsWith('\r\n', i)) {
        if (s[i] === ')') i++
        return out
      }
      const c = s[i]
      if (c === '(') {
        i++
        out.push(list())
      } else if (c === '"') {
        i++
        let v = ''
        while (i < s.length && s[i] !== '"') {
          if (s[i] === '\\') i++
          v += s[i++] ?? ''
        }
        i++
        out.push(v)
      } else if (c === '{') {
        const m = /^\{(\d+)\+?\}\r\n/.exec(s.slice(i, i + 24))
        if (!m) {
          // Not a literal after all: keep the rest as text rather than guess where it ends.
          out.push(s.slice(i))
          i = s.length
          continue
        }
        i += m[0].length
        out.push(s.slice(i, i + Number(m[1])))
        i += Number(m[1])
      } else {
        const start = i
        let v = ''
        while (i < s.length && !' ()\r'.includes(s[i])) {
          if (s[i] === '[') {
            const close = s.indexOf(']', i)
            const eol = s.indexOf('\r\n', i)
            const end = close >= 0 ? close + 1 : eol >= 0 ? eol : s.length
            v += s.slice(i, end)
            i = end
          } else v += s[i++]
        }
        // A stray character that starts nothing (a bare \r) is stepped over, so the parse always moves on.
        if (i === start) {
          i++
          continue
        }
        out.push(v === 'NIL' ? null : v)
      }
    }
  }
  return list()
}

/** A FETCH response's item list as an object keyed by item name in upper case. */
function fetchItems(parsed) {
  const items = Array.isArray(parsed[3]) ? parsed[3] : []
  const out = {}
  for (let k = 0; k + 1 < items.length; k += 2) out[String(items[k]).toUpperCase()] = items[k + 1]
  return out
}

/** A minimal IMAP client over one socket: one command at a time. */
class ImapClient {
  constructor(socket) {
    this.socket = socket
    this.buf = ''
    this.ready = []
    this.waiting = null
    this.failure = null
    this.tag = 0
    socket.setEncoding('latin1')
    socket.on('data', (d) => {
      this.buf += d
      this.pump()
    })
    const gone = (e) => {
      this.failure = this.failure || e || new Error('connection closed')
      if (this.waiting) {
        const w = this.waiting
        this.waiting = null
        w.reject(this.failure)
      }
    }
    socket.on('error', gone)
    socket.on('close', () => gone())
  }

  pump() {
    for (;;) {
      const n = completeResponseLength(this.buf)
      if (n < 0) break
      this.ready.push(this.buf.slice(0, n))
      this.buf = this.buf.slice(n)
    }
    if (this.waiting && this.ready.length) {
      const w = this.waiting
      this.waiting = null
      w.resolve(this.ready.shift())
    }
  }

  next() {
    if (this.ready.length) return Promise.resolve(this.ready.shift())
    if (this.failure) return Promise.reject(this.failure)
    return new Promise((resolve, reject) => {
      this.waiting = { resolve, reject }
    })
  }

  async greeting() {
    const line = await this.next()
    if (!line.startsWith('* OK')) throw new Error('unexpected greeting')
  }

  /** Send a command; resolve with its untagged responses, reject unless it ends OK. */
  async command(cmd) {
    const tag = `a${++this.tag}`
    this.socket.write(`${tag} ${cmd}\r\n`)
    const untagged = []
    for (;;) {
      const r = await this.next()
      if (r.startsWith(`${tag} `)) {
        // The server's words are not passed on: a refused login names nothing about the member, but
        // there is no need to carry it either.
        if (!r.startsWith(`${tag} OK`)) throw new Error(`${cmd.split(' ')[0]} refused`)
        return untagged
      }
      untagged.push(r)
    }
  }
}

// ---------------------------------------------------------------------------
// Text
// ---------------------------------------------------------------------------

function decodeBytes(buf, charset) {
  try {
    return new TextDecoder(charset || 'utf-8').decode(buf)
  } catch {
    return new TextDecoder('utf-8').decode(buf)
  }
}

function quotedPrintable(s) {
  const bytes = []
  const src = s.replace(/=\r?\n/g, '')
  for (let k = 0; k < src.length; k++) {
    if (src[k] === '=' && /^[0-9A-Fa-f]{2}$/.test(src.slice(k + 1, k + 3))) {
      bytes.push(parseInt(src.slice(k + 1, k + 3), 16))
      k += 2
    } else bytes.push(src.charCodeAt(k) & 0xff)
  }
  return Buffer.from(bytes)
}

/** A header value: raw 8-bit text taken as UTF-8, and RFC 2047 encoded words decoded. */
function decodeHeader(value) {
  if (value == null) return ''
  const raw = Buffer.from(String(value), 'latin1').toString('utf8')
  return raw
    .replace(/(=\?[^?]+\?[BbQq]\?[^?]*\?=)\s+(?==\?)/g, '$1')
    .replace(/=\?([^?*]+)(?:\*[^?]*)?\?([BbQq])\?([^?]*)\?=/g, (m, cs, enc, txt) =>
      decodeBytes(enc.toUpperCase() === 'B' ? Buffer.from(txt, 'base64') : quotedPrintable(txt.replace(/_/g, ' ')), cs)
    )
}

/** A part's body, cut at PART_BYTES, decoded from its transfer encoding and charset. */
function decodePart(raw, encoding, charset) {
  let bytes
  if (encoding === 'base64') {
    const clean = raw.replace(/[^A-Za-z0-9+/=]/g, '')
    bytes = Buffer.from(clean.slice(0, clean.length - (clean.length % 4)), 'base64')
  } else if (encoding === 'quoted-printable') {
    bytes = quotedPrintable(raw.replace(/=[0-9A-Fa-f]?$/, ''))
  } else bytes = Buffer.from(raw, 'latin1')
  return decodeBytes(bytes, charset)
}

/**
 * HTML as plain text. The part may have been cut short, so a style, script or head block, quoted mail
 * or a tag with no end runs to the end of what was read and is dropped with it.
 */
function htmlToText(html) {
  // Comments first: Outlook hides whole blocks in conditional comments.
  let s = html.replace(/<!--[\s\S]*?(-->|$)/g, ' ').replace(/<(style|script|head)(?=[\s>])[\s\S]*?(<\/\1>|$)/gi, ' ')
  // Quoted mail, innermost quote first so a quote inside a quote goes whole.
  const innermost = /<blockquote\b(?:(?!<blockquote\b)[\s\S])*?<\/blockquote>/gi
  for (let before = ''; before !== s; ) {
    before = s
    s = s.replace(innermost, '\n')
  }
  return s
    .replace(/<blockquote\b[\s\S]*$/i, '\n')
    .replace(/<br\s*\/?>|<\/(p|div|tr|li|h\d)>/gi, '\n')
    // Only something that starts like a tag is a tag: "a < 5 deal" keeps its text.
    .replace(/<[a-z/!](?:"[^"]*(?:"|$)|'[^']*(?:'|$)|[^>"'])*(>|$)/gi, ' ')
    .replace(/&#(\d+);|&#x([0-9a-f]+);|&([a-z]+);/gi, (m, dec, hex, name) => {
      if (dec || hex) {
        const n = dec ? Number(dec) : parseInt(hex, 16)
        return n > 0 && n <= 0x10ffff ? String.fromCodePoint(n) : ' '
      }
      return ENTITIES[name.toLowerCase()] ?? m
    })
}

// The named entities mail clients actually write; anything else is left as it is.
const ENTITIES = {
  nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", pound: '£', euro: '€', copy: '©', hellip: '…',
  rsquo: '’', lsquo: '‘', rdquo: '”', ldquo: '“', ndash: '–', mdash: '—', bull: '•', middot: '·',
}

/**
 * The new text of a mail: quoted earlier mail, the "On ... wrote:" line and everything after it,
 * forwarded headers and the signature are dropped, then whitespace is collapsed.
 */
function excerptOf(text) {
  const lines = String(text).replace(/\r/g, '').split('\n')
  const kept = []
  for (let k = 0; k < lines.length; k++) {
    const t = lines[k].trim()
    const next = (lines[k + 1] || '').trim()
    if (t.startsWith('>')) continue
    if (/^On\b/.test(t) && (/wrote:?$/.test(t) || /wrote:?$/.test(next))) break
    if (/^-{2,}\s*(Original|Forwarded) Message/i.test(t)) break
    if (/^(From|Sent|Date):\s/i.test(t) && kept.length) break
    if (/^-- ?$/.test(lines[k]) || /^Sent from my /i.test(t)) break
    kept.push(t)
  }
  return kept.join(' ').replace(/\s+/g, ' ').trim().slice(0, EXCERPT_CHARS)
}

// ---------------------------------------------------------------------------
// One mailbox
// ---------------------------------------------------------------------------

const lower = (v) => String(v || '').toLowerCase()
const param = (params, name) => {
  if (!Array.isArray(params)) return null
  for (let k = 0; k + 1 < params.length; k += 2) if (lower(params[k]) === name) return params[k + 1]
  return null
}

/** The part to read for an excerpt: the first text/plain, else the first text/html, not attachments. */
function textPart(bs, prefix = '') {
  if (!Array.isArray(bs)) return null
  if (Array.isArray(bs[0])) {
    let html = null
    for (let k = 0; Array.isArray(bs[k]); k++) {
      const found = textPart(bs[k], prefix ? `${prefix}.${k + 1}` : `${k + 1}`)
      if (found && found.subtype === 'plain') return found
      if (found && !html) html = found
    }
    return html
  }
  const type = lower(bs[0])
  const subtype = lower(bs[1])
  if (type !== 'text' || (subtype !== 'plain' && subtype !== 'html')) return null
  // A text part's disposition is at index 9 when present: skip a text file attached to the mail.
  if (Array.isArray(bs[9]) && lower(bs[9][0]) === 'attachment') return null
  return { part: prefix || '1', subtype, charset: param(bs[2], 'charset'), encoding: lower(bs[5]) }
}

const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec']

/** An INTERNALDATE such as "17-Jul-2026 02:44:25 -0700" as epoch milliseconds, or 0. */
function internalDate(s) {
  const m = /^\s*(\d{1,2})-([A-Za-z]{3})-(\d{4}) (\d{2}):(\d{2}):(\d{2}) ([+-])(\d{2})(\d{2})/.exec(String(s || ''))
  if (!m) return 0
  const month = MONTHS.indexOf(m[2].toLowerCase())
  if (month < 0) return 0
  const offset = (m[7] === '-' ? -1 : 1) * (Number(m[8]) * 60 + Number(m[9])) * 60000
  return Date.UTC(Number(m[3]), month, Number(m[1]), Number(m[4]), Number(m[5]), Number(m[6])) - offset
}

const addressOf = (a) => (Array.isArray(a) && a[2] && a[3] ? `${a[2]}@${a[3]}`.toLowerCase() : '')
const quoteImap = (s) => `"${String(s).replace(/[\\"]/g, '\\$&')}"`

/** An address without any +tag, so mail from someone+freegle@ counts as theirs. */
const untagged = (a) => a.replace(/\+[^@]*@/, '@')

/**
 * The date for Gmail's after:, a day early: Gmail reads it in Pacific time, so the extra day makes
 * sure nothing at the edge of the two years is missed.
 */
function gmailDate(d) {
  const e = new Date(d.getTime() - 24 * 60 * 60 * 1000)
  return `${e.getUTCFullYear()}/${String(e.getUTCMonth() + 1).padStart(2, '0')}/${String(e.getUTCDate()).padStart(2, '0')}`
}

/** The folder holding every mail, sent and received: Gmail flags it \All whatever its local name. */
async function allMailFolder(client) {
  for (const line of await client.command('LIST "" "*"')) {
    const p = parseResponse(line)
    if (p[1] === 'LIST' && Array.isArray(p[2]) && p[2].some((f) => lower(f) === '\\all')) return p[4]
  }
  return '[Gmail]/All Mail'
}

/**
 * Read one mailbox: the newest PER_MAILBOX mails from, to or copied to any of the addresses since
 * the given date.
 */
async function readMailbox({ mailbox, addresses, since, connect, timeoutMs = MAILBOX_TIMEOUT_MS, stop }) {
  const socket = connect(mailbox)
  const timer = setTimeout(() => socket.destroy(new Error('timed out')), timeoutMs)
  const onStop = () => socket.destroy(new Error('stopped'))
  if (stop) stop.addEventListener('abort', onStop, { once: true })
  const client = new ImapClient(socket)
  try {
    await client.greeting()
    await client.command(`LOGIN ${quoteImap(mailbox.user)} ${quoteImap(mailbox.pass)}`)
    await client.command(`EXAMINE ${quoteImap(await allMailFolder(client))}`)

    const terms = addresses.flatMap((a) => [`from:${a}`, `to:${a}`, `cc:${a}`]).join(' ')
    const found = await client.command(`UID SEARCH X-GM-RAW ${quoteImap(`{${terms}} after:${gmailDate(since)}`)}`)
    const uids = found
      .flatMap((line) => (line.startsWith('* SEARCH') ? line.slice(8).trim().split(/\s+/) : []))
      .filter((u) => /^\d+$/.test(u))
      .map(Number)
      .sort((a, b) => a - b)
      .slice(-PER_MAILBOX)
    if (!uids.length) return []

    const metas = (await client.command(`UID FETCH ${uids.join(',')} (UID INTERNALDATE ENVELOPE BODYSTRUCTURE)`))
      .map(parseResponse)
      .filter((p) => p[2] === 'FETCH')
      .map(fetchItems)

    const own = new Set(addresses.map(untagged))
    const entries = []
    for (const m of metas) {
      const env = Array.isArray(m.ENVELOPE) ? m.ENVELOPE : []
      const from = addressOf(Array.isArray(env[2]) ? env[2][0] : null)
      const part = /^\d+$/.test(String(m.UID)) ? textPart(m.BODYSTRUCTURE) : null
      let excerpt = ''
      if (part) {
        // HTML often starts with kilobytes of style, so more of it is read.
        const bytes = part.subtype === 'html' ? HTML_PART_BYTES : PART_BYTES
        try {
          const got = await client.command(`UID FETCH ${m.UID} (BODY.PEEK[${part.part}]<0.${bytes}>)`)
          const items = got.map(parseResponse).filter((p) => p[2] === 'FETCH').map(fetchItems)[0] || {}
          const raw = Object.entries(items).find(([k]) => k.startsWith('BODY['))
          if (raw && typeof raw[1] === 'string') {
            const body = decodePart(raw[1], part.encoding, part.charset)
            excerpt = excerptOf(part.subtype === 'html' ? htmlToText(body) : body)
          }
        } catch (e) {
          // One mail the server will not give is listed without an excerpt; a lost connection ends the read.
          if (client.failure) throw e
        }
      }
      const at = internalDate(m.INTERNALDATE)
      entries.push({
        at,
        messageId: String(env[9] || ''),
        date: at ? new Date(at).toISOString().slice(0, 16).replace('T', ' ') : '',
        mailbox: mailbox.name,
        direction: own.has(untagged(from)) ? 'in' : 'out',
        subject: decodeHeader(env[1]).replace(/\s+/g, ' ').trim().slice(0, SUBJECT_CHARS),
        excerpt,
      })
    }
    try {
      await client.command('LOGOUT')
    } catch {}
    return entries
  } finally {
    clearTimeout(timer)
    if (stop) stop.removeEventListener('abort', onStop)
    socket.destroy()
  }
}

// ---------------------------------------------------------------------------
// A member's history
// ---------------------------------------------------------------------------

/** The member's own addresses worth searching for: preferred and confirmed first, ours left out. */
async function memberAddresses(memberId, select) {
  const rows = await select(
    `SELECT email FROM users_emails WHERE userid=${Number(memberId)} ORDER BY preferred DESC, validated IS NOT NULL DESC, id DESC`
  )
  const seen = new Set()
  for (const r of rows) {
    const email = String(r.email || '').trim().toLowerCase()
    if (!ADDRESS.test(email) || OWN_DOMAIN.test(email.split('@')[1])) continue
    seen.add(email)
    if (seen.size >= MAX_ADDRESSES) break
  }
  return [...seen]
}

const unreadable = () => ({ available: false, reason: REASON_UNREADABLE, entries: [] })

// Results by member, and reads under way by member, so callers asking at the same time share one read.
const cache = new Map()
const inflight = new Map()

/**
 * A member's past mail with support@ and info@, newest first. A full result, with mail in it or with
 * none, is kept for CACHE_TTL_MS; a failure, or a result missing a mailbox, is not kept.
 *
 * @param {number} memberId
 * @param {object} [deps] - env, select (a read-only SELECT), connect (mailbox -> socket), now, log,
 *   timeoutMs (per mailbox), totalTimeoutMs (the whole read), signal (the caller stops waiting on
 *   abort; a read it shares goes on to its own timeout).
 */
async function mailHistoryFor(memberId, deps = {}) {
  const env = deps.env || process.env
  const now = deps.now || Date.now
  const mailboxes = mailboxesFromEnv(env)
  if (!mailboxes.length) return { available: false, reason: REASON_NOT_SET_UP, entries: [] }
  // One key per member whether the id came as a number or as text.
  const id = typeof memberId === 'number' || typeof memberId === 'string' ? Number(memberId) : NaN
  if (!Number.isSafeInteger(id) || id <= 0) return { available: false, reason: REASON_NO_MEMBER, entries: [] }

  for (const [k, kept] of cache) if (now() - kept.at >= CACHE_TTL_MS) cache.delete(k)
  const hit = cache.get(id)
  if (hit) return hit.result

  let read = inflight.get(id)
  if (!read) {
    // The whole read is bounded too, so a stuck database connection cannot hold every later caller.
    const totalMs = deps.totalTimeoutMs || (deps.timeoutMs || MAILBOX_TIMEOUT_MS) + 15000
    // At the bound the read is stopped too: its connections close and its result is not kept.
    const stop = new AbortController()
    let timer
    const bound = new Promise((resolve) => {
      timer = setTimeout(() => {
        stop.abort()
        resolve(unreadable())
      }, totalMs)
    })
    read = Promise.race([readHistory(id, mailboxes, { ...deps, now, stop: stop.signal }), bound]).finally(() => {
      clearTimeout(timer)
      inflight.delete(id)
    })
    inflight.set(id, read)
  }
  return deps.signal ? untilAborted(read, deps.signal) : read
}

function untilAborted(read, signal) {
  if (signal.aborted) return Promise.resolve(unreadable())
  return new Promise((resolve) => {
    const onAbort = () => resolve(unreadable())
    signal.addEventListener('abort', onAbort, { once: true })
    read.then(resolve, () => resolve(unreadable())).finally(() => signal.removeEventListener('abort', onAbort))
  })
}

async function readHistory(memberId, mailboxes, deps) {
  const { now } = deps
  const log = deps.log || console.log
  const select = deps.select || ((sql) => require('./tools').dbSelect(sql, 50, {}))
  const connect = deps.connect || (() => tls.connect({ host: IMAP_HOST, port: IMAP_PORT, servername: IMAP_HOST }))

  let addresses
  try {
    addresses = await memberAddresses(memberId, select)
  } catch {
    log('[MailHistory] the member addresses could not be read')
    return unreadable()
  }
  if (!addresses.length) return { available: false, reason: REASON_NO_ADDRESS, entries: [] }

  const since = new Date(now())
  since.setUTCFullYear(since.getUTCFullYear() - YEARS_BACK)

  if (deps.stop && deps.stop.aborted) return unreadable()
  const settled = await Promise.allSettled(
    mailboxes.map((mailbox) => readMailbox({ mailbox, addresses, since, connect, timeoutMs: deps.timeoutMs, stop: deps.stop }))
  )
  const failed = []
  const lists = []
  settled.forEach((s, k) => {
    if (s.status === 'fulfilled') return lists.push(s.value)
    failed.push(mailboxes[k].name)
    // The mailbox name and a fixed message only: nothing about the member.
    log(`[MailHistory] the ${mailboxes[k].name} mailbox could not be read: ${String((s.reason && s.reason.message) || 'error').replace(/[^\w ]/g, '')}`)
  })
  if (!lists.length) return unreadable()

  // A mail sent to both mailboxes appears once, under the first.
  const seen = new Set()
  const entries = lists
    .flat()
    .sort((a, b) => b.at - a.at)
    .filter((e) => {
      if (!e.messageId) return true
      if (seen.has(e.messageId)) return false
      seen.add(e.messageId)
      return true
    })
    .slice(0, MAX_ENTRIES)
    .map(({ date, mailbox, direction, subject, excerpt }) => ({ date, mailbox, direction, subject, excerpt }))

  if (failed.length) {
    const read = mailboxes.map((m) => m.name).filter((n) => !failed.includes(n))
    return { available: true, reason: `the ${failed.join(' and ')} mailbox could not be read just now, so only ${read.join(' and ')} mail is listed`, entries }
  }
  const result = { available: true, reason: '', entries }
  if (deps.stop && deps.stop.aborted) return result
  if (cache.size >= CACHE_MAX) cache.delete(cache.keys().next().value)
  cache.set(memberId, { at: now(), result })
  return result
}

module.exports = {
  REASON_NOT_SET_UP,
  REASON_NO_MEMBER,
  REASON_NO_ADDRESS,
  REASON_UNREADABLE,
  mailboxesFromEnv,
  mailHistoryFor,
  readMailbox,
  memberAddresses,
  completeResponseLength,
  parseResponse,
  textPart,
  internalDate,
  decodeHeader,
  decodePart,
  excerptOf,
  htmlToText,
  _cache: cache,
  _inflight: inflight,
}
