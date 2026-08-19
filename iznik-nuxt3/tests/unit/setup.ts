import { vi, beforeEach, afterEach } from 'vitest'
import { config } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import {
  ref,
  computed,
  watch,
  onMounted,
  onBeforeUnmount,
  defineComponent,
  defineAsyncComponent,
  h,
} from 'vue'

// ============================================
// VUE COMPOSITION API GLOBALS (for Nuxt auto-imports)
// ============================================
// Nuxt auto-imports Vue composition API functions. In tests, components that
// don't explicitly import these expect them to be globally available.
;(globalThis as Record<string, unknown>).ref = ref
;(globalThis as Record<string, unknown>).computed = computed
;(globalThis as Record<string, unknown>).watch = watch
;(globalThis as Record<string, unknown>).onMounted = onMounted
;(globalThis as Record<string, unknown>).onBeforeUnmount = onBeforeUnmount
;(globalThis as Record<string, unknown>).defineAsyncComponent = defineAsyncComponent


// ============================================
// GLOBAL VARIABLE MOCKS (for pinia-plugin-persistedstate)
// ============================================
// The compose store uses piniaPluginPersistedstate which is injected by Nuxt
// We need to provide a mock implementation for testing
;(globalThis as Record<string, unknown>).piniaPluginPersistedstate = {
  localStorage: () => ({
    getItem: () => null,
    setItem: () => {},
    removeItem: () => {},
  }),
  sessionStorage: () => ({
    getItem: () => null,
    setItem: () => {},
    removeItem: () => {},
  }),
}

// ============================================
// VUE WARNINGS HANDLER - Fail tests on warnings
// ============================================
const originalWarn = console.warn
console.warn = (...args: unknown[]) => {
  const message = typeof args[0] === 'string' ? args[0] : ''
  if (message.includes('[Vue warn]')) {
    // Throw an error to fail the test
    throw new Error(`Vue warning should not occur in tests: ${args.join(' ')}`)
  }
  originalWarn.apply(console, args)
}

// ============================================
// NUXT COMPOSABLE GLOBALS (for auto-imports)
// ============================================
// Mock useNuxtApp to provide $api and other injected services
const mockApi = {
  dashboard: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  message: { fetch: vi.fn().mockResolvedValue({ data: {} }), fetchMultiple: vi.fn().mockResolvedValue([]) },
  user: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  chat: { fetch: vi.fn().mockResolvedValue({ data: {} }), listChats: vi.fn().mockResolvedValue([]) },
  group: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  news: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  notification: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  story: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  tryst: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  volunteering: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  communityevent: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  shortlink: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  address: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  isochrone: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
  location: { fetch: vi.fn().mockResolvedValue({ data: {} }) },
}

const mockNuxtApp = {
  $api: mockApi,
  $dayjs: (val: unknown) => ({
    format: () => 'formatted',
    fromNow: () => 'now',
    isBefore: () => false,
    isAfter: () => true,
    diff: () => 0,
    add: () => ({ format: () => 'formatted' }),
    subtract: () => ({ format: () => 'formatted' }),
  }),
  $pinia: {},
}

;(globalThis as Record<string, unknown>).useNuxtApp = () => mockNuxtApp

// Mock useRoute
;(globalThis as Record<string, unknown>).__testUseRoute = () => ({
  params: {},
  query: {},
  path: '/',
  name: 'index',
  fullPath: '/',
})

// Mock useRouter
;(globalThis as Record<string, unknown>).__testUseRouter = () => ({
  push: vi.fn(),
  replace: vi.fn(),
  go: vi.fn(),
  back: vi.fn(),
  forward: vi.fn(),
  currentRoute: { value: { path: '/' } },
})

// Mock useRuntimeConfig
;(globalThis as Record<string, unknown>).useRuntimeConfig = () => ({
  public: {
    APIv2: 'http://apiv2.localhost',
    USER_SITE: '',
    GOOGLE_MAPS_KEY: 'test-key',
    GOOGLE_CLIENT_ID: 'test-client-id',
    FACEBOOK_APPID: 'test-fb-id',
    SENTRY_DSN: '',
    YAHOO_APPID: 'test-yahoo-id',
  },
})

// Mock navigateTo
;(globalThis as Record<string, unknown>).navigateTo = vi.fn()

// Mock defineNuxtPlugin (auto-imported by Nuxt, returns the plugin function as-is)
;(globalThis as Record<string, unknown>).defineNuxtPlugin = (plugin: unknown) => plugin

