import { describe, it, expect, vi } from 'vitest'
import ConfigAPI from '~/api/ConfigAPI'

describe('ConfigAPI.fetchv2', () => {
  it('reads the key and logs a failure by default', async () => {
    const api = new ConfigAPI({ public: {} })
    api.$getv2 = vi.fn().mockResolvedValue([])
    await api.fetchv2('some_key')
    expect(api.$getv2).toHaveBeenCalledWith('/config/some_key', {}, true)
  })

  it('can ask for a failure not to be reported, for a key that may be refused', async () => {
    const api = new ConfigAPI({ public: {} })
    api.$getv2 = vi.fn().mockResolvedValue([])
    await api.fetchv2('ux_experiments', false)
    expect(api.$getv2).toHaveBeenCalledWith('/config/ux_experiments', {}, false)
  })
})
