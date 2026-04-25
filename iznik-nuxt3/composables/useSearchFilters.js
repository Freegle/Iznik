/**
 * Composable for filtering items based on search and storage filters.
 * Handles freezer, storage type, recent items, and search term filtering.
 */

/**
 * Check if an item is recent based on addedDate and days threshold.
 * @param {Object} item - The item to check
 * @param {number} days - Number of days to consider recent
 * @returns {boolean} True if item is recent, false otherwise
 */
export function isItemRecent(item, days) {
  if (!item || !item.addedDate) {
    return false
  }

  try {
    const addedTime = new Date(item.addedDate).getTime()
    if (isNaN(addedTime)) {
      return false
    }

    const now = Date.now()
    const dayInMs = 24 * 60 * 60 * 1000
    const thresholdTime = now - days * dayInMs

    return addedTime >= thresholdTime
  } catch (e) {
    return false
  }
}

/**
 * Check if a single item should be included based on filter criteria.
 * @param {Object} item - The item to evaluate
 * @param {Object} filters - Filter criteria object
 * @returns {boolean} True if item matches all filter criteria
 */
export function shouldIncludeItem(item, filters = {}) {
  if (!item) {
    return false
  }

  // Check freezer filter
  if (filters.freezer !== null && filters.freezer !== undefined) {
    if (item.freezer !== filters.freezer) {
      return false
    }
  }

  // Check storage type filter
  if (filters.storageType !== null && filters.storageType !== undefined) {
    if (item.storageType !== filters.storageType) {
      return false
    }
  }

  // Check storage types array filter (multiple types)
  if (filters.storageTypes && Array.isArray(filters.storageTypes)) {
    if (!filters.storageTypes.includes(item.storageType)) {
      return false
    }
  }

  // Check recent days filter
  if (filters.recentDays !== null && filters.recentDays !== undefined) {
    if (!isItemRecent(item, filters.recentDays)) {
      return false
    }
  }

  // Check search term filter
  if (filters.searchTerm && filters.searchTerm.trim() !== '') {
    const searchLower = filters.searchTerm.toLowerCase()
    const itemName = (item.name || '').toLowerCase()
    const itemDesc = (item.description || '').toLowerCase()

    if (!itemName.includes(searchLower) && !itemDesc.includes(searchLower)) {
      return false
    }
  }

  return true
}

/**
 * Filter an array of items based on multiple filter criteria.
 * @param {Array} items - Array of items to filter
 * @param {Object} filters - Filter criteria object
 * @returns {Array} Filtered array of items
 */
export function filterItems(items, filters = {}) {
  // Handle null/undefined input
  if (!items || !Array.isArray(items)) {
    return []
  }

  // If no filters provided, return all items
  if (!filters || Object.keys(filters).length === 0) {
    return items
  }

  return items.filter((item) => shouldIncludeItem(item, filters))
}

/**
 * Apply filters to items (alias for filterItems for semantic clarity).
 * @param {Array} items - Array of items to filter
 * @param {Object} filters - Filter criteria object
 * @returns {Array} Filtered array of items
 */
export function applyFilters(items, filters = {}) {
  return filterItems(items, filters)
}
