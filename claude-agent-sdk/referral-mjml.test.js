'use strict'

const { test } = require('node:test')
const assert = require('node:assert')
const {
  buildReferralMjml,
  buildReferralText,
  referralSubject,
  formatReferralRef,
  REF_ALPHABET,
  escapeHtml,
  stripUnsafeHtml,
  htmlToText,
  formatTokens,
  formatLastSeen,
  deviceTitle,
  freshnessBadge,
} = require('./referral-mjml')

const REFERRAL = {
  ref: 'SR-4F2QW',
  member: { id: 35909200, displayname: 'Edward Hibbert', email: 'edward@ehibbert.org.uk' },
  referredBy: { id: 42, name: 'Jeni', email: 'jeni@example.org' },
  note: 'Second one this week - can we stop showing the badge to the sender?',
  deviceSummary: {
    lastaccess: '2026-08-05T08:00:00Z',
    devices: [
      {
        isApp: true,
        os: 'Android',
        appVersion: '3.2.28',
        freshness: 'current',
        sessions: 21,
        lastSeen: '2026-08-05T08:00:00Z',
        screen: { w: 384, h: 832 },
        windows: [{ w: 384, h: 749, sessions: 20 }],
      },
      {
        isApp: false,
        os: 'Windows',
        browser: 'Chrome',
        browserVersion: '150',
        freshness: 'stale',
        sessions: 25,
        lastSeen: '2026-08-04T21:00:00Z',
        windows: [],
      },
    ],
  },
  messages: [
    { role: 'user', content: 'Why is his reply stuck on waiting to send?' },
    {
      role: 'assistant',
      content: 'It is held by rippling.',
      html: '<p>It is <strong>held by rippling</strong>.</p>',
      costUsd: 0.1832,
      usage: { inputTokens: 48213, outputTokens: 946 },
    },
  ],
  totals: { costUsd: 0.2546, inputTokens: 69619, outputTokens: 1334 },
  generatedAt: '2026-08-05T15:02:00Z',
  modToolsUrl: 'https://modtools.org',
}

const NOW = Date.parse('2026-08-05T16:00:00Z')

test('formatReferralRef is short, fixed-length and unambiguous', () => {
  const ref = formatReferralRef([0, 1, 2, 3, 4])
  // Short enough to lead a subject line without crowding out the subject.
  assert.match(ref, /^SR-[0-9A-Z]{5}$/)
  assert.equal(ref.length, 8)
  // No characters that get misread when someone quotes a reference back.
  assert.ok(!/[01OIL]/.test(ref.slice(3)), `tail should avoid 0/1/O/I/L: ${ref}`)
  for (const c of REF_ALPHABET) assert.ok(!'01OIL'.includes(c))
})

test('formatReferralRef is deterministic for given bytes and varies with them', () => {
  assert.equal(formatReferralRef([5, 6, 7, 8, 9]), formatReferralRef([5, 6, 7, 8, 9]))
  assert.notEqual(formatReferralRef([5, 6, 7, 8, 9]), formatReferralRef([9, 10, 11, 12, 13]))
})

test('formatReferralRef copes with short/absent entropy rather than throwing', () => {
  assert.match(formatReferralRef([]), /^SR-.{5}$/)
})

test('referralSubject leads with the reference, then who and what', () => {
  const s = referralSubject(REFERRAL)
  assert.ok(s.startsWith('[SR-4F2QW] '), s)
  assert.ok(s.includes('Edward Hibbert'))
  assert.ok(s.includes('(35909200)'))
  assert.ok(s.includes('Why is his reply stuck'))
})

test('referralSubject still works with no reference and no conversation', () => {
  assert.equal(
    referralSubject({ member: { displayname: 'Sam', id: 7 }, messages: [], note: 'odd bounce' }),
    'Support referral: Sam (7) - odd bounce'
  )
  assert.equal(referralSubject({ member: {}, messages: [] }), 'Support referral: a member')
})

test('referralSubject truncates a long question instead of running away', () => {
  const s = referralSubject({
    ...REFERRAL,
    messages: [{ role: 'user', content: 'x'.repeat(500) }],
  })
  assert.ok(s.length < 200, `subject was ${s.length} chars`)
  assert.ok(s.endsWith('…'))
})

