import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useFavoritePage } from '~/composables/useFavoritePage'

const mockSet = vi.fn()

vi.mock('~/stores/misc', () => ({
  useMiscStore: () => ({
    set: mockSet,
  }),
}))

describe('useFavoritePage', () => {
  beforeEach(() => {
    mockSet.mockReset()
  })

  it('sets lasthomepage to pageName', () => {
    useFavoritePage('myposts')

    expect(mockSet).toHaveBeenCalledWith({ key: 'lasthomepage', value: 'myposts' })
  })

  it('sets lasthomepage to a different page name', () => {
    useFavoritePage('browse')

    expect(mockSet).toHaveBeenCalledWith({ key: 'lasthomepage', value: 'browse' })
  })

  it('always sets lasthomepage unconditionally', () => {
    useFavoritePage('myposts')
    useFavoritePage('myposts')

    expect(mockSet).toHaveBeenCalledTimes(2)
  })
})
