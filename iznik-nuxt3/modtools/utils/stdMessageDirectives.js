// Directive tags for standard messages, processed client-side in the ModTools
// "send standard message" flow (ModStdMessageModal).
//
//   <editthis>suggested text</editthis>
//     A bit the moderator MUST personalise before the message can be sent. The
//     send is blocked while the original placeholder text is still present, so a
//     mod can't fire off the template verbatim. The tags themselves never reach
//     the recipient (they are stripped on send).
//
//   <optional>...</optional>
//     A bit that doesn't apply in every case (e.g. "you could browse instead of
//     posting a Wanted"). The mod must explicitly Keep or Remove it — a forced
//     decision (so it isn't sent blindly) with one-tap removal (so it isn't
//     faffy to delete by hand on a phone). The send is blocked while any optional
//     section is still undecided. Tags are stripped on send.
//
// All functions here are PURE (string in → string/array out) so the parsing,
// stripping and send-gating rules are unit-testable without mounting the modal.

const EDITTHIS_RE = /<editthis>([\s\S]*?)<\/editthis>/gi
const OPTIONAL_RE = /<optional>([\s\S]*?)<\/optional>/gi

// Inner texts of every <editthis> block, in order.
export function parseEditThis(text) {
  return matchInners(text, EDITTHIS_RE)
}

// Inner texts of every <optional> block, in order.
export function parseOptional(text) {
  return matchInners(text, OPTIONAL_RE)
}

function matchInners(text, re) {
  if (!text) return []
  const out = []
  let m
  const r = new RegExp(re.source, re.flags)
  while ((m = r.exec(text)) !== null) {
    out.push(m[1].trim())
  }
  return out
}

// Remove ALL directive tags, keeping their inner content. Used to produce the
// final message a recipient sees (after the mod has edited/decided).
export function stripDirectiveTags(text) {
  if (!text) return text
  return text
    .replace(/<\/?editthis>/gi, '')
    .replace(/<\/?optional>/gi, '')
}

// Replace the Nth (0-based) occurrence of a tag with `replacement(inner)`.
// Indices are taken from the CURRENT text, so callers re-parse after each edit.
function replaceNth(text, re, index, replacement) {
  if (!text) return text
  let i = -1
  const r = new RegExp(re.source, re.flags)
  return text.replace(r, (whole, inner) => {
    i++
    return i === index ? replacement(inner) : whole
  })
}

// Drop the Nth <optional> block entirely (one-tap Remove), tidying the blank
// line it leaves behind so the message doesn't end up with an awkward gap.
export function removeOptionalAt(text, index) {
  return collapseBlankLines(replaceNth(text, OPTIONAL_RE, index, () => ''))
}

// Keep the Nth <optional> block: strip just its tags, leaving the inner text.
export function keepOptionalAt(text, index) {
  return replaceNth(text, OPTIONAL_RE, index, (inner) => inner)
}

// Tidy up runs of blank lines (e.g. left after removing an optional paragraph).
function collapseBlankLines(text) {
  if (!text) return text
  return text.replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n')
}

// Of the original <editthis> placeholders, which are STILL present verbatim in
// the current body (i.e. not yet personalised). Drives both the live "still to
// edit" warning and the send gate. Whitespace-only placeholders are ignored.
export function pendingEditThis(currentText, originalInners) {
  if (!currentText || !originalInners) return []
  const haystack = stripDirectiveTags(currentText)
  return originalInners.filter((o) => o && o.trim() && haystack.includes(o))
}

// True while any <optional> block is still undecided (its tags remain). Once the
// mod has chosen Keep or Remove for every optional, no <optional> tag is left.
export function hasUndecidedOptional(text) {
  if (!text) return false
  return /<optional>/i.test(text)
}

// Everything a caller needs to gate the Send button. Returns the lists so the UI
// can show exactly what's outstanding; `ok` is true when nothing blocks sending.
export function sendBlockers(currentText, originalEditThisInners) {
  const editThis = pendingEditThis(currentText, originalEditThisInners)
  const optional = hasUndecidedOptional(currentText)
  return { editThis, optionalUndecided: optional, ok: editThis.length === 0 && !optional }
}

// ── Segmented editor model ──────────────────────────────────────────────────
// Editing the whole template (tags and all) in one textarea gets unmanageable
// with several <editthis> blocks — you hunt through the text and lose your place.
// parseSegments splits the message into an ORDERED list so the UI can render it
// in flow with an inline box per <editthis> and a keep/remove control per
// <optional>: the mod fills each box in order, never looking back at the raw
// template.
//
// Segment shapes:
//   { type: 'text',     content }                 fixed prose (not editable)
//   { type: 'editthis', content, value, edited }  must-fill; content=placeholder
//   { type: 'optional', content, removed }        removed: undefined until decided
const SEGMENT_RE = /<(editthis|optional)>([\s\S]*?)<\/\1>/gi

export function parseSegments(text) {
  if (!text) return []
  const re = new RegExp(SEGMENT_RE.source, SEGMENT_RE.flags)
  const segments = []
  let last = 0
  let m
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) segments.push({ type: 'text', content: text.slice(last, m.index) })
    const type = m[1].toLowerCase() // 'editthis' | 'optional'
    const content = m[2]
    if (type === 'editthis') segments.push({ type, content, value: content, edited: false })
    else segments.push({ type, content, removed: undefined })
    last = m.index + m[0].length
  }
  if (last < text.length) segments.push({ type: 'text', content: text.slice(last) })
  return segments
}

// True if a message uses directives (modal switches to the segmented editor;
// plain messages keep the ordinary textarea).
export function hasDirectives(text) {
  if (!text) return false
  return /<editthis>/i.test(text) || /<optional>/i.test(text)
}

// Build the final recipient message from the edited segments, in order. editthis
// → the mod's value; optional → its text unless removed; text → as-is. Tidies the
// blank lines a removed optional leaves behind.
export function assembleSegments(segments) {
  if (!segments) return ''
  const out = segments
    .map((s) => {
      if (s.type === 'editthis') return s.value ?? s.content
      if (s.type === 'optional') return s.removed ? '' : s.content
      return s.content
    })
    .join('')
  return collapseBlankLines(out)
}

// What still blocks Send in the segmented model: an editthis not yet changed from
// its placeholder (or blanked), and an optional not yet Kept/Removed. Returns the
// segment indices so the UI can highlight exactly what's outstanding.
export function segmentsSendBlockers(segments) {
  const unedited = []
  const undecided = []
  ;(segments || []).forEach((s, i) => {
    if (s.type === 'editthis') {
      const v = (s.value ?? '').trim()
      if (!v || v === (s.content ?? '').trim()) unedited.push(i)
    } else if (s.type === 'optional' && s.removed === undefined) {
      undecided.push(i)
    }
  })
  return { unedited, undecided, ok: unedited.length === 0 && undecided.length === 0 }
}
