import { describe, it, expect } from 'vitest'
import { findProseProblemsScorerPath, proseProblems } from '../prose.js'

describe('prose check', () => {
  it('finds the repo scorer by walking up from the module', () => {
    expect(findProseProblemsScorerPath()).toMatch(/\.claude\/pr-complexity\.mjs$/)
  })

  it('passes an answer a moderator could read on a phone', async () => {
    const problems = await proseProblems(
      'Deleting a rippled post only removes it from the group you are on. The copies on other groups stay.',
    )
    expect(problems).toEqual([])
  })

  it('catches an answer written in jargon', async () => {
    const problems = await proseProblems(
      'The member-scoped deletion semantics propagate asynchronously through the rippling reach ' +
      "enforcement subsystem, whereupon the originating group's canonical representation is " +
      'invalidated and subsequently reconciled against downstream replicas.',
    )
    expect(problems.length).toBeGreaterThan(0)
    expect(problems.join(' ')).toContain('reading grade')
  })

  it('catches a sentence that runs on too long', async () => {
    const problems = await proseProblems(
      'You can see the post on the group you are on and also on the other groups it went to and ' +
      'you can delete it there as well if you want to and the other groups will still keep theirs ' +
      'until somebody there decides to remove it too.',
    )
    expect(problems.join(' ')).toMatch(/words/)
  })

  it('says nothing is wrong when the scorer cannot be found', async () => {
    expect(findProseProblemsScorerPath('/')).toBeNull()
  })
})
