import { describe, it, expect, vi } from 'vitest'
import {
  leafletSingleInstance,
  LEAFLET_ESM,
} from '../../../build-plugins/leafletSingleInstance'

function resolveWith(source, importer = '/x/geocoder.js') {
  const plugin = leafletSingleInstance()
  const resolve = vi.fn().mockResolvedValue({ id: '/resolved/esm.js' })
  const result = plugin.resolveId.call({ resolve }, source, importer, {})
  return { result, resolve }
}

describe('leafletSingleInstance', () => {
  it('runs before the other resolvers', () => {
    expect(leafletSingleInstance().enforce).toBe('pre')
  })

  it('sends a bare leaflet import to the ES module build', async () => {
    const { result, resolve } = resolveWith('leaflet')

    expect(await result).toEqual({ id: '/resolved/esm.js' })
    expect(resolve).toHaveBeenCalledWith(
      LEAFLET_ESM,
      '/x/geocoder.js',
      expect.objectContaining({ skipSelf: true })
    )
  })

  it.each([
    'leaflet/dist/leaflet-src.esm',
    'leaflet/dist/leaflet.css',
    'leaflet-control-geocoder',
    'vue',
  ])('leaves %s alone', async (source) => {
    const { result, resolve } = resolveWith(source)

    expect(await result).toBeNull()
    expect(resolve).not.toHaveBeenCalled()
  })
})
