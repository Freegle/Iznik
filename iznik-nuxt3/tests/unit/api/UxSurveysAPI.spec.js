import { describe, it, expect, vi } from 'vitest'
import UxSurveysAPI from '~/api/UxSurveysAPI'

describe('UxSurveysAPI', () => {
  const make = () => {
    const api = new UxSurveysAPI({ public: {} })
    api.$getv2 = vi.fn().mockResolvedValue({ ok: true })
    return api
  }

  // The third argument is logError. A route the server does not have yet must not reach Sentry.
  it.each([
    ['fetchProblemGroups', '/modtools/ux/problem-groups'],
    ['fetchProblems', '/modtools/ux/problems'],
    ['fetchQuestions', '/modtools/ux/questions'],
  ])(
    '%s reads %s with its params and logs nothing on failure',
    async (method, path) => {
      const api = make()
      const params = { flow: 'give', q: "can't" }
      expect(await api[method](params)).toEqual({ ok: true })
      expect(api.$getv2).toHaveBeenCalledWith(path, params, false)
    }
  )

  it.each(['fetchProblemGroups', 'fetchProblems', 'fetchQuestions'])(
    '%s asks for everything when it is given no params',
    async (method) => {
      const api = make()
      await api[method]()
      expect(api.$getv2.mock.calls[0][1]).toEqual({})
    }
  )

  it('reads the values with no params and logs nothing on failure', async () => {
    const api = make()
    expect(await api.fetchValues()).toEqual({ ok: true })
    expect(api.$getv2).toHaveBeenCalledWith('/modtools/ux/values', {}, false)
  })

  it('has the four reads and nothing that writes', () => {
    const own = Object.getOwnPropertyNames(UxSurveysAPI.prototype).filter(
      (n) => n !== 'constructor'
    )
    expect(own.sort()).toEqual([
      'fetchProblemGroups',
      'fetchProblems',
      'fetchQuestions',
      'fetchValues',
    ])
  })

  it('does not touch the older UxAPI class, which has no survey methods', async () => {
    const { default: UxAPI } = await import('~/api/UxAPI')
    const old = new UxAPI({ public: {} })
    expect(old.fetchProblemGroups).toBeUndefined()
    expect(old.fetchProblems).toBeUndefined()
    expect(old.fetchValues).toBeUndefined()
    expect(old.fetchQuestions).toBeUndefined()
  })
})
