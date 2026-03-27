import { describe, it, expect } from 'vitest'
import { filterFeed, searchFeed } from '~/composables/useFeedFilter'

const mockItems = [
  { id: 1, type: 'Offer', title: 'Sofa', description: 'Comfy sofa', taken: false },
  { id: 2, type: 'Wanted', title: 'Bike', description: 'Kids bike', taken: false },
  { id: 3, type: 'Discussion', title: 'Tip hours?', description: 'Anyone know?', taken: false },
  { id: 4, type: 'Offer', title: 'Table', description: 'Pine table', taken: true },
  { id: 5, type: 'Offer', title: 'Books', description: 'Box of fiction books', taken: false },
]

describe('filterFeed', () => {
  it('returns all items for "All" filter', () => {
    expect(filterFeed(mockItems, 'All')).toHaveLength(5)
  })
  it('filters to Offers only', () => {
    const result = filterFeed(mockItems, 'Offer')
    expect(result.every((i) => i.type === 'Offer')).toBe(true)
  })
  it('filters to Wanteds only', () => {
    const result = filterFeed(mockItems, 'Wanted')
    expect(result.every((i) => i.type === 'Wanted')).toBe(true)
  })
  it('filters to Discussion only', () => {
    const result = filterFeed(mockItems, 'Discussion')
    expect(result.every((i) => i.type === 'Discussion')).toBe(true)
  })
  it('includes taken items in their parent type', () => {
    const result = filterFeed(mockItems, 'Offer')
    expect(result.find((i) => i.id === 4)).toBeTruthy()
  })
})

describe('searchFeed', () => {
  it('filters by title match', () => {
    const result = searchFeed(mockItems, 'sofa')
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe(1)
  })
  it('filters by description match', () => {
    const result = searchFeed(mockItems, 'fiction')
    expect(result).toHaveLength(1)
    expect(result[0].id).toBe(5)
  })
  it('is case insensitive', () => {
    expect(searchFeed(mockItems, 'BIKE')).toHaveLength(1)
  })
  it('returns all for empty search', () => {
    expect(searchFeed(mockItems, '')).toHaveLength(5)
  })
})