// Mock definePageMeta (Nuxt compiler macro, no-op in tests)
;(globalThis as Record<string, unknown>).definePageMeta = () => {}

// Mock useCookie
;(globalThis as Record<string, unknown>).useCookie = () => ref(null)

// Mock useState. Nuxt's useState is KEYED AND SHARED: two callers passing the same key get
// the same ref, which is the whole point of it and what composables built on it rely on
// (useReachOverlay hands the browse map a shape the distance slider fetched). Returning a
// fresh ref per call, as this did, silently turned every such composable into per-caller
// state, so a test could pass while the components never actually saw each other's writes.
//
// Real Nuxt scopes these per SSR request. Here the module is the scope, so a spec that
// shares a key across cases must reset between them: call clearNuxtState() in beforeEach.
const nuxtStateStore = new Map<string, unknown>()
;(globalThis as Record<string, unknown>).useState = (key: string, init?: () => unknown) => {
  if (!nuxtStateStore.has(key)) {
    nuxtStateStore.set(key, ref(typeof init === 'function' ? init() : null))
  }
  return nuxtStateStore.get(key)
}
;(globalThis as Record<string, unknown>).clearNuxtState = () => nuxtStateStore.clear()

// Mock useFetch
;(globalThis as Record<string, unknown>).useFetch = vi.fn().mockResolvedValue({ data: ref(null), pending: ref(false), error: ref(null) })

// Mock useAsyncData
;(globalThis as Record<string, unknown>).useAsyncData = vi.fn().mockResolvedValue({ data: ref(null), pending: ref(false), error: ref(null) })

// ============================================
// GLOBAL MOCKS (provided to template context)
// ============================================
// These functions are auto-imported by Nuxt but need to be provided as mocks in tests
config.global.mocks = {
  // Time formatting functions (from composables/useTimeFormat.js)
  datetimeshort: (val: string) => `formatted:${val}`,
  timeadapt: (val: string) => `adapted:${val}`,
  timeago: (val: string) => `ago:${val}`,
  dateonly: (val: string) => `dateonly:${val}`,
  dateshort: (val: string) => `dateshort:${val}`,
}

