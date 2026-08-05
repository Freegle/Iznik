'use strict'

/**
 * MJML for a "Refer to geeks" support referral.
 *
 * A support volunteer investigates a member in the AI Support Helper and then
 * hands the whole thing over to geeks@. The email has to carry everything that
 * was on their screen - which member, what devices, every question and every
 * answer - so the geeks can pick it up without asking them to repeat it.
 *
 * The layout deliberately mirrors the helper's own screen
 * (modtools/components/ModSupportAIAssistant.vue): the same header, the same
 * member chip, the same device cards, and the same green-you / blue-assistant
 * chat bubbles, using that component's colours. Reading the mail should feel
 * like looking at the tool.
 *
 * This module is PURE - no requires at all - so it runs under `node --test`
 * with no node_modules, which is how CI runs the claude-agent-sdk specs. The
 * MJML->HTML compile and the SMTP send live in referral-email.js.
 */

// Component palette (ModSupportAIAssistant.vue).
const C = {
  pageBg: '#e9ecef',
  panelBg: '#f8f9fa',
  white: '#ffffff',
  border: '#dee2e6',
  text: '#212529',
  muted: '#6c757d',
  faint: '#9e9e9e',
  chatBg: '#f5f5f5',
  user: '#28a745',
  assistant: '#007bff',
  chip: '#e9ecef',
  pillBg: '#e7f1ff',
  pillText: '#0d6efd',
  noteBg: '#fff3cd',
  noteBorder: '#ffe69c',
  success: '#198754',
  warning: '#fd7e14',
}

