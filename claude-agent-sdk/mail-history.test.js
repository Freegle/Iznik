'use strict'

// The past-mail reader. A fake IMAP server on a local socket plays Gmail, so the whole exchange runs:
// login, finding the All Mail folder, the search, the fetches and the logout. Every command it is sent
// is recorded, so the tests can check that nothing is ever written or marked read.

const { test, beforeEach } = require('node:test')
const assert = require('node:assert')
const net = require('node:net')
const mh = require('./mail-history')

const NOW = Date.UTC(2026, 10, 16, 12, 0, 0)
const ENV_BOTH = { SUPPORT_MAIL_USER: 'support@example.org', SUPPORT_MAIL_PASS: 'abcd efgh ijkl mnop', INFO_MAIL_USER: 'info@example.org', INFO_MAIL_PASS: 'qrstuvwxyzabcdef' }
const ENV_SUPPORT = { SUPPORT_MAIL_USER: 'support@example.org', SUPPORT_MAIL_PASS: 'abcdefghijklmnop' }

const lit = (s) => `{${Buffer.byteLength(s, 'latin1')}}\r\n${s}`
const b64 = (s) => Buffer.from(s, 'utf8').toString('base64')

// One mail as the fake server holds it: its FETCH metadata and the body of each part.
function mail({ uid, date, subject, from, messageId, structure, parts }) {
  const envelope = `("${date}" ${subject} ((NIL NIL "${from.split('@')[0]}" "${from.split('@')[1]}")) NIL NIL NIL NIL NIL NIL "${messageId}")`
  return { uid, meta: `(UID ${uid} INTERNALDATE "${date}" ENVELOPE ${envelope} BODYSTRUCTURE ${structure})`, parts }
}

const PLAIN = (enc = '7BIT', charset = 'utf-8') => `("TEXT" "PLAIN" ("CHARSET" "${charset}") NIL NIL "${enc}" 100 5 NIL NIL NIL NIL)`
const HTML = `("TEXT" "HTML" ("CHARSET" "utf-8") NIL NIL "7BIT" 100 5 NIL NIL NIL NIL)`

function fakeServer(mailboxes, opts = {}) {
  const commands = []
  const server = net.createServer((sock) => {
    let who = null
    let buf = ''
    sock.write('* OK Gimap ready\r\n')
    sock.on('data', (d) => {
      buf += d.toString('latin1')
      let eol
      while ((eol = buf.indexOf('\r\n')) >= 0) {
        const line = buf.slice(0, eol)
        buf = buf.slice(eol + 2)
        const [tag, ...rest] = line.split(' ')
        const cmd = rest.join(' ')
        commands.push({ who, cmd })
        const ok = (extra = '') => sock.write(`${extra}${tag} OK done\r\n`)
        if (/^LOGIN /.test(cmd)) {
          const user = /^LOGIN "([^"]+)"/.exec(cmd)[1]
          if (opts.refuse === user) {
            sock.write(`${tag} NO [AUTHENTICATIONFAILED] Invalid credentials (Failure)\r\n`)
            continue
          }
          who = user
          ok()
        } else if (/^LIST /.test(cmd)) {
          ok('* LIST (\\HasNoChildren) "/" "INBOX"\r\n* LIST (\\All \\HasNoChildren) "/" "[Google Mail]/All Mail"\r\n')
        } else if (/^EXAMINE /.test(cmd)) {
          ok('* 3 EXISTS\r\n* OK [UIDVALIDITY 1] UIDs valid.\r\n')
        } else if (/^UID SEARCH /.test(cmd)) {
          if (opts.hang === who) continue
          ok(`* SEARCH ${mailboxes[who].map((m) => m.uid).join(' ')}\r\n`)
        } else if (/^UID FETCH [\d,]+ \(UID INTERNALDATE/.test(cmd)) {
          const wanted = cmd.split(' ')[2].split(',').map(Number)
          ok(mailboxes[who].filter((m) => wanted.includes(m.uid)).map((m, k) => `* ${k + 1} FETCH ${m.meta}\r\n`).join(''))
        } else if (/^UID FETCH \d+ \(BODY\.PEEK\[/.test(cmd)) {
          const uid = Number(cmd.split(' ')[2])
          if (opts.refuseBody === uid) {
            sock.write(`${tag} NO Some messages could not be FETCHed (Failure)\r\n`)
            continue
          }
          const part = /BODY\.PEEK\[([^\]]+)\]/.exec(cmd)[1]
          const body = mailboxes[who].find((m) => m.uid === uid).parts[part]
          ok(`* 1 FETCH (UID ${uid} BODY[${part}]<0> ${lit(body)})\r\n`)
        } else if (/^LOGOUT/.test(cmd)) {
          sock.write('* BYE\r\n')
          ok()
          sock.end()
        } else sock.write(`${tag} BAD unknown\r\n`)
      }
    })
    sock.on('error', () => {})
  })
  return new Promise((resolve) =>
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address()
      let connections = 0
      resolve({
        commands,
        connect: () => {
          connections++
          return net.connect(port, '127.0.0.1')
        },
        connections: () => connections,
        close: () => new Promise((r) => server.close(r)),
      })
    })
  )
}

