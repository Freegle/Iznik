import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const mockFetchConcernKeywordsv2 = vi.fn()
const mockAddConcernKeywordv2 = vi.fn()
const mockDeleteConcernKeywordv2 = vi.fn()

vi.mock('~/api', () => ({
  default: () => ({
    config: {
      fetchConcernKeywordsv2: mockFetchConcernKeywordsv2,
      addConcernKeywordv2: mockAddConcernKeywordv2,
      deleteConcernKeywordv2: mockDeleteConcernKeywordv2,
    },
  }),
}))

describe('systemconfig store', () => {
  let useSystemConfigStore

  beforeEach(async () => {
    vi.clearAllMocks()
    setActivePinia(createPinia())
    const mod = await import('~/modtools/stores/systemconfig')
    useSystemConfigStore = mod.useSystemConfigStore
  })

  it('starts with empty state', () => {
    const store = useSystemConfigStore()
    expect(store.concern_keywords).toEqual([])
    expect(store.loading).toBe(false)
    expect(store.error).toBeNull()
  })

  describe('getters', () => {
    it('getConcernKeywords returns concern_keywords', () => {
      const store = useSystemConfigStore()
      store.concern_keywords = [{ id: 1, keyword: 'test', category: 'review' }]
      expect(store.getConcernKeywords).toEqual([
        { id: 1, keyword: 'test', category: 'review' },
      ])
    })

    it('isLoading reflects loading state', () => {
      const store = useSystemConfigStore()
      expect(store.isLoading).toBe(false)
      store.loading = true
      expect(store.isLoading).toBe(true)
    })

    it('hasError reflects error state', () => {
      const store = useSystemConfigStore()
      expect(store.hasError).toBe(false)
      store.error = 'Something broke'
      expect(store.hasError).toBe(true)
    })

    it('getError returns error message', () => {
      const store = useSystemConfigStore()
      store.error = 'oops'
      expect(store.getError).toBe('oops')
    })
  })

  describe('concern keywords', () => {
    it('fetchConcernKeywords stores results', async () => {
      const store = useSystemConfigStore()
      store.init({})
      const keywords = [
        { id: 1, keyword: 'knife', category: 'substance_regulated', scope: 'global' },
      ]
      mockFetchConcernKeywordsv2.mockResolvedValue(keywords)
      await store.fetchConcernKeywords()
      expect(mockFetchConcernKeywordsv2).toHaveBeenCalledWith({})
      expect(store.concern_keywords).toEqual(keywords)
    })

    it('fetchConcernKeywords handles null response', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchConcernKeywordsv2.mockResolvedValue(null)
      await store.fetchConcernKeywords()
      expect(store.concern_keywords).toEqual([])
    })

    it('fetchConcernKeywords sets empty array on error', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchConcernKeywordsv2.mockRejectedValue(new Error('network'))
      await store.fetchConcernKeywords()
      expect(store.concern_keywords).toEqual([])
      expect(store.hasError).toBe(true)
    })

    it('fetchConcernKeywords clears loading on completion', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockFetchConcernKeywordsv2.mockResolvedValue([])
      await store.fetchConcernKeywords()
      expect(store.loading).toBe(false)
    })

    it('addConcernKeyword calls API and re-fetches', async () => {
      const store = useSystemConfigStore()
      store.init({})
      mockAddConcernKeywordv2.mockResolvedValue({})
      mockFetchConcernKeywordsv2.mockResolvedValue([])
      await store.addConcernKeyword({
        keyword: 'test',
        category: 'review',
        match_mode: 'literal',
        action: 'flag',
        scope: 'global',
      })
      expect(mockAddConcernKeywordv2).toHaveBeenCalledWith({
        keyword: 'test',
        category: 'review',
        match_mode: 'literal',
        action: 'flag',
        scope: 'global',
      })
      expect(mockFetchConcernKeywordsv2).toHaveBeenCalled()
    })

    it('addConcernKeyword ignores empty keyword', async () => {
      const store = useSystemConfigStore()
      store.init({})
      await store.addConcernKeyword({ keyword: '  ' })
      expect(mockAddConcernKeywordv2).not.toHaveBeenCalled()
    })

    it('addConcernKeyword ignores missing keyword', async () => {
      const store = useSystemConfigStore()
      store.init({})
      await store.addConcernKeyword({})
      expect(mockAddConcernKeywordv2).not.toHaveBeenCalled()
    })

    it('deleteConcernKeyword removes from local state', async () => {
      const store = useSystemConfigStore()
      store.init({})
      store.concern_keywords = [
        { id: 1, keyword: 'keep', category: 'review' },
        { id: 2, keyword: 'remove', category: 'scam' },
      ]
      mockDeleteConcernKeywordv2.mockResolvedValue({})
      await store.deleteConcernKeyword(2)
      expect(mockDeleteConcernKeywordv2).toHaveBeenCalledWith(2)
      expect(store.concern_keywords).toEqual([
        { id: 1, keyword: 'keep', category: 'review' },
      ])
    })

    it('deleteConcernKeyword handles error', async () => {
      const store = useSystemConfigStore()
      store.init({})
      store.concern_keywords = [{ id: 1, keyword: 'test', category: 'review' }]
      mockDeleteConcernKeywordv2.mockRejectedValue(new Error('Not found'))
      await store.deleteConcernKeyword(1)
      expect(store.error).toBe('Not found')
      expect(store.loading).toBe(false)
    })
  })
})
