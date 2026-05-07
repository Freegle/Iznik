import { describe, it, expect } from 'vitest'

/**
 * Tests for the check_existing_prs action.
 *
 * The action searches open PRs on Freegle/Iznik by keyword(s) extracted from
 * a bug description and returns any matches so DIAGNOSE_BUG can surface them
 * to the LLM before dispatching a fix.
 *
 * The actual gh call is not mocked here — we test the pure parsing logic that
 * turns `gh pr list --json` output into the action's return shape.
 */

// Pure parsing helper extracted from the action.
// Tests are written against this function so we can verify logic without
// spawning processes or requiring network access.

interface PRListItem {
  number: number
  title: string
  url: string
  state: string
  createdAt: string
}

function parsePRList(jsonOut: string): PRListItem[] {
  try {
    const parsed = JSON.parse(jsonOut)
    if (!Array.isArray(parsed)) return []
    return parsed.map((p: any) => ({
      number: p.number ?? 0,
      title: p.title ?? '',
      url: p.url ?? '',
      state: p.state ?? 'OPEN',
      createdAt: p.createdAt ?? '',
    }))
  } catch {
    return []
  }
}

function filterByKeywords(prs: PRListItem[], keywords: string[]): PRListItem[] {
  if (keywords.length === 0) return []
  const lower = keywords.map(k => k.toLowerCase())
  return prs.filter(pr =>
    lower.some(kw => pr.title.toLowerCase().includes(kw))
  )
}

