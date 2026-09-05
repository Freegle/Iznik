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
if (import.meta.client && props.src.includes('gimg_0.jpg')) {
  import('@sentry/browser').then((Sentry) => {
    Sentry.captureMessage('Broken image: ' + props.src)
  })
}

const fullSrc = computed(() => {
  let ret = props.src

  if (!ret.startsWith('http')) {
    ret = useRuntimeConfig().public.USER_SITE + ret
  }

  // The URL goes to the provider with its query string intact.
  //
  // It used to be percent-encoded here, to stop wsrv reading the image's own
  // parameters as its own. That failure is real - wsrv handed
  // url=https://www.gravatar.com/avatar/X?s=200&d=identicon&r=g keeps d and r
  // for itself and asks Gravatar for ?s=200 alone - but encoding it here fixes
  // it in the wrong place, because the escaping then survives all the way to
  // the ORIGIN. Gravatar received one parameter literally named
  // "s=200&d=identicon&r=g", found no d, and served its own logo: a blue disc
  // with a white ring, which is what members were reporting when they said
  // their avatar had changed to a rotated G or an on-off symbol. It hit every
  // Gravatar profile whose owner has no Gravatar account, so they all showed
  // the same picture instead of their own identicon.
  //
  // The provider already encodes the whole URL exactly once when it builds
  // ?url=, which is what keeps the parameters out of wsrv's hands AND delivers
  // them intact to the origin. Encoding them again here is what broke them.
  return ret
})

const emit = defineEmits(['error'])

function brokenImage(e) {
  emit('error', e)
}
</script>