const MEMBER = 'someone.1975@example.com'
const selectFor = (emails) => async () => emails.map((email) => ({ email }))

const SUPPORT_MAIL = [
  mail({
    uid: 7,
    date: '10-Sep-2026 09:15:00 +0100',
    subject: '"Can\'t log in"',
    from: MEMBER,
    messageId: '<m1@example.com>',
    structure: `(${PLAIN()}${HTML} "ALTERNATIVE" ("BOUNDARY" "b1") NIL NIL NIL)`,
    parts: { 1: 'Hello, I cannot log in since Tuesday.\r\n\r\nOn Mon, 7 Sep 2026 at 10:00, Freegle <support@example.org>\r\nwrote:\r\n> old text\r\n', 2: '<p>html</p>' },
  }),
  mail({
    uid: 9,
    date: '11-Sep-2026 10:00:00 +0000',
    subject: '"=?UTF-8?B?' + b64('Re: Can’t log in') + '?="',
    from: 'volunteer@example.org',
    messageId: '<m2@example.org>',
    structure: PLAIN('BASE64'),
    parts: { 1: b64('Please try resetting your password.\n\n--\nThe support team\n') },
  }),
]
const INFO_MAIL = [
  // Sent to both mailboxes: it appears once.
  mail({ uid: 3, date: '10-Sep-2026 09:15:00 +0100', subject: '"Can\'t log in"', from: MEMBER, messageId: '<m1@example.com>', structure: PLAIN(), parts: { 1: 'Hello, I cannot log in since Tuesday.' } }),
  mail({
    uid: 4,
    date: '01-Mar-2026 08:00:00 +0000',
    subject: '"Donation"',
    from: MEMBER,
    messageId: '<m3@example.com>',
    structure: HTML,
    parts: { 1: '<html><style>p{}</style><p>Here is &pound;5 &amp; thanks</p><blockquote>quoted</blockquote></html>' },
  }),
]

beforeEach(() => {
  mh._cache.clear()
  mh._inflight.clear()
})

test('without mailbox passwords the history is not set up, and a member is not needed to say so', async () => {
  const r = await mh.mailHistoryFor(42, { env: {}, select: () => assert.fail('no query') })
  assert.deepStrictEqual(r, { available: false, reason: mh.REASON_NOT_SET_UP, entries: [] })
})

test('the passwords are read without their spaces, and a mailbox missing either half is left out', () => {
  assert.deepStrictEqual(mh.mailboxesFromEnv(ENV_BOTH).map((m) => [m.name, m.pass]), [['support', 'abcdefghijklmnop'], ['info', 'qrstuvwxyzabcdef']])
  assert.deepStrictEqual(mh.mailboxesFromEnv({ INFO_MAIL_USER: 'x@example.org' }), [])
})

