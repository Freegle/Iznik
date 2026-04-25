export function filterFeed(items, typeFilter) {
  if (!typeFilter || typeFilter === 'All') return items

  return items.filter((item) => {
    if (item.taken) return item.type === typeFilter
    return item.type === typeFilter
  })
}

export function searchFeed(items, query) {
  if (!query) return items

  const q = query.toLowerCase()
  return items.filter((item) => {
    const title = (item.title || '').toLowerCase()
    const desc = (item.description || '').toLowerCase()
    return title.includes(q) || desc.includes(q)
  })
}
