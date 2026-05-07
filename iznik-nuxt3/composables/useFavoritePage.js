import { useMiscStore } from '~/stores/misc'

export function useFavoritePage(pageName) {
  const miscStore = useMiscStore()

  // Always set — miscStore.set() is a cheap in-memory write (no API call),
  // so the former guard against redundant writes is unnecessary overhead.
  miscStore.set({
    key: 'lasthomepage',
    value: pageName,
  })
}