test('no member, or a member with only our own addresses, is not available with a plain reason', async () => {
  assert.strictEqual((await mh.mailHistoryFor(null, { env: ENV_SUPPORT })).reason, mh.REASON_NO_MEMBER)
  const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor(['a.volunteer@ilovefreegle.org', 'x@users.ilovefreegle.org', 'not an address']) })
  assert.deepStrictEqual(r, { available: false, reason: mh.REASON_NO_ADDRESS, entries: [] })
})

test('the member addresses are bounded, lower-cased, deduplicated, and asked for preferred first', async () => {
  let sql = ''
  const emails = ['Someone.1975@Example.com', 'someone.1975@example.com', ...Array.from({ length: 12 }, (_, k) => `a${k}@example.net`)]
  const got = await mh.memberAddresses(42, async (q) => {
    sql = q
    return emails.map((email) => ({ email }))
  })
  assert.match(sql, /WHERE userid=42 ORDER BY preferred DESC/)
  assert.strictEqual(got.length, 8)
  assert.strictEqual(got[0], 'someone.1975@example.com')
  assert.strictEqual(new Set(got).size, 8)
})

test('both mailboxes are read only, merged newest first, and a mail in both appears once', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL, 'info@example.org': INFO_MAIL })
  try {
    const r = await mh.mailHistoryFor(42, { env: ENV_BOTH, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} })
    assert.strictEqual(r.available, true)
    assert.deepStrictEqual(r.entries, [
      { date: '2026-09-11 10:00', mailbox: 'support', direction: 'out', subject: 'Re: Can’t log in', excerpt: 'Please try resetting your password.' },
      { date: '2026-09-10 08:15', mailbox: 'support', direction: 'in', subject: "Can't log in", excerpt: 'Hello, I cannot log in since Tuesday.' },
      { date: '2026-03-01 08:00', mailbox: 'info', direction: 'in', subject: 'Donation', excerpt: 'Here is £5 & thanks' },
    ])

    const cmds = fake.commands.map((c) => c.cmd)
    // Read only: the folder is EXAMINEd, never SELECTed, and bodies are PEEKed so nothing is marked read.
    assert.ok(cmds.some((c) => c === 'EXAMINE "[Google Mail]/All Mail"'))
    assert.ok(!cmds.some((c) => /^(SELECT|STORE|UID STORE|COPY|UID COPY|MOVE|UID MOVE|EXPUNGE|APPEND|DELETE)\b/.test(c)))
    assert.ok(cmds.filter((c) => /^UID FETCH/.test(c)).every((c) => !/BODY\[/.test(c)))
    // Two years back, on from, to and cc for each address.
    // A day early, because Gmail reads after: in Pacific time.
    assert.ok(cmds.includes(`UID SEARCH X-GM-RAW "{from:${MEMBER} to:${MEMBER} cc:${MEMBER}} after:2024/11/15"`))
    // The password is sent without its spaces.
    assert.ok(cmds.includes('LOGIN "support@example.org" "abcdefghijklmnop"'))
  } finally {
    await fake.close()
  }
})

test('a result is kept per member, so a second read does not go to the mailboxes', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL })
  try {
    const deps = { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} }
    const first = await mh.mailHistoryFor(42, deps)
    const second = await mh.mailHistoryFor(42, deps)
    assert.strictEqual(fake.connections(), 1)
    assert.deepStrictEqual(second, first)
    // After six hours it is read again.
    await mh.mailHistoryFor(42, { ...deps, now: () => NOW + 6 * 60 * 60 * 1000 + 1 })
    assert.strictEqual(fake.connections(), 2)
  } finally {
    await fake.close()
  }
})

