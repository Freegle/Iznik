/**
 * Mock implementation of Nuxt's #app and #imports modules
 * Used for unit testing components that use useNuxtApp(), useRouter(), etc.
 */

// Re-export Vue Composition API functions that Nuxt auto-imports
export {
  ref,
  computed,
  watch,
  onMounted,
  onBeforeUnmount,
  defineAsyncComponent,
  toRef,
  nextTick,
} from 'vue'

export function useNuxtApp() {
  return {
    $api: null, // Components should mock this in their tests
  }
}

// Default mock for useRuntimeConfig - tests override via globalThis, e.g.
// globalThis.__testRuntimeConfig = () => ({ public: { ISAPP: true } })
export function useRuntimeConfig() {
  if (typeof globalThis.__testRuntimeConfig === 'function')
    return globalThis.__testRuntimeConfig()
  return {
    public: {},
  }
}

// Stub for components that import useHead from #imports directly
export function useHead() {}

// Sets the HTTP status of the SSR response; a no-op on the client, and so here too.
// Tests that care what status a page asks for can spy via globalThis. Forwards
// exactly the arguments it was given, so a spy sees the real call shape.
export function setResponseStatus(...args) {
  if (typeof globalThis.__testSetResponseStatus === 'function')
    globalThis.__testSetResponseStatus(...args)
}

// Default mock for useRouter - tests override via vi.mock('#imports') or globalThis
export function useRouter() {
  if (typeof globalThis.__testUseRouter === 'function')
    return globalThis.__testUseRouter()
  return {
    push: () => {},
    replace: () => {},
    currentRoute: { value: { path: '/' } },
  }
}

// Default mock for useRoute - tests override via vi.mock('#imports') or globalThis
export function useRoute() {
  if (typeof globalThis.__testUseRoute === 'function')
    return globalThis.__testUseRoute()
  return { params: {}, query: {}, path: '/', name: 'index', fullPath: '/' }
}

// Mock for twem (emoji processing) - just pass through text
export function twem(text) {
  return text
}

// Nuxt plugin registration stub - just returns the plugin function as-is.
export function defineNuxtPlugin(plugin) {
  return plugin
}

// Nuxt compiler macro stub - a no-op in tests, matching the globalThis
// fallback used by pages that rely on Nuxt's auto-import instead of an
// explicit `import { definePageMeta } from '#imports'`.
export function definePageMeta() {}
