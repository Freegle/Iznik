import { describe, it, expect, vi } from 'vitest'
import UxExperimentsAPI from '~/api/UxExperimentsAPI'

describe('UxExperimentsAPI', () => {
  const make = () => {
    const api = new UxExperimentsAPI({ public: {} })
    api.$getv2 = vi.fn().mockResolvedValue({ flows: [] })
    api.$postv2 = vi.fn().mockResolvedValue({ id: 7, status: 'draft' })
    api.$patchv2 = vi.fn().mockResolvedValue({ queued: true })
    return api
  }

  it('reads the list from the experiments route, with its params, and logs nothing to Sentry on failure', async () => {
    const api = make()
    expect(await api.fetchExperiments({ flow: 'give' })).toEqual({ flows: [] })
    // The third argument is logError. A route the server does not have yet must not reach Sentry.
    expect(api.$getv2).toHaveBeenCalledWith(
      '/modtools/ux/experiments',
      { flow: 'give' },
      false
    )
  })

  it('asks for the whole list when it is given no params', async () => {
    const api = make()
    await api.fetchExperiments()
    expect(api.$getv2).toHaveBeenCalledWith(
      '/modtools/ux/experiments',
      {},
      false
    )
  })

  it('creates a draft with a post, and does not log a refusal, which the form shows', async () => {
    const api = make()
    const data = { flow: 'give', name: 'give-photos-skip', title: 'A title' }
    expect(await api.createExperiment(data)).toEqual({ id: 7, status: 'draft' })
    expect(api.$postv2).toHaveBeenCalledWith(
      '/modtools/ux/experiments',
      data,
      false
    )
  })

  it('starts, stops or closes by id with a patch, and does not log a refusal', async () => {
    const api = make()
    expect(await api.experimentAction(7, { action: 'start' })).toEqual({
      queued: true,
    })
    expect(api.$patchv2).toHaveBeenCalledWith(
      '/modtools/ux/experiments/7',
      { action: 'start' },
      false
    )
  })

  it('does not touch the older UxAPI class, which has no experiment methods', async () => {
    const { default: UxAPI } = await import('~/api/UxAPI')
    const old = new UxAPI({ public: {} })
    expect(old.fetchExperiments).toBeUndefined()
    expect(old.createExperiment).toBeUndefined()
    expect(old.experimentAction).toBeUndefined()
  })
})
