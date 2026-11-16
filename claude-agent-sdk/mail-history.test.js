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

beforeEach(() => mh._cache.clear())

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
    assert.ok(cmds.includes(`UID SEARCH X-GM-RAW "{from:${MEMBER} to:${MEMBER} cc:${MEMBER}} after:2024/11/16"`))
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

test('a refused login makes the whole history unavailable, says nothing about the member, and is not kept', async () => {
  const fake = await fakeServer({ 'support@example.org': SUPPORT_MAIL, 'info@example.org': INFO_MAIL }, { refuse: 'info@example.org' })
  const logs = []
  try {
    const deps = { env: ENV_BOTH, select: selectFor([MEMBER]), connect: fake.connect, now: () => NOW, log: (s) => logs.push(s) }
    const r = await mh.mailHistoryFor(42, deps)
    assert.deepStrictEqual(r, { available: false, reason: mh.REASON_UNREADABLE, entries: [] })
    assert.ok(logs.length === 1 && !logs[0].includes(MEMBER) && !logs[0].includes('42'))
    await mh.mailHistoryFor(42, deps)
    assert.strictEqual(fake.connections(), 4)
  } finally {
    await fake.close()
  }
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
  // A bracket with no end does not loop.
  assert.deepStrictEqual(mh.parseResponse('* OK [ALERT broken\r\n'), ['*', 'OK', '[ALERT broken'])
})

test('the text part is the plain one, else the html one, and an attached text file is not used', () => {
  const plainHtml = [['text', 'plain', ['charset', 'iso-8859-1'], null, null, 'quoted-printable'], ['text', 'html', null, null, null, '7bit'], 'alternative']
  assert.deepStrictEqual(mh.textPart(plainHtml), { part: '1', subtype: 'plain', charset: 'iso-8859-1', encoding: 'quoted-printable' })
  const mixed = [[['text', 'html', null, null, null, 'base64'], 'alternative'], ['application', 'pdf'], 'mixed']
  assert.deepStrictEqual(mh.textPart(mixed), { part: '1.1', subtype: 'html', charset: null, encoding: 'base64' })
  const attached = [['image', 'png'], ['text', 'plain', null, null, null, '7bit', 10, 1, null, ['attachment', null]], 'mixed']
  assert.strictEqual(mh.textPart(attached), null)
  assert.deepStrictEqual(mh.textPart(['text', 'plain', null, null, null, '7bit']).part, '1')
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
})
