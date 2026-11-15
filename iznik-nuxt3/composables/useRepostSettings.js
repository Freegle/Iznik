import { computed } from 'vue'
import { useConfigStore } from '~/stores/config'
import { GROUP_REPOSTS } from '~/constants'

// The repost delays and counts are one system-wide setting, the same for every community: the
// `reposts` row of the config table, read through GET /config/reposts. A community's own
// settings.reposts is ignored. GROUP_REPOSTS holds the same four fallback values as the Go API
// (group.DefaultRepostSettings) and the batch (RepostSettings::FALLBACK); change all three together.
export const REPOSTS_CONFIG_KEY = 'reposts'

function wholeNumber(v) {
  if (typeof v === 'number') {
    return Number.isInteger(v) && v >= 0 ? v : null
  }

  if (typeof v === 'string' && /^\d+$/.test(v)) {
    return parseInt(v)
  }

  return null
}

// Turn what GET /config/reposts returned ([{ id, key, value }], value a JSON string; or just the
// object, or its JSON) into the four numbers. Anything missing or unusable falls back, key by key.
export function parseRepostSettings(raw) {
  let value = Array.isArray(raw) ? raw[0]?.value : raw

  if (typeof value === 'string') {
    try {
      value = JSON.parse(value)
    } catch (e) {
      value = null
    }
  }

  const settings = { ...GROUP_REPOSTS }

  if (value && typeof value === 'object') {
    for (const key of Object.keys(GROUP_REPOSTS)) {
      const n = wholeNumber(value[key])

      if (n !== null) {
        settings[key] = n
      }
    }
  }

  return settings
}

export function useRepostSettings() {
  const configStore = useConfigStore()

  const settings = computed(() =>
    parseRepostSettings(configStore.list[REPOSTS_CONFIG_KEY]?.values)
  )

  // Fetch the row once (the config store keeps it for ten minutes). A refusal or a failure leaves
  // the fallback values in place: this ships before the Go API that serves the key on a deploy.
  async function load() {
    try {
      await configStore.fetch(REPOSTS_CONFIG_KEY)
    } catch (e) {
      // Fallback values apply.
    }
  }

  return { settings, load }
}
