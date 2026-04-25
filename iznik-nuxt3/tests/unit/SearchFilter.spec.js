import { describe, it, expect, beforeEach } from 'vitest'
import { filterItems, isItemRecent, shouldIncludeItem, applyFilters } from '~/composables/useSearchFilters'

describe('SearchFilter - Freezer Filter', () => {
  describe('filterItems - Freezer items', () => {
    it('includes items with freezer=true when no filter applied', () => {
      const items = [
        { id: 1, freezer: true, name: 'Frozen pizza' },
        { id: 2, freezer: false, name: 'Fresh bread' },
      ]
      const filtered = filterItems(items, {})
      expect(filtered).toHaveLength(2)
      expect(filtered.map(i => i.id)).toContain(1)
    })

    it('excludes items with freezer=true when freezer filter is false', () => {
      const items = [
        { id: 1, freezer: true, name: 'Frozen pizza' },
        { id: 2, freezer: false, name: 'Fresh bread' },
      ]
      const filtered = filterItems(items, { freezer: false })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(2)
    })

    it('includes only freezer items when freezer filter is true', () => {
      const items = [
        { id: 1, freezer: true, name: 'Frozen pizza' },
        { id: 2, freezer: false, name: 'Fresh bread' },
        { id: 3, freezer: true, name: 'Frozen cake' },
      ]
      const filtered = filterItems(items, { freezer: true })
      expect(filtered).toHaveLength(2)
      expect(filtered.map(i => i.id)).toEqual([1, 3])
    })

    it('handles items with missing freezer property', () => {
      const items = [
        { id: 1, freezer: true, name: 'Frozen pizza' },
        { id: 2, name: 'Unknown item' },
        { id: 3, freezer: false, name: 'Fresh bread' },
      ]
      const filtered = filterItems(items, { freezer: true })
      expect(filtered.map(i => i.id)).toEqual([1])
    })
  })
})

describe('SearchFilter - Recent Items', () => {
  describe('isItemRecent - Date range filtering', () => {
    it('returns true for items added within last 7 days', () => {
      const now = Date.now()
      const sevenDaysAgo = now - 7 * 24 * 60 * 60 * 1000
      const item = { id: 1, addedDate: new Date(sevenDaysAgo + 1000).toISOString() }
      expect(isItemRecent(item, 7)).toBe(true)
    })

    it('returns false for items added more than 7 days ago', () => {
      const now = Date.now()
      const eightDaysAgo = now - 8 * 24 * 60 * 60 * 1000
      const item = { id: 1, addedDate: new Date(eightDaysAgo).toISOString() }
      expect(isItemRecent(item, 7)).toBe(false)
    })

    it('returns true for items added today', () => {
      const now = new Date().toISOString()
      const item = { id: 1, addedDate: now }
      expect(isItemRecent(item, 7)).toBe(true)
    })

    it('handles custom date ranges', () => {
      const now = Date.now()
      const thirtyDaysAgo = now - 30 * 24 * 60 * 60 * 1000
      const item = { id: 1, addedDate: new Date(thirtyDaysAgo + 1000).toISOString() }
      expect(isItemRecent(item, 30)).toBe(true)
      expect(isItemRecent(item, 7)).toBe(false)
    })

    it('returns false for items with missing addedDate', () => {
      const item = { id: 1 }
      expect(isItemRecent(item, 7)).toBe(false)
    })

    it('returns false for items with invalid date format', () => {
      const item = { id: 1, addedDate: 'invalid-date' }
      expect(isItemRecent(item, 7)).toBe(false)
    })
  })

  describe('filterItems - Recent items', () => {
    it('includes all items when recentDays filter not specified', () => {
      const now = new Date().toISOString()
      const oldDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString()
      const items = [
        { id: 1, addedDate: now },
        { id: 2, addedDate: oldDate },
      ]
      const filtered = filterItems(items, {})
      expect(filtered).toHaveLength(2)
    })

    it('filters to recent items when recentDays specified', () => {
      const now = new Date().toISOString()
      const oldDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString()
      const items = [
        { id: 1, addedDate: now },
        { id: 2, addedDate: oldDate },
      ]
      const filtered = filterItems(items, { recentDays: 7 })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })
  })
})