test('one mailbox refusing its login keeps the other mailbox, says which was not read, and is not kept', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL, 'info@example.org': INFO_MAIL }, { refuse: 'info@example.org' })
  const logs = []
  try {
    const deps = { env: ENV_BOTH, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: (s) => logs.push(s) }
    const r = await mh.mailHistoryFor(42, deps)
    assert.strictEqual(r.available, true)
    assert.strictEqual(r.reason, 'the info mailbox could not be read just now, so only support mail is listed')
    assert.deepStrictEqual(r.entries.map((e) => e.mailbox), ['support', 'support'])
    // The log names the mailbox and a fixed message, not the member.
    assert.deepStrictEqual(logs, ['[MailHistory] the info mailbox could not be read: LOGIN refused'])
    await mh.mailHistoryFor(42, deps)
    assert.strictEqual(fake.connections(), 4)
  } finally {
    await fake.close()
  }
})

test('both mailboxes refusing makes the history unavailable', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL }, { refuse: 'support@example.org' })
  try {
    const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} })
    assert.deepStrictEqual(r, { available: false, reason: mh.REASON_UNREADABLE, entries: [] })
  } finally {
    await fake.close()
  }
})

test('two callers asking at once for the same member share one read', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL })
  try {
    const deps = { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} }
    const [a, b] = await Promise.all([mh.mailHistoryFor(42, deps), mh.mailHistoryFor(42, deps)])
    assert.strictEqual(fake.connections(), 1)
    assert.deepStrictEqual(a, b)
    assert.strictEqual(mh._inflight.size, 0)
  } finally {
    await fake.close()
  }
})

test('a caller that stops waiting gets "not read" at once, and the shared read goes on', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL }, { hang: 'support@example.org' })
  try {
    const ac = new AbortController()
    const started = Date.now()
    const p = mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {}, timeoutMs: 1000, signal: ac.signal })
    setTimeout(() => ac.abort(), 30)
    assert.deepStrictEqual(await p, { available: false, reason: mh.REASON_UNREADABLE, entries: [] })
    assert.ok(Date.now() - started < 500)
    assert.strictEqual(mh._inflight.size, 1)
    const already = new AbortController()
    already.abort()
    assert.strictEqual((await mh.mailHistoryFor(42, { env: ENV_SUPPORT, signal: already.signal })).reason, mh.REASON_UNREADABLE)
    // The shared read ends at its own timeout and clears itself.
    await mh._inflight.get(42)
    assert.strictEqual(mh._inflight.size, 0)
  } finally {
    await fake.close()
  }
})

test('a mail whose body the server refuses is listed without an excerpt; a +tag address counts as the member', async () => {
  const tagged = mail({ uid: 12, date: '12-Sep-2026 10:00:00 +0000', subject: '"Tagged"', from: 'someone.1975+freegle@example.com', messageId: '<m4@example.com>', structure: PLAIN(), parts: { 1: 'From my tagged address.' } })
  const fake = await fakeServer({ 'support@example.org': [...SUPPORT_MAIL, tagged] }, { refuseBody: 7 })
  try {
    const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} })
    assert.deepStrictEqual(
      r.entries.map((e) => [e.subject, e.direction, e.excerpt]),
      [
        ['Tagged', 'in', 'From my tagged address.'],
        ['Re: Can’t log in', 'out', 'Please try resetting your password.'],
        ["Can't log in", 'in', ''],
      ]
    )
  } finally {
    await fake.close()
  }
})

test('a member id given as text shares the number\'s read and kept result', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL })
  try {
    const deps = { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {} }
    await Promise.all([mh.mailHistoryFor(42, deps), mh.mailHistoryFor('42', deps)])
    await mh.mailHistoryFor('42', deps)
    assert.strictEqual(fake.connections(), 1)
    for (const bad of ['x', 1.5, Infinity, '1e21', true, -3]) assert.strictEqual((await mh.mailHistoryFor(bad, deps)).reason, mh.REASON_NO_MEMBER)
  } finally {
    await fake.close()
  }
})

test('a read stuck before any mailbox (the database) still ends, and the next caller starts afresh', async () => {
  const stuck = () => new Promise(() => {})
  const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: stuck, totalTimeoutMs: 50, log: () => {} })
  assert.strictEqual(r.reason, mh.REASON_UNREADABLE)
  assert.strictEqual(mh._inflight.size, 0)
})

