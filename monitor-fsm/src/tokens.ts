// Token usage accounting shared across the driver (brain calls) and the
// delegate action (spawned `claude -p` subprocess). Both paths funnel through
// `recordTokens()` with the usage block extracted from the SDK / stream-json
// `result` message.
//
// Shape of SDK usage blocks varies across versions:
//   { input_tokens, output_tokens, cache_creation_input_tokens, cache_read_input_tokens }
// Stream-JSON `result` events expose the same shape. We defensively pick any
// field that's a number and zero the rest — missing fields never throw.
//
// The module keeps two accumulators:
//   - stepAccum:  reset by beginStep() at the start of every driver step; the
//                 step footer reads stepSummary() to show tokens per step.
//   - iterAccum:  accumulates for the entire iteration; iterationSummary() is
//                 called once at the end to print the total.
// Per-source totals (brain vs delegate) are tracked so the iteration line can
// show where the tokens went — usually the delegate dominates.

export interface TokenUsage {
  input: number
  output: number
  cacheRead: number
  cacheCreate: number
}

function zero(): TokenUsage {
  return { input: 0, output: 0, cacheRead: 0, cacheCreate: 0 }
}

function add(dst: TokenUsage, src: TokenUsage): void {
  dst.input += src.input
  dst.output += src.output
  dst.cacheRead += src.cacheRead
  dst.cacheCreate += src.cacheCreate
}

let stepAccum: TokenUsage = zero()
let iterAccum: TokenUsage = zero()
const iterBySource: Record<string, TokenUsage> = {}

function pick(obj: any, keys: string[]): number {
  for (const k of keys) {
    const v = obj[k]
    if (typeof v === 'number' && Number.isFinite(v)) return v
  }
  return 0
}

export function extractUsage(raw: any): TokenUsage | null {
  if (!raw || typeof raw !== 'object') return null
  const u: TokenUsage = {
    input: pick(raw, ['input_tokens', 'prompt_tokens']),
    output: pick(raw, ['output_tokens', 'completion_tokens']),
    cacheRead: pick(raw, ['cache_read_input_tokens', 'cache_read_tokens']),
    cacheCreate: pick(raw, ['cache_creation_input_tokens', 'cache_write_tokens']),
  }
  if (u.input === 0 && u.output === 0 && u.cacheRead === 0 && u.cacheCreate === 0) return null
  return u
}

export function recordTokens(source: string, u: TokenUsage | null): void {
  if (!u) return
  add(stepAccum, u)
  add(iterAccum, u)
  if (!iterBySource[source]) iterBySource[source] = zero()
  add(iterBySource[source], u)
}

export function beginStep(): void {
  stepAccum = zero()
}

function fmt(n: number): string {
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}k`
  return `${n}`
}

function formatUsage(u: TokenUsage): string {
  const bits = [`${fmt(u.input)} in`, `${fmt(u.output)} out`]
  if (u.cacheRead) bits.push(`${fmt(u.cacheRead)} cache-read`)
  if (u.cacheCreate) bits.push(`${fmt(u.cacheCreate)} cache-write`)
  return bits.join(', ')
}

// Step footer helper. Returns empty string when no tokens were spent — the
// logger treats that as "silent pop", so tool-only steps don't get a noisy
// "0 in / 0 out" line.
export function stepSummary(): string {
  const total = stepAccum.input + stepAccum.output + stepAccum.cacheRead + stepAccum.cacheCreate
  if (total === 0) return ''
  return `tokens ${formatUsage(stepAccum)}`
}

export function iterationSummary(): string {
  const overall = formatUsage(iterAccum)
  const sources = Object.entries(iterBySource)
  if (sources.length <= 1) return overall
  const breakdown = sources.map(([src, u]) => `${src} ${formatUsage(u)}`).join('; ')
  return `${overall} (${breakdown})`
}

export function currentIterUsage(): TokenUsage {
  return { ...iterAccum }
}
