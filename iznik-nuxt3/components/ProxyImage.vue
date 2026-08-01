<template>
  <NuxtPicture
    :format="format"
    :fit="fit"
    :preload="preloadValue"
    provider="weserv"
    :src="fullSrc"
    :modifiers="modifiers"
    :class="(className ? className : '') + ' ' + isFluid"
    :alt="alt"
    :width="width"
    :height="height"
    :loading="preload ? 'eager' : loading"
    :sizes="sizes"
    :placeholder="placeholder"
    :img-attrs="imgAttrsComputed"
    @error="brokenImage"
  />
</template>
<script setup>
const props = defineProps({
  src: {
    type: String,
    required: true,
  },
  modifiers: {
    type: String,
    required: false,
    default: null,
  },
  preload: {
    type: Boolean,
    required: false,
    default: false,
  },
  loading: {
    type: String,
    required: false,
    default: 'lazy',
  },
  className: {
    type: String,
    required: false,
    default: null,
  },
  fluid: {
    type: Boolean,
    required: false,
    default: false,
  },
  fit: {
    type: String,
    required: false,
    default: 'inside',
  },
  format: {
    type: String,
    required: false,
    default: 'webp',
  },
  alt: {
    type: String,
    required: false,
    default: null,
  },
  width: {
    type: Number,
    required: false,
    default: null,
  },
  height: {
    type: Number,
    required: false,
    default: null,
  },
  sizes: {
    type: String,
    required: false,
    default: null,
  },
  placeholder: {
    type: String,
    required: false,
    default: null,
  },
  fetchpriority: {
    type: String,
    required: false,
    default: null,
  },
})

const isFluid = computed(() => (props.fluid ? 'img-fluid' : ''))

const preloadValue = computed(() => {
  if (!props.preload) return false
  if (props.fetchpriority) return { fetchPriority: props.fetchpriority }
  return true
})

const imgAttrsComputed = computed(() => {
  if (props.fetchpriority) return { fetchpriority: props.fetchpriority }
  return undefined
})

// Whether this fires depends on incidental test data (some rendered image's
// src happening to contain "gimg_0.jpg"), so it flips covered/uncovered
// between Playwright runs and repeatedly trips Coveralls' "coverage
// decreased" check on unrelated PRs - the same disease previously fixed for
// SpinButton.vue/LoginModal.vue (PR #910/#1007). Excluded from V8/Playwright
// coverage only; vitest (istanbul, which ignores v8 comments) still counts
// it, and ProxyImage.spec.js covers it deterministically.
/* v8 ignore next 5 */
if (process.client && props.src.includes('gimg_0.jpg')) {
  import('@sentry/browser').then((Sentry) => {
    Sentry.captureMessage('Broken image: ' + props.src)
  })
}

const fullSrc = computed(() => {
  let ret = props.src

  if (!ret.startsWith('http')) {
    ret = useRuntimeConfig().public.USER_SITE + ret
  }

  // If there is a ?, use encodeURI on that and everything after, otherwise those parameters get picked up
  // by wsrv rather than passed on
  if (ret.includes('?')) {
    const [base, query] = ret.split('?')
    const encodedQuery = encodeURIComponent(query)
    ret = base + '?' + encodedQuery
  }
  return ret
})

const emit = defineEmits(['error'])

/* Fires only when an image actually fails to load, which depends on network
   timing during an end-to-end run rather than on anything the tests control, so
   it flips in and out of Playwright coverage the same way the Sentry block above
   used to. Excluded from V8/Playwright coverage only - vitest still counts it,
   and ProxyImage.spec.js triggers a real error event on the img. */
/* v8 ignore next 3 */
function brokenImage(e) {
  emit('error', e)
}
</script>
