'use strict'

/**
 * Compile and send a "Refer to geeks" support referral.
 *
 * The MJML itself is built by referral-mjml.js, which is dependency-free so it
 * can be unit-tested under `node --test` with no node_modules (how CI runs
 * these specs). Everything that needs a library - the MJML->HTML compile and
 * the SMTP send - lives here.
 */

const crypto = require('crypto')
const mjml2html = require('mjml')
const nodemailer = require('nodemailer')
const {
  buildReferralMjml,
  buildReferralText,
  referralSubject,
  formatReferralRef,
} = require('./referral-mjml')

// Where referrals go. Local dev points SUPPORT_SMTP_HOST at mailpit, so nothing
// escapes the machine until it is deliberately configured otherwise.
const GEEKS_EMAIL = process.env.GEEKS_EMAIL || 'geeks@ilovefreegle.org'
const REFERRAL_FROM = process.env.SUPPORT_REFERRAL_FROM || 'support@ilovefreegle.org'
const SMTP_HOST = process.env.SUPPORT_SMTP_HOST || process.env.SMTP_HOST || 'mailpit'
const SMTP_PORT = Number(process.env.SUPPORT_SMTP_PORT || process.env.SMTP_PORT || 1025)
const SMTP_USER = process.env.SUPPORT_SMTP_USER || process.env.SMTP_USER || ''
const SMTP_PASS = process.env.SUPPORT_SMTP_PASS || process.env.SMTP_PASS || ''

// A referral carries a whole conversation, so cap it rather than letting a
// runaway client post an unbounded body.
const MAX_MESSAGES = 100
const MAX_HTML_PER_MESSAGE = 200000

/**
 * A fresh tracking reference. Generated on the SERVER so it is authoritative -
 * the client cannot choose or reuse one, and it is the same value that goes in
 * the subject, the email body and the audit trail.
 */
function newReferralRef() {
  return formatReferralRef(crypto.randomBytes(8))
}

/** Compile the referral to {subject, html, text}. Throws on invalid MJML. */
function renderReferralEmail(referral, nowMs = Date.now()) {
  const trimmed = {
    ...referral,
    messages: (referral.messages || []).slice(-MAX_MESSAGES).map((m) => ({
      ...m,
      html: m.html ? String(m.html).slice(0, MAX_HTML_PER_MESSAGE) : m.html,
    })),
  }

  const mjml = buildReferralMjml(trimmed, nowMs)
  const { html, errors } = mjml2html(mjml, { validationLevel: 'soft', minify: false })
  if (errors && errors.length) {
    // Soft validation still renders; log so a template mistake is visible
    // rather than silently producing a broken email.
    console.error('[Referral] MJML warnings:', errors.map((e) => e.formattedMessage).join('; '))
  }
  return {
    subject: referralSubject(trimmed),
    html,
    text: buildReferralText(trimmed),
  }
}

function transport() {
  return nodemailer.createTransport({
    host: SMTP_HOST,
    port: SMTP_PORT,
    // 465 is implicit TLS; 587/25/1025 start plain and may STARTTLS.
    secure: SMTP_PORT === 465,
    auth: SMTP_USER ? { user: SMTP_USER, pass: SMTP_PASS } : undefined,
    // mailpit and other dev relays have self-signed certificates.
    tls: { rejectUnauthorized: false },
  })
}

/**
 * Send the referral to geeks@. Reply-To is the referring volunteer, so a reply
 * goes back to the person who actually saw the problem rather than to a
 * no-reply address.
 *
 * @returns {Promise<{to:string, subject:string, messageId:string}>}
 */
async function sendReferral(referral, opts = {}) {
  const to = opts.to || GEEKS_EMAIL
  const { subject, html, text } = renderReferralEmail(referral)
  const replyTo = (referral.referredBy && referral.referredBy.email) || undefined

  const info = await transport().sendMail({
    from: REFERRAL_FROM,
    to,
    replyTo,
    subject,
    html,
    text,
    // Also as a header, so a mail rule or a script can match on the reference
    // without parsing the subject line.
    headers: referral.ref ? { 'X-Freegle-Support-Referral': referral.ref } : undefined,
  })
  return { to, subject, ref: referral.ref, messageId: info.messageId }
}

module.exports = {
  renderReferralEmail,
  sendReferral,
  newReferralRef,
  GEEKS_EMAIL,
  REFERRAL_FROM,
  SMTP_HOST,
  SMTP_PORT,
}