function escapeHtml(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

/**
 * Defence in depth for the one field that is already HTML.
 *
 * Message bodies arrive as the HTML the browser rendered, which the helper
 * produced with DOMPurify.sanitize(marked(...)) - so it has already been
 * through a real sanitiser, and taking it verbatim is what makes the email
 * match the screen exactly. This is the second belt: strip the script/style/
 * frame elements, inline event handlers and javascript: URLs outright, so a
 * malformed or bypassed client can't put active content in an email. It is NOT
 * the primary sanitiser and must not be treated as one.
 */
function stripUnsafeHtml(html) {
  return String(html || '')
    .replace(/<\s*(script|style|iframe|object|embed|link|meta|base)\b[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
    .replace(/<\s*(script|style|iframe|object|embed|link|meta|base)\b[^>]*\/?\s*>/gi, '')
    .replace(/\son[a-z]+\s*=\s*"[^"]*"/gi, '')
    .replace(/\son[a-z]+\s*=\s*'[^']*'/gi, '')
    .replace(/\son[a-z]+\s*=\s*[^\s>]+/gi, '')
    .replace(/(href|src|action)\s*=\s*(["'])\s*javascript:[^"']*\2/gi, '$1="#"')
}

/** Very rough HTML -> text, for the text/plain alternative part only. */
function htmlToText(html) {
  return String(html || '')
    .replace(/<\s*(script|style)\b[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
    .replace(/<\s*br\s*\/?\s*>/gi, '\n')
    .replace(/<\s*\/\s*(p|div|li|h[1-6]|tr|pre)\s*>/gi, '\n')
    .replace(/<\s*li\b[^>]*>/gi, '- ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&amp;/gi, '&')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#39;/gi, "'")
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

// Reference alphabet with the ambiguous characters removed (no 0/O, 1/I/L),
// because these get read aloud, typed into Discourse and quoted in replies.
const REF_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ'

// Five characters is 31^5 = ~28.6M, far more than enough to tell referrals
// apart, and short enough to sit at the front of a subject line without
// crowding out what the referral is actually about.
const REF_LENGTH = 5

/**
 * A short, unique, quotable reference for one referral - "SR-4F2QW".
 *
 * @param {Buffer|Uint8Array|number[]} bytes REF_LENGTH+ random bytes (injected,
 *   so tests are deterministic and the caller owns the entropy source)
 */
function formatReferralRef(bytes) {
  let tail = ''
  for (let i = 0; i < REF_LENGTH; i++) {
    tail += REF_ALPHABET[(bytes[i] || 0) % REF_ALPHABET.length]
  }
  return `SR-${tail}`
}

function truncate(s, n) {
  const t = String(s || '').replace(/\s+/g, ' ').trim()
  return t.length > n ? t.slice(0, n - 1).trimEnd() + '…' : t
}

/** "12,345 in / 678 out tokens", or '' when there is nothing to say. */
function formatTokens(usage) {
  const input = (usage && usage.inputTokens) || 0
  const output = (usage && usage.outputTokens) || 0
  if (!input && !output) return ''
  return `${input.toLocaleString('en-GB')} in / ${output.toLocaleString('en-GB')} out tokens`
}

/** Matches the helper's own "8h ago" style so the mail reads like the screen. */
function formatLastSeen(ts, nowMs = Date.now()) {
  if (!ts) return ''
  const d = new Date(ts)
  if (Number.isNaN(d.getTime())) return ''
  const mins = Math.round((nowMs - d.getTime()) / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.round(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.round(hrs / 24)
  if (days < 7) return `${days}d ago`
  return d.toISOString().slice(0, 10)
}

function formatWhen(iso) {
  const d = new Date(iso || Date.now())
  if (Number.isNaN(d.getTime())) return ''
  return d.toLocaleString('en-GB', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Europe/London',
  })
}

/**
 * Subject line. The tracking reference leads, so a referral can be found,
 * quoted and replied about without anyone having to describe it; then who it is
 * about and what was asked, so a mailbox full of these is still scannable.
 */
function referralSubject(referral) {
  const m = referral.member || {}
  const who = m.displayname || (m.id ? `user ${m.id}` : 'a member')
  const firstQuestion = (referral.messages || []).find((x) => x.role === 'user')
  const about = truncate(firstQuestion ? firstQuestion.content : referral.note, 80)
  const id = m.id ? ` (${m.id})` : ''
  const ref = referral.ref ? `[${referral.ref}] ` : ''
  return about
    ? `${ref}Support referral: ${who}${id} - ${about}`
    : `${ref}Support referral: ${who}${id}`
}

/** The bold identity on a device card - "App 3.2.28" / "Chrome 150". */
function deviceTitle(dev) {
  if (dev.isApp) return dev.appVersion ? `App ${dev.appVersion}` : 'App'
  return dev.browserVersion ? `${dev.browser} ${dev.browserVersion}` : dev.browser || 'Browser'
}

/** The green/amber freshness badge, or null when we can't judge it. */
function freshnessBadge(dev) {
  if (dev.freshness === 'current') {
    return { text: 'up to date', bg: C.success }
  }
  if (dev.freshness === 'stale') {
    return { text: dev.isApp ? 'update the app' : 'out of date', bg: C.warning }
  }
  return null
}

function badgeHtml(badge) {
  if (!badge) return ''
  return (
    ` <span style="display:inline-block;background:${badge.bg};color:#ffffff;` +
    `border-radius:10px;padding:1px 7px;font-size:11px;white-space:nowrap;">` +
    `${escapeHtml(badge.text)}</span>`
  )
}

function deviceCardHtml(dev, nowMs) {
  const pills = (dev.windows || [])
    .map(
      (w) =>
        `<span style="display:inline-block;background:${C.pillBg};color:${C.pillText};` +
        `border-radius:10px;padding:1px 7px;font-size:11px;margin-right:4px;white-space:nowrap;">` +
        `${escapeHtml(w.w)}&times;${escapeHtml(w.h)}</span>`
    )
    .join('')
  const screen = dev.screen
    ? `<span style="color:${C.muted};font-size:11px;">on ${escapeHtml(dev.screen.w)}&times;${escapeHtml(dev.screen.h)}</span>`
    : ''
  const seen = dev.lastSeen ? ` &middot; seen ${escapeHtml(formatLastSeen(dev.lastSeen, nowMs))}` : ''
  const sessions = `${dev.sessions || 0} session${dev.sessions === 1 ? '' : 's'}`

  return (
    `<div style="font-size:13px;">` +
    `<div style="margin-bottom:4px;">` +
    `<strong style="font-size:14px;">${escapeHtml(deviceTitle(dev))}</strong> ` +
    `<span style="color:${C.muted};font-size:12px;">${escapeHtml(dev.os || '')}</span>` +
    badgeHtml(freshnessBadge(dev)) +
    `</div>` +
    (pills || screen ? `<div style="margin-bottom:4px;">${pills}${screen}</div>` : '') +
    `<div style="color:${C.muted};font-size:11px;">${escapeHtml(sessions)}${seen}</div>` +
    `</div>`
  )
}

/**
 * Device cards, laid out two to a row so they stay readable on a phone.
 * Mirrors the helper's "Recent devices (last 7 days)" panel.
 */
function devicesSection(deviceSummary, nowMs) {
  if (!deviceSummary) return ''
  const devices = deviceSummary.devices || []

  const heading =
    `<mj-section background-color="${C.white}" padding="12px 16px 4px 16px">` +
    `<mj-column>` +
    `<mj-text padding="0"><strong>Recent devices</strong> ` +
    `<span style="color:${C.muted};font-weight:normal;font-size:12px;">(last 7 days)</span>` +
    (deviceSummary.lastaccess
      ? `<span style="color:${C.muted};font-size:12px;"> &middot; last active ${escapeHtml(
          formatLastSeen(deviceSummary.lastaccess, nowMs)
        )}</span>`
      : '') +
    `</mj-text>` +
    `</mj-column>` +
    `</mj-section>`

  if (!devices.length) {
    // Say the same thing the screen says, so the geeks aren't left wondering
    // whether the panel was simply missing.
    const why = deviceSummary.lastApiActivity
      ? `Active on the server side ${escapeHtml(
          formatLastSeen(deviceSummary.lastApiActivity, nowMs)
        )}, but their device sends no telemetry - usually an ad/tracker blocker, or an app from before client logging.`
      : 'No device sessions found in the last 7 days.'
    return (
      heading +
      `<mj-section background-color="${C.white}" padding="0 16px 12px 16px">` +
      `<mj-column>` +
      `<mj-text padding="0" font-size="13px" color="${C.muted}">${why}</mj-text>` +
      `</mj-column>` +
      `</mj-section>`
    )
  }

  let out = heading
  for (let i = 0; i < devices.length; i += 2) {
    const row = devices.slice(i, i + 2)
    out +=
      `<mj-section background-color="${C.white}" padding="4px 8px">` +
      row
        .map(
          (dev) =>
            `<mj-column width="50%" background-color="${C.white}" border="1px solid ${C.border}" ` +
            `border-radius="8px" padding="10px 12px" vertical-align="top">` +
            `<mj-text padding="0">${deviceCardHtml(dev, nowMs)}</mj-text>` +
            `</mj-column>`
        )
        .join('') +
      // Keep a single card at half width rather than letting it stretch, so the
      // row reads the same as the two-card rows above it.
      (row.length === 1 ? `<mj-column width="50%"><mj-spacer height="1px" /></mj-column>` : '') +
      `</mj-section>`
  }
  // Close the white panel off before the chat transcript starts.
  out += `<mj-section background-color="${C.white}" padding="0 16px 12px 16px"><mj-column><mj-spacer height="1px" /></mj-column></mj-section>`
  return out
}

/**
 * One chat bubble. The helper right-aligns the volunteer's questions in a
 * green-bordered bubble and left-aligns the AI's answers in a blue one; email
 * has no flexbox, so the offset is an empty column beside the bubble.
 */
function messageSection(msg) {
  const isUser = msg.role === 'user'
  const colour = isUser ? C.user : C.assistant
  const label = isUser ? 'You' : 'AI Assistant'
  const body = msg.html
    ? stripUnsafeHtml(msg.html)
    : `<p style="margin:0;">${escapeHtml(msg.content).replace(/\n/g, '<br />')}</p>`

  // Tokens, never money - see the note in buildReferralMjml.
  const tokens = formatTokens(msg.usage)
  const cost = tokens
    ? `<div style="margin-top:8px;text-align:right;font-size:11px;color:${C.faint};">${escapeHtml(
        tokens
      )}</div>`
    : ''

  const bubble =
    `<mj-column width="85%" background-color="${C.white}" border="2px solid ${colour}" ` +
    `border-radius="8px" padding="10px 14px" vertical-align="top">` +
    `<mj-text padding="0">` +
    `<div style="font-size:12px;color:${colour};margin-bottom:6px;">${label}</div>` +
    `<div class="msg">${body}</div>` +
    cost +
    `</mj-text>` +
    `</mj-column>`
  const spacer = `<mj-column width="15%"><mj-spacer height="1px" /></mj-column>`

  return (
    `<mj-section background-color="${C.chatBg}" padding="6px 12px">` +
    (isUser ? spacer + bubble : bubble + spacer) +
    `</mj-section>`
  )
}

/**
 * Build the whole MJML document for a referral.
 *
 * @param {object} referral
 * @param {object} referral.member          {id, displayname, email}
 * @param {object} referral.referredBy      {id, email, name}
 * @param {string} referral.note            the volunteer's own words (optional)
 * @param {object} referral.deviceSummary   /api/device-summary payload (optional)
 * @param {Array}  referral.messages        [{role, content, html, costUsd, usage}]
 * @param {object} referral.totals          {costUsd, inputTokens, outputTokens}
 * @param {string} referral.generatedAt     ISO timestamp
 * @param {string} referral.modToolsUrl     base ModTools URL for deep links
 * @param {number} nowMs                    injected clock, for tests
 */
function buildReferralMjml(referral, nowMs = Date.now()) {
  const member = referral.member || {}
  const by = referral.referredBy || {}
  const totals = referral.totals || {}
  const messages = referral.messages || []
  const modToolsUrl = (referral.modToolsUrl || 'https://modtools.org').replace(/\/+$/, '')

  // No money anywhere in this email. The helper runs on a Claude subscription,
  // so a per-query dollar figure is a notional list price nobody is charged -
  // showing it to whoever picks the referral up is just misleading. Token
  // counts stay: they say how much work the investigation actually was.
  const totalTokens = formatTokens({
    inputTokens: totals.inputTokens,
    outputTokens: totals.outputTokens,
  })

  const byWho = by.name || by.email || 'a support volunteer'
  const byEmail = by.email ? ` (${by.email})` : ''

  const memberChip =
    `<span style="display:inline-block;background:${C.chip};border-radius:14px;padding:3px 10px;">` +
    `<strong>${escapeHtml(member.displayname || 'Member')}</strong>` +
    (member.id ? `<span style="color:${C.muted};font-size:12px;"> (ID: ${escapeHtml(member.id)})</span>` : '') +
    (member.email ? `<span style="color:${C.muted};"> ${escapeHtml(member.email)}</span>` : '') +
    `</span>`

  return `<mjml>
  <mj-head>
    <mj-title>${escapeHtml(referralSubject(referral))}</mj-title>
    <mj-preview>${escapeHtml(
      truncate(referral.note || 'A support volunteer has referred an AI Support Helper investigation.', 120)
    )}</mj-preview>
    <mj-attributes>
      <mj-all font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif" />
      <mj-text font-size="14px" color="${C.text}" line-height="1.5" padding="0" />
      <mj-section padding="0" />
    </mj-attributes>
    <!-- Inlined, because Gmail drops <style> blocks in some clients and the
         answers are markdown-rendered HTML whose tables, code and quotes are
         unreadable without it. Long code lines WRAP rather than scroll: email
         has no horizontal scrollbar, so overflow would simply be lost. -->
    <mj-style inline="inline"><![CDATA[
      .msg p { margin: 0 0 8px 0; }
      .msg ul, .msg ol { margin: 0 0 8px 0; padding-left: 20px; }
      .msg li { margin-bottom: 2px; }
      .msg h1, .msg h2, .msg h3, .msg h4 { font-size: 15px; margin: 10px 0 6px 0; }
      .msg code { background: ${C.chip}; padding: 1px 4px; font-size: 12px; font-family: Consolas, Monaco, monospace; }
      .msg pre { background: ${C.chip}; padding: 8px; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
      .msg pre code { background: none; padding: 0; }
      .msg table { border-collapse: collapse; font-size: 12px; }
      .msg th, .msg td { border: 1px solid ${C.border}; padding: 4px 8px; text-align: left; }
      .msg th { background: ${C.panelBg}; }
      .msg blockquote { margin: 0 0 8px 0; padding: 2px 0 2px 10px; border-left: 3px solid ${C.border}; color: ${C.muted}; }
      .msg a { color: ${C.assistant}; }
      .msg img { max-width: 100%; }
      .msg hr { border: none; border-top: 1px solid ${C.border}; margin: 10px 0; }
    ]]></mj-style>
    <mj-style><![CDATA[
      .msg p:last-child { margin-bottom: 0; }
    ]]></mj-style>
  </mj-head>
  <mj-body background-color="${C.pageBg}" width="700px">

    <mj-section background-color="${C.white}" border-bottom="1px solid ${C.border}" padding="12px 16px">
      <mj-column>
        <mj-text><strong style="font-size:16px;">AI Support Helper</strong>${
          referral.ref
            ? `<span style="font-family:Consolas,Monaco,monospace;font-size:12px;background:${C.chip};` +
              `border-radius:4px;padding:2px 6px;margin-left:8px;">${escapeHtml(referral.ref)}</span>`
            : ''
        }${
          totalTokens
            ? `<span style="color:${C.faint};font-size:12px;font-weight:normal;"> ${escapeHtml(
                totalTokens
              )}</span>`
            : ''
        }</mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="${C.noteBg}" border-bottom="1px solid ${C.noteBorder}" padding="12px 16px">
      <mj-column>
        <mj-text><strong>Referred to geeks by ${escapeHtml(byWho)}</strong><span style="color:${
          C.muted
        };">${escapeHtml(byEmail)} &middot; ${escapeHtml(formatWhen(referral.generatedAt))}</span></mj-text>
        ${
          referral.note
            ? `<mj-text padding="10px 0 0 0" font-size="12px" color="${C.muted}"><strong>Why they're referring it</strong></mj-text>` +
              `<mj-text padding="2px 0 0 0">${escapeHtml(referral.note).replace(/\n/g, '<br />')}</mj-text>`
            : `<mj-text padding="8px 0 0 0" color="${C.muted}" font-size="13px">No referral text was added.</mj-text>`
        }
      </mj-column>
    </mj-section>

    <mj-section background-color="${C.white}" padding="12px 16px">
      <mj-column>
        <mj-text>${memberChip}</mj-text>
        ${
          member.id
            ? `<mj-text padding="8px 0 0 0" font-size="13px"><a href="${escapeHtml(
                modToolsUrl
              )}/support/${escapeHtml(member.id)}" style="color:${C.assistant};">Open this member in ModTools</a></mj-text>`
            : ''
        }
      </mj-column>
    </mj-section>

    ${devicesSection(referral.deviceSummary, nowMs)}

    <mj-section background-color="${C.chatBg}" padding="12px 16px 4px 16px">
      <mj-column>
        <mj-text><strong>The investigation</strong> <span style="color:${
          C.muted
        };font-size:12px;font-weight:normal;">${messages.length} message${
          messages.length === 1 ? '' : 's'
        }</span></mj-text>
      </mj-column>
    </mj-section>

    ${messages.map((m) => messageSection(m)).join('\n    ')}

    <mj-section background-color="${C.chatBg}" padding="0 16px 12px 16px">
      <mj-column><mj-spacer height="1px" /></mj-column>
    </mj-section>

    <mj-section background-color="${C.panelBg}" border-top="1px solid ${C.border}" padding="12px 16px">
      <mj-column>
        <mj-text font-size="12px" color="${C.muted}">
          ${
            referral.ref
              ? `Reference <strong style="font-family:Consolas,Monaco,monospace;">${escapeHtml(
                  referral.ref
                )}</strong>. `
              : ''
          }Sent by the AI Support Helper in ModTools. Reply to this email to reach
          ${escapeHtml(by.email || 'the support volunteer who referred it')}.
        </mj-text>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>`
}

/** Plain-text alternative, so the mail is not HTML-only. */
function buildReferralText(referral) {
  const member = referral.member || {}
  const by = referral.referredBy || {}
  const lines = []
  lines.push('AI Support Helper - support referral')
  lines.push('')
  if (referral.ref) lines.push(`Reference: ${referral.ref}`)
  lines.push(`Referred by: ${by.name || by.email || 'a support volunteer'}${by.email ? ` <${by.email}>` : ''}`)
  lines.push(`When: ${formatWhen(referral.generatedAt)}`)
  lines.push(
    `Member: ${member.displayname || 'Member'}${member.id ? ` (ID: ${member.id})` : ''}${
      member.email ? ` ${member.email}` : ''
    }`
  )
  if (referral.note) {
    lines.push('')
    lines.push("Why they're referring it:")
    lines.push(referral.note)
  }

  const devices = (referral.deviceSummary && referral.deviceSummary.devices) || []
  if (devices.length) {
    lines.push('')
    lines.push('Recent devices (last 7 days):')
    for (const d of devices) {
      const badge = freshnessBadge(d)
      lines.push(
        `- ${deviceTitle(d)} ${d.os || ''}${badge ? ` [${badge.text}]` : ''} - ${d.sessions || 0} session(s)`
      )
    }
  }

  lines.push('')
  lines.push('The investigation:')
  for (const m of referral.messages || []) {
    lines.push('')
    lines.push(m.role === 'user' ? '--- You ---' : '--- AI Assistant ---')
    lines.push(m.content ? String(m.content).trim() : htmlToText(m.html))
  }
  return lines.join('\n')
}

module.exports = {
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
  COLOURS: C,
}