test('escapeHtml neutralises member-supplied angle brackets and quotes', () => {
  assert.equal(escapeHtml('<b>&"\'</b>'), '&lt;b&gt;&amp;&quot;&#39;&lt;/b&gt;')
  assert.equal(escapeHtml(null), '')
  assert.equal(escapeHtml(0), '0')
})

test('stripUnsafeHtml removes active content but keeps the formatting', () => {
  const dirty =
    '<p>Hello <strong>there</strong></p>' +
    '<script>alert(1)</script>' +
    '<img src="x" onerror="alert(2)" />' +
    '<a href="javascript:alert(3)">click</a>' +
    '<iframe src="http://evil"></iframe>'
  const clean = stripUnsafeHtml(dirty)
  assert.ok(clean.includes('<strong>there</strong>'), 'keeps formatting')
  assert.ok(!/script/i.test(clean))
  assert.ok(!/onerror/i.test(clean))
  assert.ok(!/iframe/i.test(clean))
  assert.ok(!/javascript:/i.test(clean))
})

test('stripUnsafeHtml handles unquoted and single-quoted handlers', () => {
  assert.ok(!/onclick/i.test(stripUnsafeHtml('<div onclick=doEvil()>x</div>')))
  assert.ok(!/onload/i.test(stripUnsafeHtml("<body onload='doEvil()'>x</body>")))
})

test('htmlToText produces a readable plain-text fallback', () => {
  const t = htmlToText('<p>One</p><ul><li>Two</li><li>Three</li></ul><p>Four &amp; five</p>')
  assert.ok(t.includes('One'))
  assert.ok(t.includes('- Two'))
  assert.ok(t.includes('Four & five'))
  assert.ok(!t.includes('<'))
})

test('formatTokens is empty when there is nothing to report', () => {
  assert.equal(formatTokens(null), '')
  assert.equal(formatTokens({ inputTokens: 0, outputTokens: 0 }), '')
  assert.equal(formatTokens({ inputTokens: 1234, outputTokens: 5 }), '1,234 in / 5 out tokens')
})

test('formatLastSeen matches the on-screen relative style', () => {
  assert.equal(formatLastSeen('2026-08-05T15:30:00Z', NOW), '30m ago')
  assert.equal(formatLastSeen('2026-08-05T08:00:00Z', NOW), '8h ago')
  assert.equal(formatLastSeen('2026-08-03T16:00:00Z', NOW), '2d ago')
  assert.equal(formatLastSeen(null, NOW), '')
  assert.equal(formatLastSeen('nonsense', NOW), '')
})

test('deviceTitle and freshnessBadge read like the on-screen cards', () => {
  assert.equal(deviceTitle({ isApp: true, appVersion: '3.2.28' }), 'App 3.2.28')
  assert.equal(deviceTitle({ isApp: true }), 'App')
  assert.equal(deviceTitle({ browser: 'Chrome', browserVersion: '150' }), 'Chrome 150')
  assert.equal(freshnessBadge({ freshness: 'current' }).text, 'up to date')
  assert.equal(freshnessBadge({ freshness: 'stale', isApp: true }).text, 'update the app')
  assert.equal(freshnessBadge({ freshness: 'stale', isApp: false }).text, 'out of date')
  assert.equal(freshnessBadge({ freshness: 'unknown' }), null)
})

test('buildReferralMjml carries everything that was on the screen', () => {
  const m = buildReferralMjml(REFERRAL, NOW)
  assert.ok(m.startsWith('<mjml>') && m.trimEnd().endsWith('</mjml>'))
  // Reference, referrer and their words.
  assert.ok(m.includes('SR-4F2QW'))
  assert.ok(m.includes('Referred to geeks by Jeni'))
  assert.ok(m.includes("Why they're referring it"))
  assert.ok(m.includes('Second one this week'))
  // Member identity and a way back into ModTools.
  assert.ok(m.includes('Edward Hibbert'))
  assert.ok(m.includes('35909200'))
  assert.ok(m.includes('https://modtools.org/support/35909200'))
  // Devices.
  assert.ok(m.includes('App 3.2.28'))
  assert.ok(m.includes('up to date'))
  assert.ok(m.includes('Chrome 150'))
  assert.ok(m.includes('out of date'))
  assert.ok(m.includes('21 sessions'))
  // The conversation, both sides, with the rendered answer.
  assert.ok(m.includes('Why is his reply stuck on waiting to send?'))
  assert.ok(m.includes('<strong>held by rippling</strong>'))
  assert.ok(m.includes('AI Assistant'))
  // Effort, but never money: the helper runs on a subscription, so a dollar
  // figure would be a notional price nobody is charged.
  assert.ok(m.includes('69,619 in / 1,334 out tokens'))
  assert.ok(m.includes('48,213 in / 946 out tokens'))
  assert.ok(!m.includes('$'), 'no money anywhere in the email')
})

