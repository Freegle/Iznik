import { ref } from 'vue'
import { useNuxtApp } from '#imports'

// Drives the logged-out bulk-offer availability page, authorised by a secret
// link token. Loads the catalogue and pushes each availability/count change to
// the token-gated API, re-syncing the list from the server response so the UI
// never shows an unsaved state as saved. Kept as a composable (not inline in the
// page) so the logic is unit-testable without a dynamic-route bracket import.
export function useBulkOfferUpdate(token) {
  const { $api } = useNuxtApp()

  const loading = ref(true)
  const notFound = ref(false)
  const offer = ref({ id: 0, subject: '', items: [] })
  const savingId = ref(null)
  const savedFlash = ref(false)
  const saveError = ref(false)

  let flashTimer = null
  function flashSaved() {
    saveError.value = false
    savedFlash.value = true
    if (flashTimer) clearTimeout(flashTimer)
    flashTimer = setTimeout(() => {
      savedFlash.value = false
    }, 2000)
  }

  async function load() {
    loading.value = true
    notFound.value = false
    try {
      const res = await $api.message.fetchBulkEditOffer(token)
      if (!res || res.ret !== 0 || !Array.isArray(res.items)) {
        notFound.value = true
      } else {
        offer.value = { id: res.id, subject: res.subject, items: res.items }
      }
    } catch (e) {
      notFound.value = true
    } finally {
      loading.value = false
    }
  }

  async function onUpdate({ itemid, available, quantity }) {
    const changes = {}
    if (available !== undefined) changes.available = available
    if (quantity !== undefined) changes.quantity = quantity
    savingId.value = itemid
    saveError.value = false
    try {
      const res = await $api.message.updateBulkEditItem(token, itemid, changes)
      if (res && res.ret === 0 && Array.isArray(res.items)) {
        offer.value = { ...offer.value, items: res.items }
        flashSaved()
      } else {
        saveError.value = true
      }
    } catch (e) {
      saveError.value = true
      // Re-sync from the server so the UI never shows an unsaved state as saved.
      await load()
    } finally {
      savingId.value = null
    }
  }

  return {
    loading,
    notFound,
    offer,
    savingId,
    savedFlash,
    saveError,
    load,
    onUpdate,
  }
}
