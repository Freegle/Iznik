import { describe, it, expect } from 'vitest'
import { promoteUnfixedCallSites } from '../actions/index.js'

const changed = ['iznik-server-go/story/story.go', 'iznik-server-go/test/story_test.go']

describe('promoteUnfixedCallSites', () => {
  it('turns an unfixed twin in a file the PR already edits into a blocker', () => {
    const { promoted, remaining } = promoteUnfixedCallSites(
      [{
        category: 'other-call-sites-same-bug',
        description: 'story/story.go List() has the identical unfixed pattern, around lines 143-149.',
        severity: 'warning',
      }],
      changed,
    )
    expect(promoted).toHaveLength(1)
    expect(promoted[0].severity).toBe('error')
    expect(remaining).toHaveLength(0)
  })

  it('leaves a twin in a file the PR never touched as a warning', () => {
    const { promoted, remaining } = promoteUnfixedCallSites(
      [{
        category: 'other-call-sites-same-bug',
        description: 'chat/chat.go has the same pattern and is not touched here.',
        severity: 'warning',
      }],
      changed,
    )
    expect(promoted).toHaveLength(0)
    expect(remaining).toHaveLength(1)
  })

  it('leaves unrelated warnings alone', () => {
    const { promoted, remaining } = promoteUnfixedCallSites(
      [
        { category: 'naming', description: 'story/story.go uses a different name for this', severity: 'warning' },
        { category: 'dead-code', description: 'unused variable left in story/story.go', severity: 'warning' },
      ],
      changed,
    )
    expect(promoted).toHaveLength(0)
    expect(remaining).toHaveLength(2)
  })

  it('never touches something already recorded as a blocker', () => {
    const { promoted, remaining } = promoteUnfixedCallSites(
      [{ category: 'other-call-sites-same-bug', description: 'story/story.go again', severity: 'error' }],
      changed,
    )
    expect(promoted).toHaveLength(0)
    expect(remaining).toHaveLength(1)
  })

  it('recognises the wording the reviewer actually uses', () => {
    for (const description of [
      'The same bug exists in story.go at line 200.',
      'story_test.go contains an identical pattern that is left unfixed.',
      'This call site in story/story.go was not updated and has the same defect.',
    ]) {
      const { promoted } = promoteUnfixedCallSites(
        [{ category: 'partial', description, severity: 'warning' }],
        changed,
      )
      expect(promoted, description).toHaveLength(1)
    }
  })

  it('copes with no findings and no changed files', () => {
    expect(promoteUnfixedCallSites([], changed).promoted).toHaveLength(0)
    expect(promoteUnfixedCallSites([{ category: 'x', description: 'story.go', severity: 'warning' }], []).promoted).toHaveLength(0)
  })
})