// ============================================
// GLOBAL STUBS (applied to all tests)
// ============================================
config.global.stubs = {
  // Stub bootstrap-vue-next components
  'b-button': {
    template:
      '<button :disabled="disabled" :class="variant"><slot /></button>',
    props: ['variant', 'disabled', 'size'],
  },
  'b-card': {
    template:
      '<div class="card"><slot /><slot name="header" /><slot name="footer" /></div>',
  },
  // Renders the `options` prop as real <option>s. Without them the stubbed <select> has
  // no matching option for any value, so setValue('x') lands as '' and a test cannot
  // drive the control at all — it silently exercises the wrong branch instead of the one
  // it names. The <slot> is kept for components that pass options as slot content.
  // Declares `change` and emits it with the VALUE, as bootstrap-vue-next does. Without
  // that declaration a parent's @change is treated as a native listener on the <select>
  // and receives an Event object instead, so a handler like
  // `onPresetChange(p) { if (p === 'custom') return }` can never take its early return in
  // tests — it silently runs the other branch.
  'b-form-select': {
    template:
      '<select class="form-select" :value="modelValue" @change="onStubChange">' +
      '<option v-for="o in stubOptions" :key="o.value" :value="o.value">{{ o.text }}</option>' +
      '<slot /></select>',
    props: ['modelValue', 'id', 'options'],
    emits: ['update:modelValue', 'change'],
    methods: {
      onStubChange(e: { target: { value: unknown } }) {
        this.$emit('update:modelValue', e.target.value)
        this.$emit('change', e.target.value)
      },
    },
    computed: {
      stubOptions() {
        return (this.options || []).map((o) =>
          o !== null && typeof o === 'object'
            ? { value: o.value, text: o.text ?? o.value }
            : { value: o, text: o }
        )
      },
    },
  },
  'b-form-input': {
    template:
      '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
    props: ['modelValue', 'id', 'type', 'placeholder', 'maxlength'],
  },
  'b-form-checkbox': {
    template:
      '<label class="form-check"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked); $emit(\'change\', $event.target.checked)" /><slot /></label>',
    props: ['modelValue'],
  },
  // b-form wraps the *-input/-select stubs below. Without it here, any component
  // laying its controls out in a <b-form> logs "Failed to resolve component",
  // which this file turns into a test failure.
  'b-form': {
    template: '<form @submit.prevent="$emit(\'submit\')"><slot /></form>',
    props: ['inline'],
  },
  'b-form-group': {
    template: '<div class="form-group"><slot /></div>',
    props: ['label', 'labelFor'],
  },
  'b-badge': {
    template: '<span class="badge" :class="variant"><slot /></span>',
    props: ['variant'],
  },
  'b-form-radio-group': {
    template: '<div class="radio-group"><slot /></div>',
    props: ['modelValue', 'options', 'stacked'],
  },
  'b-form-checkbox-group': {
    template: '<div class="checkbox-group"><slot /></div>',
    props: ['modelValue', 'options', 'stacked'],
  },
  'b-form-radio': {
    template:
      '<label class="form-radio"><input type="radio" :value="value" @change="$emit(\'update:modelValue\', value)" /><slot /></label>',
    props: ['modelValue', 'value'],
  },

  // Stub FontAwesome
  'v-icon': { template: '<i :class="icon"></i>', props: ['icon'] },

  // Stub NuxtLink
  NuxtLink: { template: '<a :href="to"><slot /></a>', props: ['to'] },

  // Stub Lazy-prefixed components — Nuxt's component resolver auto-imports
  // `LazyXxx` as `defineAsyncComponent(() => import('~/components/Xxx'))`
  // at build time; in the test runtime the resolver isn't wired, so we stub
  // the ones used as bare tags in chat / message / news components.
  // Class names match the non-Lazy stubs existing specs expect, so tests
  // that assert on `.profile-modal` / `.promise-modal` keep working.
  LazyProfileModal: {
    template: '<div class="profile-modal" :data-id="id" />',
    props: ['id'],
    methods: { show() {} },
  },
  LazyPromiseModal: {
    template: '<div class="promise-modal" :data-id="id" />',
    props: ['id', 'group'],
    emits: ['promised', 'reneged'],
    methods: { show() {} },
  },

  // Stub Spinner (auto-imported by Nuxt)
  Spinner: {
    template: '<div class="spinner-border" role="status" :style="spinnerStyle" />',
    props: ['size'],
    setup(props) {
      const spinnerStyle = computed(() => ({
        width: `${props.size || 50}px`,
        height: `${props.size || 50}px`,
      }))
      return { spinnerStyle }
    },
  },

  // Stub NuxtPicture (from @nuxt/image, auto-imported by Nuxt)
  NuxtPicture: {
    template:
      '<span><img :src="src" :alt="alt" :width="width" :height="height" :loading="loading" :placeholder="placeholder" @error="$emit(\'error\', $event)" /></span>',
    props: [
      'src',
      'alt',
      'format',
      'fit',
      'preload',
      'provider',
      'modifiers',
      'width',
      'height',
      'loading',
      'sizes',
      'placeholder',
    ],
    emits: ['error'],
  },

  // Stub ShowMore (components/ShowMore.vue): render every item via its #item (or
  // default) slot - a <div> per item in block mode, comma-separated spans in
  // inline mode - so group-list specs see the names. The real cap / "+N more" /
  // collapse behaviour is covered by ShowMore.spec.js, which mounts ShowMore
  // directly (a directly-mounted root is not replaced by a global stub).
  ShowMore: {
    props: ['items', 'limit', 'inline', 'keyfield'],
    template:
      '<span><component :is="inline ? \'span\' : \'div\'" v-for="(item, i) in (items || [])" :key="i"><span v-if="inline && i > 0">, </span><slot name="item" :item="item" :index="i"><slot :item="item" :index="i" /></slot></component></span>',
  },
}

// ============================================
// PINIA SETUP - Provide active Pinia for each test
// ============================================
// Components that use Pinia stores directly (e.g. useUserStore()) need
// an active Pinia instance. setActivePinia makes stores work even when
// the test doesn't explicitly install Pinia via mount plugins.
// Tests that provide their own Pinia (via vi.mock or global.plugins)
// take precedence — setActivePinia just ensures a fallback exists.

// ============================================
// RESET BETWEEN TESTS
// ============================================
beforeEach(() => {
  setActivePinia(createPinia())
  vi.clearAllMocks()
})

afterEach(() => {
  vi.useRealTimers()
})