function extractKeywords(bugDescription: string): string[] {
  // Strip very common words, return 2-4 meaningful tokens
  const stopWords = new Set([
    'the', 'a', 'an', 'is', 'in', 'on', 'at', 'to', 'of', 'and', 'or',
    'not', 'with', 'for', 'from', 'that', 'this', 'it', 'its',
    'when', 'when', 'after', 'before', 'if', 'then', 'are', 'was', 'be',
    'does', 'do', 'have', 'has', 'had', 'can', 'cannot', 'could', 'should',
    'will', 'would', 'fix', 'bug', 'issue', 'error', 'problem',
  ])
  const tokens = bugDescription
    .toLowerCase()
    .replace(/[^\w\s]/g, ' ')
    .split(/\s+/)
    .filter(t => t.length > 3 && !stopWords.has(t))
  // Return up to 4 unique keywords, longest first (most specific)
  return [...new Set(tokens)]
    .sort((a, b) => b.length - a.length)
    .slice(0, 4)
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('check_existing_prs — parsePRList', () => {
  it('parses a valid gh pr list JSON response', () => {
    const json = JSON.stringify([
      { number: 201, title: 'fix: chat badge stuck', url: 'https://github.com/Freegle/Iznik/pull/201', state: 'OPEN', createdAt: '2026-04-01T10:00:00Z' },
      { number: 202, title: 'feat: photo upload retry', url: 'https://github.com/Freegle/Iznik/pull/202', state: 'OPEN', createdAt: '2026-04-02T10:00:00Z' },
    ])
    const result = parsePRList(json)
    expect(result).toHaveLength(2)
    expect(result[0].number).toBe(201)
    expect(result[1].title).toBe('feat: photo upload retry')
  })

  it('returns empty array for invalid JSON', () => {
    expect(parsePRList('not json')).toEqual([])
    expect(parsePRList('')).toEqual([])
    expect(parsePRList('{}')).toEqual([])
  })

  it('returns empty array for empty list', () => {
    expect(parsePRList('[]')).toEqual([])
  })

  it('tolerates missing fields in PR objects', () => {
    const json = JSON.stringify([{ number: 5 }])
    const result = parsePRList(json)
    expect(result).toHaveLength(1)
    expect(result[0].title).toBe('')
    expect(result[0].url).toBe('')
  })
})

describe('check_existing_prs — filterByKeywords', () => {
  const prs: PRListItem[] = [
    { number: 1, title: 'fix: chat badge stuck on mobile', url: '', state: 'OPEN', createdAt: '' },
    { number: 2, title: 'feat: photo upload retry logic', url: '', state: 'OPEN', createdAt: '' },
    { number: 3, title: 'fix: stdmsg delete returns 404', url: '', state: 'OPEN', createdAt: '' },
    { number: 4, title: 'chore: update dependencies', url: '', state: 'OPEN', createdAt: '' },
  ]

  it('returns PRs whose title contains any keyword (case-insensitive)', () => {
    const result = filterByKeywords(prs, ['chat'])
    expect(result).toHaveLength(1)
    expect(result[0].number).toBe(1)
  })

  it('matches on any of multiple keywords', () => {
    const result = filterByKeywords(prs, ['photo', 'stdmsg'])
    expect(result).toHaveLength(2)
    expect(result.map(p => p.number)).toContain(2)
    expect(result.map(p => p.number)).toContain(3)
  })

  it('returns empty array when no keywords match', () => {
    const result = filterByKeywords(prs, ['notification', 'digest'])
    expect(result).toHaveLength(0)
  })

  it('returns empty array when keywords list is empty', () => {
    const result = filterByKeywords(prs, [])
    expect(result).toHaveLength(0)
  })

  it('is case-insensitive', () => {
    const result = filterByKeywords(prs, ['BADGE'])
    expect(result).toHaveLength(1)
    expect(result[0].number).toBe(1)
  })
})

describe('check_existing_prs — extractKeywords', () => {
  it('extracts meaningful tokens from a bug description', () => {
    const kws = extractKeywords('Chat badge stuck showing wrong count after marking messages read')
    // 'badge', 'stuck', 'showing', 'wrong', 'count', 'after', 'marking', 'messages', 'read'
    // stopwords: 'after', 'read' -> removed; short (<4 chars): none
    expect(kws.length).toBeGreaterThan(0)
    expect(kws.length).toBeLessThanOrEqual(4)
    // 'badge', 'stuck', 'marking', 'messages' or similar should appear
    expect(kws.some(k => ['badge', 'stuck', 'messages', 'marking', 'showing', 'count', 'wrong'].includes(k))).toBe(true)
  })

  it('strips punctuation from description', () => {
    const kws = extractKeywords('Photo upload: fails with 413 error (file too large).')
    expect(kws.every(k => /^[\w]+$/.test(k))).toBe(true)
  })

  it('returns at most 4 keywords', () => {
    const kws = extractKeywords('notification email delayed spam filter blocking outbound delivery rate limit exceeded')
    expect(kws.length).toBeLessThanOrEqual(4)
  })

  it('deduplicates repeated words', () => {
    const kws = extractKeywords('badge badge badge badge badge badge')
    expect(kws).toHaveLength(1)
  })

  it('returns empty array for very short or stopword-only input', () => {
    const kws = extractKeywords('it is the bug')
    // 'bug' is a stopword, others are too short or stopwords
    expect(kws.length).toBeLessThanOrEqual(1)
  })

  it('returns longest tokens first (most specific first)', () => {
    const kws = extractKeywords('notifications email delivery delayed')
    // 'notifications' (13), 'delivery' (8), 'delayed' (7), 'email' (5)
    if (kws.length >= 2) {
      expect(kws[0].length).toBeGreaterThanOrEqual(kws[1].length)
    }
  })
})

describe('check_existing_prs — integration: filter matches', () => {
  it('finds a related PR when bug description matches existing PR title', () => {
    const prList = JSON.stringify([
      { number: 310, title: 'fix: chat badge count incorrect after marking read', url: 'https://github.com/Freegle/Iznik/pull/310', state: 'OPEN', createdAt: '2026-04-10T10:00:00Z' },
      { number: 311, title: 'feat: new group settings page', url: 'https://github.com/Freegle/Iznik/pull/311', state: 'OPEN', createdAt: '2026-04-11T10:00:00Z' },
    ])
    const prs = parsePRList(prList)
    const kws = extractKeywords('Chat badge shows wrong count after marking messages read')
    const matches = filterByKeywords(prs, kws)
    expect(matches.length).toBeGreaterThan(0)
    expect(matches[0].number).toBe(310)
  })

  it('returns no match when bug is unrelated to any open PR', () => {
    const prList = JSON.stringify([
      { number: 310, title: 'fix: chat badge count incorrect', url: 'https://github.com/Freegle/Iznik/pull/310', state: 'OPEN', createdAt: '' },
    ])
    const prs = parsePRList(prList)
    const kws = extractKeywords('photo upload fails with 413 error')
    const matches = filterByKeywords(prs, kws)
    expect(matches).toHaveLength(0)
  })
})
