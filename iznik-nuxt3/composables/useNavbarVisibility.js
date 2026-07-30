import { computed } from 'vue'

// Whether the global navbar should render for the current route. Layouts that
// manage their own chrome (e.g. 'no-navbar' campaign/donation pages) opt out
// via definePageMeta({ layout: 'no-navbar' }).
export function useNavbarVisibility(route) {
  return computed(() => {
    const layout = route.meta?.layout || 'default'
    return layout !== 'no-navbar'
  })
}
