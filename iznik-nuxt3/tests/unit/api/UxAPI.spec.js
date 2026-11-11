import { describe, it, expect, vi } from 'vitest'
import UxAPI from '~/api/UxAPI'

describe('UxAPI', () => {
  const routes = {
    fetchFunnels: '/modtools/ux/funnels',
    fetchOutcomes: '/modtools/ux/outcomes',
    fetchFields: '/modtools/ux/fields',
    fetchFriction: '/modtools/ux/friction',
    fetchAdoption: '/modtools/ux/adoption',
    fetchRegret: '/modtools/ux/regret',
    fetchSearch: '/modtools/ux/search',
    fetchHealth: '/modtools/ux/health',
    fetchSamples: '/modtools/ux/samples',
  }

  for (const [method, path] of Object.entries(routes)) {
    it(`${method} calls ${path} on the v2 API with its params`, async () => {
      const api = new UxAPI({ public: {} })
      api.$getv2 = vi.fn().mockResolvedValue({ ok: true })
      const params = { start: '2026-10-01', end: '2026-10-07' }
      expect(await api[method](params)).toEqual({ ok: true })
      expect(api.$getv2).toHaveBeenCalledWith(path, params)
    })
  }
})
