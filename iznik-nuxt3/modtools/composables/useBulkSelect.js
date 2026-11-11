import { ref, computed } from 'vue'

// The most members one bulk action may cover. Chosen to match the request that
// prompted this: a spammer typically creates up to about fifty accounts at once,
// and a cap keeps a stray "select all" from acting on a whole list.
export const BULK_SELECT_LIMIT = 50

// A capped selection of ids, for acting on many list rows at once. Ids are kept in
// the order they were chosen. The caller decides what an id is (a spam_users row,
// a userid) and which rows may be selected.
export function useBulkSelect(limit = BULK_SELECT_LIMIT) {
  const selected = ref([])

  const count = computed(() => selected.value.length)
  const atLimit = computed(() => selected.value.length >= limit)

  function isSelected(id) {
    return selected.value.includes(id)
  }

  // Add or remove one id. Adding past the cap does nothing.
  function toggle(id) {
    if (isSelected(id)) {
      selected.value = selected.value.filter((i) => i !== id)
    } else if (!atLimit.value) {
      selected.value = [...selected.value, id]
    }
  }

  // Add every id given, in order, until the cap is reached. Ids already selected
  // stay where they were.
  function selectAll(ids) {
    const next = [...selected.value]

    for (const id of ids) {
      if (next.length >= limit) break
      if (!next.includes(id)) next.push(id)
    }

    selected.value = next
  }

  function clear() {
    selected.value = []
  }

  // Drop anything no longer in the list, for when rows are removed under us.
  function keepOnly(ids) {
    const keep = new Set(ids)
    selected.value = selected.value.filter((i) => keep.has(i))
  }

  return {
    selected,
    count,
    limit,
    atLimit,
    isSelected,
    toggle,
    selectAll,
    clear,
    keepOnly,
  }
}
