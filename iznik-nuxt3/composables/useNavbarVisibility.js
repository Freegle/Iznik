import { computed } from 'vue'
import { useUiMode } from '~/composables/useUiMode'

// Whether the global navbar should render for the current route. Layouts that
// manage their own chrome (e.g. 'no-navbar' campaign/donation pages) opt out
// via definePageMeta({ layout: 'no-navbar' }). Pages that can render as the chat
// shell (definePageMeta({ chatShell: true })) have no navbar while the member is
// on the chat version; the shell draws its own header.
export function useNavbarVisibility(route) {
  const { isChat } = useUiMode()
  return computed(() => {
    const layout = route.meta?.layout || 'default'
    if (layout === 'no-navbar') return false
    if (route.meta?.chatShell && isChat.value) return false
    return true
  })
}
