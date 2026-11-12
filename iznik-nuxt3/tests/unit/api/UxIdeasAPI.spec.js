import { describe, it, expect, vi } from 'vitest'
import UxIdeasAPI from '~/api/UxIdeasAPI'

describe('UxIdeasAPI', () => {
  const make = () => {
    const api = new UxIdeasAPI({ public: {} })
    api.$getv2 = vi.fn().mockResolvedValue({ counts: {}, flows: [], ideas: [] })
    return api
  }

  it('reads the ideas route with its params, and logs nothing to Sentry on failure', async () => {
    const api = make()
    const params = { flow: 'give', status: 'open', type: 'fix', limit: 50 }
    expect(await api.fetchIdeas(params)).toEqual({
      counts: {},
      flows: [],
      ideas: [],
    })
    // The third argument is logError. A route the server does not have yet must not reach Sentry.
    expect(api.$getv2).toHaveBeenCalledWith('/modtools/ux/ideas', params, false)
  })

  it('asks for everything when it is given no params', async () => {
    const api = make()
    await api.fetchIdeas()
    expect(api.$getv2).toHaveBeenCalledWith('/modtools/ux/ideas', {}, false)
  })

  it('asks for the ideas of one problem with problem_id', async () => {
    const api = make()
    await api.fetchIdeas({ problem_id: 12 })
    expect(api.$getv2).toHaveBeenCalledWith(
      '/modtools/ux/ideas',
      { problem_id: 12 },
      false
    )
  })

  it('has no method that changes an idea: that is the ux:idea command', () => {
    const own = Object.getOwnPropertyNames(UxIdeasAPI.prototype).filter(
      (n) => n !== 'constructor'
    )
    expect(own).toEqual(['fetchIdeas'])
  })

  it('is on the API object under the key uxideas, beside the older UxAPI', async () => {
    const { default: build } = await import('~/api')
    const made = build({ public: {} })
    expect(made.uxideas).toBeInstanceOf(UxIdeasAPI)
    expect(made.ux).toBeDefined()
    expect(made.ux.fetchIdeas).toBeUndefined()
  })
})
