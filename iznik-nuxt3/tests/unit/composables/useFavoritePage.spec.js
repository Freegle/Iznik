import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useFavoritePage } from '~/composables/useFavoritePage'

const mockGet = vi.fn()
const mockSet = vi.fn()

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    get: mockGet,
    set: mockSet,
  }),
}))

describe('useFavoritePage', () => {
  beforeEach(() => {
    mockGet.mockReset()
    mockSet.mockReset()
  })

  it('sets lasthomepage when it differs from pageName', () => {
    mockGet.mockReturnValue('browse')

    useFavoritePage('myposts')

    expect(mockGet).toHaveBeenCalledWith('lasthomepage')
    expect(mockSet).toHaveBeenCalledWith({ key: 'lasthomepage', value: 'myposts' })
  })

  it('does not set lasthomepage when it already matches pageName', () => {
    mockGet.mockReturnValue('myposts')

    useFavoritePage('myposts')

    expect(mockGet).toHaveBeenCalledWith('lasthomepage')
    expect(mockSet).not.toHaveBeenCalled()
  })

  it('sets lasthomepage when existingHomepage is null (first visit)', () => {
    mockGet.mockReturnValue(null)

    useFavoritePage('browse')

    expect(mockSet).toHaveBeenCalledWith({ key: 'lasthomepage', value: 'browse' })
  })

  it('sets lasthomepage when existingHomepage is undefined', () => {
    mockGet.mockReturnValue(undefined)

    useFavoritePage('myposts')

    expect(mockSet).toHaveBeenCalledWith({ key: 'lasthomepage', value: 'myposts' })
  })
})
