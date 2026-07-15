/**
 * Robust extraction/repair of the JSON decision emitted by the FSM brain model.
 *
 * The brain is a reasoning model (e.g. claude-opus-4-8) which frequently wraps
 * its JSON decision in natural-language prose - "I need to...", "The FSM ac..." -
 * or in markdown fences. A naive JSON.parse then throws "Unexpected token 'I'"
 * and ai-flower rejects the whole decision, so the FSM never opens a PR. These
 * helpers pull the actual JSON out of that noise before validation.
 */

/**
 * Extract the first balanced JSON object or array from a string that may carry
 * a natural-language preamble and/or epilogue. Braces/brackets inside string
 * literals are ignored. Returns the JSON substring, or null if none is found.
 */
export function extractJsonBlock(text: string): string | null {
  const start = text.search(/[{[]/)
  if (start === -1) return null
  const open = text[start]
  const close = open === '{' ? '}' : ']'
  let depth = 0
  let inStr = false
  let esc = false
  for (let i = start; i < text.length; i++) {
    const ch = text[i]
    if (inStr) {
      if (esc) esc = false
      else if (ch === '\\') esc = true
      else if (ch === '"') inStr = false
      continue
    }
    if (ch === '"') inStr = true
    else if (ch === open) depth++
    else if (ch === close) {
      depth--
      if (depth === 0) return text.slice(start, i + 1)
    }
  }
  return null
}

/**
 * Repair common Claude JSON shape errors before ai-flower validates.
 *
 * Seen in the wild:
 *   - a natural-language preamble before the JSON (reasoning models) - the
 *     first balanced JSON block is extracted from the prose
 *   - `contextUpdates: "{...}"` (stringified object) - validator wants object
 *   - `actions: "[{...}]"` (stringified array) - validator wants array
 *   - leading/trailing markdown fences (ai-flower strips a single pair itself)
 *
 * If we cannot find or parse any JSON, return the input unchanged so
 * ai-flower's own error surfaces.
 */
export function sanitizeLLMDecision(raw: string): string {
  const cleaned = raw
    .replace(/^```(?:json)?\s*/m, '')
    .replace(/\s*```\s*$/m, '')
    .trim()

  let parsed: any
  let extracted = false
  try {
    parsed = JSON.parse(cleaned)
  } catch {
    // Reasoning models wrap the decision in prose; pull the JSON out of it.
    const block = extractJsonBlock(cleaned)
    if (!block) return raw
    try {
      parsed = JSON.parse(block)
      extracted = true
    } catch {
      return raw
    }
  }
  if (typeof parsed !== 'object' || parsed === null) return raw

  // If we had to strip prose to find the JSON we must return the extracted form,
  // not the original raw (which still has the prose that fails validation).
  let changed = extracted
  if (typeof parsed.contextUpdates === 'string') {
    try {
      const inner = JSON.parse(parsed.contextUpdates)
      if (typeof inner === 'object' && inner !== null) {
        parsed.contextUpdates = inner
        changed = true
      }
    } catch { /* leave as-is, validator will reject */ }
  }
  if (parsed.contextUpdates === undefined || parsed.contextUpdates === null) {
    parsed.contextUpdates = {}
    changed = true
  }
  if (typeof parsed.actions === 'string') {
    try {
      const inner = JSON.parse(parsed.actions)
      if (Array.isArray(inner)) {
        parsed.actions = inner
        changed = true
      }
    } catch { /* leave */ }
  }
  if (parsed.actions === undefined) {
    parsed.actions = []
    changed = true
  }

  return changed ? JSON.stringify(parsed) : raw
}
