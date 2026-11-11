<template>
  <div
    v-if="open"
    ref="root"
    class="app-interstitial"
    role="dialog"
    aria-modal="true"
    aria-labelledby="app-interstitial-title"
    tabindex="-1"
    @keydown="onKeydown"
  >
    <div class="app-interstitial__inner">
      <img
        class="app-interstitial__logo"
        src="/icon.png"
        alt=""
        width="72"
        height="72"
      />
      <h1 id="app-interstitial-title" class="app-interstitial__title">
        Get the Freegle app
      </h1>
      <ul class="app-interstitial__benefits">
        <li v-for="line in BENEFITS" :key="line" class="benefit">
          <v-icon icon="check" class="benefit__tick" />
          <span>{{ line }}</span>
        </li>
      </ul>
      <a
        ref="storeLink"
        class="app-interstitial__store"
        data-testid="app-interstitial-store"
        :href="storeUrl"
        target="_blank"
        rel="noopener noreferrer"
        @click="storeClick"
      >
        <img
          v-if="platform === 'ios'"
          src="/app-store-black-sm.png"
          alt="Download on the App Store"
          width="203"
          height="60"
        />
        <img
          v-else
          src="/en-play-badge.png"
          alt="Get it on Google Play"
          width="201"
          height="60"
        />
      </a>
      <button
        ref="continueButton"
        type="button"
        class="app-interstitial__continue"
        data-testid="app-interstitial-continue"
        @click="dismiss('continue')"
      >
        Continue to the website
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from '#imports'
import { useMiscStore } from '~/stores/misc'
import { useMobileStore } from '~/stores/mobile'
import { action } from '~/composables/useClientLog'
import { experimentVariant } from '~/composables/useExperiment'
import {
  appInterstitialOpen as open,
  appInterstitialPlatform as platform,
  closeAppInterstitial,
  considerAppInterstitial,
} from '~/composables/useAppInterstitial'
import { APP_STORE_URL_ANDROID, APP_STORE_URL_IOS } from '~/constants'

const BENEFITS = [
  'Never miss a message. Replies reach you as soon as they are sent.',
  'Get a notification when someone replies or offers you something.',
  'Post an item from your phone in a few taps.',
  'The app is free.',
]

const route = useRoute()
const root = ref(null)
const storeLink = ref(null)
const continueButton = ref(null)

const storeUrl = computed(() =>
  platform.value === 'ios' ? APP_STORE_URL_IOS : APP_STORE_URL_ANDROID
)

function details(extra = {}) {
  return { platform: platform.value, ...extra, variant: experimentVariant() }
}

function dismiss(how) {
  action('app_interstitial_dismissed', details({ how }))
  closeAppInterstitial()
}

function storeClick() {
  action('app_store_click', details())
}

function onKeydown(e) {
  if (e.key === 'Escape') {
    dismiss('escape')
  } else if (e.key === 'Tab') {
    // Two things can take focus, so Tab and Shift+Tab swap between them and never leave the page.
    const first = storeLink.value
    const last = continueButton.value
    if (e.shiftKey && [first, root.value].includes(document.activeElement)) {
      e.preventDefault()
      last.focus()
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault()
      first.focus()
    } else if (!root.value.contains(document.activeElement)) {
      e.preventDefault()
      first.focus()
    }
  }
}

watch(
  open,
  async (isOpen) => {
    if (isOpen) {
      document.body.style.overflow = 'hidden'
      await nextTick()
      root.value?.focus()
      action('app_interstitial_shown', details())
    } else {
      document.body.style.overflow = ''
    }
  },
  { flush: 'post' }
)

onMounted(() => {
  considerAppInterstitial({
    ua: navigator.userAgent,
    width: window.innerWidth,
    path: route.path,
    query: route.query,
    native: useMobileStore().isApp,
    modtools: useMiscStore().modtools,
  })
})

onBeforeUnmount(() => {
  if (open.value) document.body.style.overflow = ''
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'assets/css/_color-vars.scss';

.app-interstitial {
  position: fixed;
  inset: 0;
  z-index: 2000;
  overflow-y: auto;
  background: $color-green--bg-gradient;
  display: flex;
  padding: 1.5rem 1rem;
  outline: none;
}

.app-interstitial__inner {
  width: 100%;
  max-width: 420px;
  margin: auto;
  text-align: center;
}

.app-interstitial__logo {
  border-radius: 16px;
  margin-bottom: 1rem;
}

.app-interstitial__title {
  font-size: 1.75rem;
  font-weight: 700;
  color: $color-header;
  margin: 0 0 1.25rem;
}

.app-interstitial__benefits {
  list-style: none;
  padding: 0;
  margin: 0 0 1.75rem;
  text-align: left;
}

.benefit {
  display: flex;
  gap: 0.75rem;
  align-items: flex-start;
  font-size: 1.05rem;
  line-height: 1.35;
  color: $gray-800;
  padding: 0.5rem 0;
}

.benefit__tick {
  flex: 0 0 auto;
  margin-top: 0.2rem;
  color: $color-success;
}

.app-interstitial__store {
  display: inline-block;
  margin-bottom: 1.25rem;

  img {
    display: block;
    height: 56px;
    width: auto;
    max-width: 100%;
  }
}

.app-interstitial__continue {
  display: block;
  width: 100%;
  min-height: 48px;
  background: none;
  border: 2px solid $color-green--darker;
  border-radius: 8px;
  color: $color-green--darker;
  font-size: 1.05rem;
  font-weight: 600;
  cursor: pointer;

  &:focus-visible,
  &:hover {
    background: $color-green--light;
  }
}

.app-interstitial__store:focus-visible {
  outline: 3px solid $color-secondary;
  outline-offset: 4px;
}
</style>