test('a read stopped at the overall bound closes its connection and keeps nothing', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL }, { hang: 'support@example.org' })
  try {
    let closed = false
    const connect = () => {
      const s = fake.connect()
      s.on('close', () => (closed = true))
      return s
    }
    const started = Date.now()
    const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect, now: () => NOW, log: () => {}, timeoutMs: 10000, totalTimeoutMs: 100 })
    assert.strictEqual(r.reason, mh.REASON_UNREADABLE)
    assert.ok(Date.now() - started < 2000)
    await new Promise((res) => setTimeout(res, 50))
    assert.strictEqual(closed, true)
    assert.strictEqual(mh._cache.size, 0)
  } finally {
    await fake.close()
  }
})

test('kept results past their six hours are dropped on the next call', async () => {
  mh._cache.set(99, { at: NOW - 7 * 60 * 60 * 1000, result: {} })
  await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([]), now: () => NOW })
  assert.ok(!mh._cache.has(99))
})

test('a mailbox that stops answering times out as unavailable', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL }, { hang: 'support@example.org' })
  try {
    const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: () => {}, timeoutMs: 200 })
    assert.strictEqual(r.reason, mh.REASON_UNREADABLE)
  } finally {
    await fake.close()
  }
})

test('a member address query that fails is unavailable, not an error', async () => {
  const r = await mh.mailHistoryFor(42, { env: ENV_SUPPORT, select: async () => { throw new Error('db down') }, log: () => {} })
  assert.strictEqual(r.reason, mh.REASON_UNREADABLE)
})

test('a response is complete only once its literals have all arrived', () => {
  const full = `* 1 FETCH (BODY[1] ${lit('ab\r\ncd')})\r\n`
  assert.strictEqual(mh.completeResponseLength(full.slice(0, -4)), -1)
  assert.strictEqual(mh.completeResponseLength(full + 'a1 OK'), full.length)
  assert.strictEqual(mh.completeResponseLength('a1 OK done\r\n* next'), 12)
})

test('responses parse into lists, strings, literals and NIL, with [sections] kept in atoms', () => {
  assert.deepStrictEqual(mh.parseResponse(`* 2 FETCH (UID 5 BODY[1.2]<0> ${lit('x y')} ENVELOPE (NIL "a \\"q\\"" ((NIL NIL "u" "h"))))\r\n`), [
    '*',
    '2',
    'FETCH',
    ['UID', '5', 'BODY[1.2]<0>', 'x y', 'ENVELOPE', [null, 'a "q"', [[null, null, 'u', 'h']]]],
  ])
  // A bracket with no end, a bare \r and a count that is not a literal all end the parse.
  assert.deepStrictEqual(mh.parseResponse('* OK [ALERT broken\r\n'), ['*', 'OK', '[ALERT broken'])
  assert.deepStrictEqual(mh.parseResponse('* OK a\rb\r\n'), ['*', 'OK', 'a', 'b'])
  assert.deepStrictEqual(mh.parseResponse('* 1 FETCH (X {12\r\n'), ['*', '1', 'FETCH', ['X', '{12\r\n']])
  assert.deepStrictEqual(mh.parseResponse('* "ends with \\'), ['*', 'ends with '])
})

