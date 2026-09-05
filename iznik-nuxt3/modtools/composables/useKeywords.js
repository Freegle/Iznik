import { computed } from 'vue'
import { group } from '~/composables/useModMessages'

// Read the selected community straight from the shared ref. This used to be a
// module-level computed whose getter called setupModMessages() - so a setup
// function ran from inside a computed, re-registering its watchers on every
// group change. Nothing here needs setup; it only needs to read the group.
function keyword(thegroup, which, fallback) {
  return thegroup?.settings?.keywords?.[which] || fallback
}

export function setupKeywords() {
  const typeOptions = computed(() => [
    { value: 'Offer', text: keyword(group.value, 'offer', 'OFFER') },
    { value: 'Wanted', text: keyword(group.value, 'wanted', 'WANTED') },
  ])

  return {
    typeOptions,
  }
}
