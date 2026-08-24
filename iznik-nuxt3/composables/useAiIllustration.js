/**
 * useAiIllustration — composable for fetching and storing an AI illustration
 * for a give/mobile post when the member has not uploaded their own photo.
 *
 * Extracted from pages/give/mobile/details.vue so the fetch+store logic can
 * be unit-tested independently of Nuxt's component runtime.
 */

/**
 * Fetch an AI illustration for `itemName` (if one is cached on the server),
 * immediately materialise it as a real server-side attachment via POST /image,
 * and write the result into the compose store.
 *
 * @param {object} opts
 * @param {object}   opts.api            – result of api(runtimeConfig)
 * @param {object}   opts.composeStore   – Pinia compose store instance
 * @param {number}   opts.messageId      – compose store message id
 * @param {string}   opts.itemName       – item name to fetch illustration for
 * @param {object[]} opts.attachments    – current attachments for the message
 * @param {import('vue').Ref<boolean>}  opts.fetchingIllustration – reactive flag
 * @param {import('vue').Ref<string|null>} opts.lastFetchedItem   – last fetched item name
 * @returns {Promise<void>}
 */
export async function fetchAiIllustration({
  api,
  composeStore,
  messageId,
  itemName,
  attachments,
  fetchingIllustration,
  lastFetchedItem,
}) {
  if (!itemName || !itemName.trim()) return

  const trimmedItem = itemName.trim()

  // Only fetch if there are no real photos, we haven't already fetched for
  // this item name, and we're not currently fetching.
  const realPhotos = attachments.filter(
    (a) => !a.externalmods || a.externalmods.ai !== true
  )

  if (
    realPhotos.length > 0 ||
    fetchingIllustration.value ||
    lastFetchedItem.value === trimmedItem
  ) {
    return
  }

  fetchingIllustration.value = true
  lastFetchedItem.value = trimmedItem

  try {
    const illustration = await api.message.getIllustration(trimmedItem)

    if (illustration && illustration.externaluid) {
      // Check again that no real photos were added while we were fetching.
      const currentRealPhotos = attachments.filter(
        (a) => !a.externalmods || a.externalmods.ai !== true
      )

      if (currentRealPhotos.length === 0) {
        // Remove any existing AI illustration first.
        const filteredAtts = attachments.filter(
          (a) => !a.externalmods || a.externalmods.ai !== true
        )

        // Materialise as a real server-side attachment immediately so the id
        // stored in the compose store is always a numeric uint64.  If this
        // POST fails we fall back to no id (null); createDraft will retry via
        // its own image.post() call using ouruid as a safety net.
        let realId = null
        try {
          const result = await api.image.post({
            externaluid: illustration.externaluid,
            externalmods: { ai: true },
          })
          if (result && typeof result.id === 'number') {
            realId = result.id
          }
        } catch (e) {
          console.error('Failed to pre-create AI illustration attachment:', e)
        }

        composeStore.setAttachmentsForMessage(messageId, [
          ...filteredAtts,
          {
            id: realId, // numeric (or null if POST failed — createDraft handles this)
            path: illustration.url,
            paththumb: illustration.url,
            ouruid: illustration.externaluid,
            externalmods: { ai: true },
            isAiIllustration: true,
          },
        ])
      }
    }
  } catch (e) {
    console.log('Failed to fetch AI illustration:', e.message)
  } finally {
    fetchingIllustration.value = false
  }
}
