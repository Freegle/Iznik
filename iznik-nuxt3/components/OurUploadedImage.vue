<template>
  <NuxtPicture
    v-if="show"
    :key="src + '-' + modifiers"
    :format="format"
    :fit="fit"
    :preload="preload"
    :provider="chooseProvider"
    :src="chooseSrc"
    :modifiers="modString"
    :class="imageClasses"
    :alt="alt"
    :width="width"
    :height="height"
    :loading="preload ? 'eager' : loading"
    :sizes="sizes"
    :placeholder="placeholder"
    @error="brokenImage"
  />
  <!-- TEMP DIAGNOSTIC (modtools only): plain <img> with the constructed weserv URL.
       Lets us see on-device whether the URL is loadable when NuxtPicture renders nothing.
       Remove once image-rendering bug is identified. -->
  <span
    v-if="isMT"
    style="
      display: inline-block;
      vertical-align: top;
      background: #fff8e1;
      border: 2px solid #ff9800;
      padding: 2px;
      max-width: 120px;
      font-size: 9px;
      line-height: 1.1;
      color: #c00;
      word-break: break-all;
    "
  >
    <span>src={{ src }}</span><br />
    <span>prov={{ chooseProvider }} cs={{ chooseSrc }}</span><br />
    <span>L={{ diagLoad }} E={{ diagError }}</span><br />
    <img
      v-if="diagUrl"
      :src="diagUrl"
      alt="diag"
      style="max-width: 80px; max-height: 80px; display: block"
      @load="diagLoad = true"
      @error="diagError = true"
    />
    <span v-if="diagUrl" style="font-size: 7px">{{ diagUrl }}</span>
  </span>
</template>
<script setup>
import { ref, computed, onBeforeUnmount } from 'vue'
import { useRuntimeConfig } from '#imports'
import { captureMessage as sentryCaptureMessage } from '@sentry/browser'

const props = defineProps({
  src: {
    type: String,
    required: true,
  },
  modifiers: {
    type: [String, Object],
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
    default: 'cover',
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
})

const isFluid = computed(() => (props.fluid ? 'img-fluid' : ''))

const isAI = computed(() => {
  if (!props.modifiers) return false
  const mods =
    typeof props.modifiers === 'string'
      ? JSON.parse(props.modifiers)
      : props.modifiers
  return mods?.ai === true
})

const imageClasses = computed(() => {
  const classes = []
  if (props.className) classes.push(props.className)
  if (isFluid.value) classes.push(isFluid.value)
  if (isAI.value) classes.push('ai-image-duotone')
  return classes.join(' ')
})

if (process.client && props.src?.includes('gimg_0.jpg')) {
  sentryCaptureMessage('Broken image: ' + props.src)
}

const emit = defineEmits(['error'])

// If the source contains a dash then the first part is the provider and the second part the source.
const chooseProvider = computed(() => {
  const p = props.src.indexOf('freegletusd-')

  if (p !== -1) {
    // For now we only have one such option - freegletusd, which we render using Nuxt Image's weserve provider.
    return 'weserv'
  } else {
    // Defaults to uploadcare.
    return 'uploadcare'
  }
})

const chooseSrc = computed(() => {
  const p = props.src.indexOf('freegletusd-')

  if (p !== -1) {
    // For now we only have one such option - freegletusd, which we render by pointing at our upload server
    return props.src.substring(p + 12)
  }

  return props.src
})

const show = ref(true)

// TEMP DIAGNOSTIC (modtools only) — see template.
const runtimeConfig = useRuntimeConfig()
const isMT = computed(() => runtimeConfig.public?.SITE === 'MT')
const diagLoad = ref(false)
const diagError = ref(false)
const diagUrl = computed(() => {
  if (!isMT.value) return null
  if (chooseProvider.value !== 'weserv') return null
  const base = (runtimeConfig.public?.TUS_UPLOADER || '').replace(':8080', '')
  const delivery = runtimeConfig.public?.IMAGE_DELIVERY || ''
  if (!base || !delivery || !chooseSrc.value) return null
  return `${delivery}/?url=${base}/${chooseSrc.value}`
})

// Track component teardown so `brokenImage` can tell a real load failure apart
// from an <img> error fired while Vue is unmounting the node (infinite-scroll
// virtualisation, keepAlive eviction). The element-level `isConnected` check
// in `brokenImage` covers detachment after the hook fires; this ref covers the
// narrow window where the hook has run but the DOM is still attached.
const isUnmounting = ref(false)
onBeforeUnmount(() => {
  isUnmounting.value = true
})

const modString = computed(() => {
  if (!props.modifiers) {
    return null
  }

  if (typeof props.modifiers === 'string') {
    return JSON.parse(props.modifiers)
  } else {
    return props.modifiers
  }
})
function brokenImage(e) {
  // Skip processing when the error was caused by a cancelled request rather than
  // a real load failure: the component is tearing down, or the <img> node has
  // already been detached from the DOM (common with infinite-scroll feeds on
  // mobile / Capacitor, where scrolling removes the node mid-fetch and Chromium
  // aborts the request, firing `error` on the detached element).
  if (isUnmounting.value || e?.target?.isConnected === false) {
    return
  }

  console.log('Our uploaded image broken', props.src)
  emit('error', e)
  show.value = false

  sentryCaptureMessage('Failed to fetch image ' + props.src)
}
</script>