test('buildReferralMjml uses the helper bubble colours for each side', () => {
  const m = buildReferralMjml(REFERRAL, NOW)
  assert.ok(m.includes('border="2px solid #28a745"'), 'volunteer bubble is green')
  assert.ok(m.includes('border="2px solid #007bff"'), 'assistant bubble is blue')
})

test('buildReferralMjml escapes member-supplied text outside the answer HTML', () => {
  const m = buildReferralMjml(
    {
      ...REFERRAL,
      member: { id: 1, displayname: '<script>alert(1)</script>', email: 'a@b.c' },
      note: '<img src=x onerror=alert(2)>',
    },
    NOW
  )
  // Escaped, not removed: this is text a volunteer typed, so the geeks should
  // see exactly what it said - just as inert text, never as live markup.
  assert.ok(!m.includes('<script>alert(1)</script>'))
  assert.ok(m.includes('&lt;script&gt;'))
  assert.ok(!m.includes('<img src=x'), 'no live img tag from the note')
  assert.ok(m.includes('&lt;img src=x onerror=alert(2)&gt;'))
})

test('buildReferralMjml strips active content from the answer HTML', () => {
  const m = buildReferralMjml(
    {
      ...REFERRAL,
      messages: [{ role: 'assistant', content: 'x', html: '<p>ok</p><script>alert(1)</script>' }],
    },
    NOW
  )
  assert.ok(m.includes('<p>ok</p>'))
  assert.ok(!/<script/i.test(m))
})

test('buildReferralMjml renders a plain-content message with no HTML', () => {
  const m = buildReferralMjml(
    { ...REFERRAL, messages: [{ role: 'user', content: 'line one\nline two' }] },
    NOW
  )
  assert.ok(m.includes('line one<br />line two'))
})

test('buildReferralMjml survives a referral with nothing optional set', () => {
  const m = buildReferralMjml({ messages: [{ role: 'user', content: 'help' }] }, NOW)
  assert.ok(m.startsWith('<mjml>'))
  assert.ok(m.includes('No referral text was added.'))
  assert.ok(m.includes('help'))
})

test('buildReferralMjml says why a device panel is empty rather than omitting it', () => {
  const m = buildReferralMjml(
    {
      ...REFERRAL,
      deviceSummary: { devices: [], lastApiActivity: '2026-08-05T14:00:00Z' },
    },
    NOW
  )
  assert.ok(m.includes('Recent devices'))
  assert.ok(m.includes('sends no telemetry'))
})

test('buildReferralMjml pads a single device card so the row still lines up', () => {
  const one = { ...REFERRAL.deviceSummary.devices[0] }
  const m = buildReferralMjml({ ...REFERRAL, deviceSummary: { devices: [one] } }, NOW)
  const cardRow = m.slice(m.indexOf('App 3.2.28') - 800, m.indexOf('App 3.2.28') + 800)
  assert.ok(cardRow.includes('mj-spacer'), 'a filler column keeps the card at half width')
})

test('buildReferralText mirrors the HTML for non-HTML mail readers', () => {
  const t = buildReferralText(REFERRAL)
  assert.ok(t.includes('Reference: SR-4F2QW'))
  assert.ok(t.includes('Jeni'))
  assert.ok(t.includes('Second one this week'))
  assert.ok(t.includes('Edward Hibbert'))
  assert.ok(t.includes('App 3.2.28'))
  assert.ok(t.includes('Why is his reply stuck'))
  assert.ok(t.includes('--- AI Assistant ---'))
  // <jeni@example.org> is deliberate; HTML markup is not.
  assert.ok(!/<\/?(p|div|strong|em|span|ul|li|table)\b/i.test(t), 'no markup leaks into the text part')
})

test('buildReferralText falls back to the HTML when there is no markdown source', () => {
  const t = buildReferralText({
    messages: [{ role: 'assistant', html: '<p>Answer <em>here</em></p>' }],
  })
  assert.ok(t.includes('Answer here'))
})