test('the text part is the plain one, else the html one, and an attached text file is not used', () => {
  const plainHtml = [['text', 'plain', ['charset', 'iso-8859-1'], null, null, 'quoted-printable'], ['text', 'html', null, null, null, '7bit'], 'alternative']
  assert.deepStrictEqual(mh.textPart(plainHtml), { part: '1', subtype: 'plain', charset: 'iso-8859-1', encoding: 'quoted-printable' })
  const mixed = [[['text', 'html', null, null, null, 'base64'], 'alternative'], ['application', 'pdf'], 'mixed']
  assert.deepStrictEqual(mh.textPart(mixed), { part: '1.1', subtype: 'html', charset: null, encoding: 'base64' })
  const attached = [['image', 'png'], ['text', 'plain', null, null, null, '7bit', 10, 1, null, ['attachment', null]], 'mixed']
  assert.strictEqual(mh.textPart(attached), null)
  assert.deepStrictEqual(mh.textPart(['text', 'plain', null, null, null, '7bit']).part, '1')
  // A reply with a forwarded mail attached: the reply's own plain text, not the attached mail's.
  const forwarded = [[['text', 'plain', null, null, null, '7bit'], ['text', 'html', null, null, null, '7bit'], 'alternative'], ['message', 'rfc822', null, null, null, '7bit'], 'mixed']
  assert.strictEqual(mh.textPart(forwarded).part, '1.1')
})

test('dates, headers and bodies decode', () => {
  assert.strictEqual(new Date(mh.internalDate('17-Jul-2026 02:44:25 -0700')).toISOString(), '2026-07-17T09:44:25.000Z')
  assert.strictEqual(mh.internalDate('nonsense'), 0)
  assert.strictEqual(mh.decodeHeader('=?utf-8?Q?Caf=C3=A9_open?= =?utf-8?B?' + b64(' – yes') + '?='), 'Café open – yes')
  assert.strictEqual(mh.decodeHeader(Buffer.from('Raw café', 'utf8').toString('latin1')), 'Raw café')
  assert.strictEqual(mh.decodeHeader(null), '')
  // A base64 body cut part way through a group still decodes what came before.
  assert.strictEqual(mh.decodePart(b64('hello world').slice(0, 10), 'base64', 'utf-8'), 'hello ')
  assert.strictEqual(mh.decodePart('caf=E9 soft=\r\nbreak=', 'quoted-printable', 'iso-8859-1'), 'café softbreak')
  assert.strictEqual(mh.decodePart('plain', '7bit', 'no-such-charset'), 'plain')
})

test('the excerpt is the new text only: quotes, reply lines, forwards and signatures go', () => {
  assert.strictEqual(mh.excerptOf('Thanks!\n\nOn Tue, 1 Sep 2026, A <a@b.c> wrote:\n> earlier'), 'Thanks!')
  assert.strictEqual(mh.excerptOf('Yes.\nOn Tue, 1 Sep 2026 at 10:00, A <\na@b.c> wrote:\nold'), 'Yes.')
  assert.strictEqual(mh.excerptOf('See below\n---------- Forwarded message ---------\nFrom: x'), 'See below')
  assert.strictEqual(mh.excerptOf('Hi\nFrom: someone\nSent: today'), 'Hi')
  assert.strictEqual(mh.excerptOf('Body\n-- \nSig'), 'Body')
  assert.strictEqual(mh.excerptOf('Body\nSent from my phone'), 'Body')
  assert.strictEqual(mh.excerptOf('x'.repeat(400)).length, 300)
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<p>a&nbsp;&lt;b&gt;&#8217;&#x2019;&zzz;&#0;</p><script>no</script>')), 'a <b>’’&zzz;')
  // Cut short inside its style block, or inside a tag, the HTML gives no style rules as text.
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<html><head><style>p{color:red}' + ' .x{margin:0}'.repeat(50))), '')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<p>Hi there</p><style>a{color:')), 'Hi there')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<p>Hi</p><a href="https://exa')), 'Hi')
  // A quote inside a quote goes whole, and a bare < in the text is kept.
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<p>New</p><blockquote>outer<blockquote>inner</blockquote>outer tail</blockquote><p>end</p>')), 'New end')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<p>a < 5 deal</p>')), 'a < 5 deal')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<header>kept</header>')), 'kept')
  // Comments, Outlook's conditional blocks and a > inside an attribute leave no text behind.
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<!-- a > secret -->visible')), 'visible')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<!--[if gte mso 9]><xml>x>y</xml><![endif]-->Body')), 'Body')
  assert.strictEqual(mh.excerptOf(mh.htmlToText('<a title="x>y">link</a>')), 'link')
})
