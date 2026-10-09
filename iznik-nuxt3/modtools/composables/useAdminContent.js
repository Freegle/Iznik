// Checks on ADMIN content, matching the Go API (iznik-server-go/admin/content.go) so a moderator
// sees the problem before sending a test. The server makes the same checks and is what enforces them.

// Real HTML tags, by element name, so placeholder text such as "<your names here>" is allowed.
const HTML_TAG =
  /<\s*\/?\s*(a|abbr|b|blockquote|body|br|button|center|code|div|em|font|form|h[1-6]|head|hr|html|i|iframe|img|input|li|link|meta|ol|p|pre|script|section|small|span|strong|style|sub|sup|table|tbody|td|th|thead|tr|u|ul|mjml|mj-[a-z-]+)(\s[^<>]*)?\/?>|<!--/i

const MJML_FORBIDDEN = /<\s*(mjml|mj-head|mj-body|mj-include)\b/i
const MJML_TOP_LEVEL = /<\s*(mj-section|mj-wrapper|mj-hero)\b/i
const MAX_MJML_BYTES = 256 * 1024

export const MJML_SITE = 'https://mjml.io'
export const MJML_TRY_IT = 'https://mjml.io/try-it-live'

// The standard footer every ADMIN gets, as in iznik-batch's emails/mjml/partials/footer.blade.php
// (address from config/freegle.php branding.registered_address). Shown while composing so the
// moderator sees what goes under their content; the batch adds the real one when it sends.
export const FOOTER_CHARITY =
  'Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.'
export const FOOTER_ADDRESS =
  'Registered address: 64a North Road, Ormesby, Great Yarmouth, Norfolk NR29 3LE'

const FOOTER_MJML = `<mj-section background-color="#f5f5f5" padding="20px">
  <mj-column>
    <mj-text font-size="12px" color="#666666" align="center" line-height="1.6">
      This email was sent to member@example.com<br/>
      <a href="#" style="color: #338808; font-weight: bold; text-decoration: none;">Change your email settings</a> &bull;
      <a href="#" style="color: #338808; font-weight: bold; text-decoration: none;">Unsubscribe</a>
    </mj-text>
    <mj-divider border-color="#ddd" border-width="1px" padding="15px 40px"></mj-divider>
    <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
      ${FOOTER_CHARITY}<br/>
      ${FOOTER_ADDRESS}
    </mj-text>
  </mj-column>
</mj-section>`

// The MJML part as a whole document for the MJML live editor, with the standard footer under it.
export function mjmlForLiveEditor(mjml) {
  return `<mjml>\n<mj-body background-color="#f4f4f4">\n${(
    mjml || ''
  ).trim()}\n${FOOTER_MJML}\n</mj-body>\n</mjml>\n`
}

// Returns a message if the plain-text part contains HTML, or null.
export function textProblem(text) {
  const m = text ? text.match(HTML_TAG) : null
  return m
    ? `The text part must be plain text, with no HTML (found ${m[0]}). Put formatted content in the MJML part instead.`
    : null
}

// Returns a message if the MJML part is unusable, or null. An empty part is fine: it is optional.
export function mjmlProblem(mjml) {
  if (!mjml || !mjml.trim()) {
    return null
  }
  if (new TextEncoder().encode(mjml).length > MAX_MJML_BYTES) {
    return 'The MJML part is too long (limit 256KB).'
  }
  const m = mjml.match(MJML_FORBIDDEN)
  if (m) {
    return `The MJML part must be only the sections that go inside <mj-body>, without ${m[0].trim()}>. Freegle adds the head, header and footer.`
  }
  if (!MJML_TOP_LEVEL.test(mjml)) {
    return 'The MJML part must contain at least one <mj-section>, <mj-wrapper> or <mj-hero>.'
  }
  return null
}

// True if this is one plain email address.
export function isSingleEmail(email) {
  return /^[^\s@,<>;"]+@[^\s@,<>;"]+\.[^\s@,<>;"]+$/.test((email || '').trim())
}

// The Go API answers errors as {"error": <status>, "message": "..."}.
export function apiMessage(e, fallback) {
  const msg = e?.response?.data?.message
  return typeof msg === 'string' && msg.trim() ? msg : fallback
}