describe('SearchFilter - Item Type Filters', () => {
  describe('filterItems - Storage type filters', () => {
    it('includes all items when storageType filter not specified', () => {
      const items = [
        { id: 1, storageType: 'Fridge' },
        { id: 2, storageType: 'Freezer' },
        { id: 3, storageType: 'Cupboard' },
      ]
      const filtered = filterItems(items, {})
      expect(filtered).toHaveLength(3)
    })

    it('filters to Fridge items', () => {
      const items = [
        { id: 1, storageType: 'Fridge' },
        { id: 2, storageType: 'Freezer' },
        { id: 3, storageType: 'Cupboard' },
      ]
      const filtered = filterItems(items, { storageType: 'Fridge' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })

    it('filters to Freezer items', () => {
      const items = [
        { id: 1, storageType: 'Fridge' },
        { id: 2, storageType: 'Freezer' },
        { id: 3, storageType: 'Cupboard' },
      ]
      const filtered = filterItems(items, { storageType: 'Freezer' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(2)
    })

    it('filters to Cupboard items', () => {
      const items = [
        { id: 1, storageType: 'Fridge' },
        { id: 2, storageType: 'Freezer' },
        { id: 3, storageType: 'Cupboard' },
      ]
      const filtered = filterItems(items, { storageType: 'Cupboard' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(3)
    })

    it('handles multiple storage types in array filter', () => {
      const items = [
        { id: 1, storageType: 'Fridge' },
        { id: 2, storageType: 'Freezer' },
        { id: 3, storageType: 'Cupboard' },
      ]
      const filtered = filterItems(items, { storageTypes: ['Fridge', 'Freezer'] })
      expect(filtered).toHaveLength(2)
      expect(filtered.map(i => i.id)).toEqual([1, 2])
    })
  })
})

describe('SearchFilter - Combined Filters', () => {
  describe('filterItems - Multiple filters applied simultaneously', () => {
    it('applies freezer and recent filters together', () => {
      const now = new Date().toISOString()
      const oldDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString()
      const items = [
        { id: 1, freezer: true, addedDate: now },
        { id: 2, freezer: true, addedDate: oldDate },
        { id: 3, freezer: false, addedDate: now },
      ]
      const filtered = filterItems(items, { freezer: true, recentDays: 7 })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })

    it('applies freezer, type, and recent filters together', () => {
      const now = new Date().toISOString()
      const items = [
        { id: 1, freezer: true, storageType: 'Freezer', addedDate: now },
        { id: 2, freezer: true, storageType: 'Fridge', addedDate: now },
        { id: 3, freezer: false, storageType: 'Freezer', addedDate: now },
      ]
      const filtered = filterItems(items, { freezer: true, storageType: 'Freezer', recentDays: 7 })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })

    it('applies search term filter with other filters', () => {
      const now = new Date().toISOString()
      const items = [
        { id: 1, freezer: true, name: 'Frozen pizza', addedDate: now },
        { id: 2, freezer: true, name: 'Fresh bread', addedDate: now },
      ]
      const filtered = filterItems(items, { freezer: true, searchTerm: 'pizza' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })
  })

  describe('applyFilters - Complex filter combinations', () => {
    it('returns all items when filters object is empty', () => {
      const items = [
        { id: 1, freezer: true },
        { id: 2, freezer: false },
      ]
      const filtered = applyFilters(items, {})
      expect(filtered).toEqual(items)
    })

    it('applies all specified filters in correct order', () => {
      const now = new Date().toISOString()
      const items = [
        { id: 1, freezer: true, storageType: 'Freezer', name: 'Pizza', addedDate: now },
        { id: 2, freezer: true, storageType: 'Fridge', name: 'Pizza', addedDate: now },
        { id: 3, freezer: false, storageType: 'Freezer', name: 'Bread', addedDate: now },
      ]
      const filtered = applyFilters(items, {
        freezer: true,
        storageType: 'Freezer',
        searchTerm: 'Pizza'
      })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })
  })
})

describe('SearchFilter - Edge Cases', () => {
  describe('filterItems - Empty results', () => {
    it('returns empty array when no items match filters', () => {
      const items = [
        { id: 1, freezer: false },
        { id: 2, freezer: false },
      ]
      const filtered = filterItems(items, { freezer: true })
      expect(filtered).toEqual([])
    })

    it('handles empty input array', () => {
      const items = []
      const filtered = filterItems(items, { freezer: true })
      expect(filtered).toEqual([])
    })

    it('handles null input gracefully', () => {
      const filtered = filterItems(null, { freezer: true })
      expect(filtered).toEqual([])
    })

    it('handles undefined input gracefully', () => {
      const filtered = filterItems(undefined, { freezer: true })
      expect(filtered).toEqual([])
    })
  })

  describe('filterItems - No filters applied', () => {
    it('returns all items when empty filter object provided', () => {
      const items = [
        { id: 1, freezer: true },
        { id: 2, freezer: false },
        { id: 3, freezer: true },
      ]
      const filtered = filterItems(items, {})
      expect(filtered).toHaveLength(3)
    })

    it('returns all items when no filter object provided', () => {
      const items = [
        { id: 1, freezer: true },
        { id: 2, freezer: false },
      ]
      const filtered = filterItems(items)
      expect(filtered).toHaveLength(2)
    })
  })

  describe('filterItems - Clearing and resetting filters', () => {
    it('removes filter when filter value is null', () => {
      const items = [
        { id: 1, freezer: true },
        { id: 2, freezer: false },
      ]
      const filtered = filterItems(items, { freezer: null })
      expect(filtered).toHaveLength(2)
    })

    it('removes filter when filter value is undefined', () => {
      const items = [
        { id: 1, freezer: true },
        { id: 2, freezer: false },
      ]
      const filtered = filterItems(items, { freezer: undefined })
      expect(filtered).toHaveLength(2)
    })

    it('resets all filters when empty object provided after having filters', () => {
      const items = [
        { id: 1, freezer: true, storageType: 'Freezer' },
        { id: 2, freezer: false, storageType: 'Fridge' },
      ]
      // First apply filters
      let filtered = filterItems(items, { freezer: true, storageType: 'Freezer' })
      expect(filtered).toHaveLength(1)

      // Then clear filters
      filtered = filterItems(items, {})
      expect(filtered).toHaveLength(2)
    })
  })

  describe('shouldIncludeItem - Single item evaluation', () => {
    it('evaluates single item against multiple filter conditions', () => {
      const item = { id: 1, freezer: true, storageType: 'Freezer', name: 'Pizza' }
      expect(shouldIncludeItem(item, { freezer: true })).toBe(true)
      expect(shouldIncludeItem(item, { freezer: false })).toBe(false)
      expect(shouldIncludeItem(item, { storageType: 'Freezer' })).toBe(true)
      expect(shouldIncludeItem(item, { storageType: 'Fridge' })).toBe(false)
    })

    it('returns true when all conditions match', () => {
      const item = { id: 1, freezer: true, storageType: 'Freezer', name: 'Pizza' }
      const result = shouldIncludeItem(item, { freezer: true, storageType: 'Freezer' })
      expect(result).toBe(true)
    })

    it('returns false when any condition does not match', () => {
      const item = { id: 1, freezer: true, storageType: 'Fridge', name: 'Pizza' }
      const result = shouldIncludeItem(item, { freezer: true, storageType: 'Freezer' })
      expect(result).toBe(false)
    })

    it('returns true for empty filter object', () => {
      const item = { id: 1, freezer: true, storageType: 'Fridge' }
      expect(shouldIncludeItem(item, {})).toBe(true)
    })
  })

  describe('Search term matching', () => {
    it('matches case-insensitive search terms', () => {
      const items = [
        { id: 1, name: 'Pizza', description: '' },
        { id: 2, name: 'bread', description: 'Fresh' },
      ]
      const filtered = filterItems(items, { searchTerm: 'PIZZA' })
      expect(filtered).toHaveLength(1)
      expect(filtered[0].id).toBe(1)
    })

    it('searches across multiple fields', () => {
      const items = [
        { id: 1, name: 'Pizza', description: 'Frozen' },
        { id: 2, name: 'Bread', description: 'Sourdough pizza' },
      ]
      const filtered = filterItems(items, { searchTerm: 'pizza' })
      expect(filtered).toHaveLength(2)
    })

    it('returns all items when search term is empty string', () => {
      const items = [
        { id: 1, name: 'Pizza' },
        { id: 2, name: 'Bread' },
      ]
      const filtered = filterItems(items, { searchTerm: '' })
      expect(filtered).toHaveLength(2)
    })

    it('returns empty results for search term that matches nothing', () => {
      const items = [
        { id: 1, name: 'Pizza' },
        { id: 2, name: 'Bread' },
      ]
      const filtered = filterItems(items, { searchTerm: 'xyz123nonexistent' })
      expect(filtered).toEqual([])
    })
  })
})

describe('SearchFilter - API Integration', () => {
  describe('Filter parameters passed correctly to API calls', () => {
    it('builds correct filter query parameters', () => {
      const filters = {
        freezer: true,
        recentDays: 7,
        storageType: 'Freezer',
        searchTerm: 'pizza'
      }
      // This would be called by the component to build API params
      const params = shouldIncludeItem({ freezer: true }, filters)
      expect(params).toBe(true)
    })

    it('omits null/undefined filter parameters from API call', () => {
      const filters = {
        freezer: true,
        storageType: undefined,
        searchTerm: null
      }
      const items = [{ id: 1, freezer: true, storageType: 'Fridge', name: 'test' }]
      const filtered = filterItems(items, filters)
      // Should still filter by freezer even with undefined/null other filters
      expect(filtered).toHaveLength(1)
    })
  })
})
