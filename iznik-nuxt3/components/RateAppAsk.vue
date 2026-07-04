<template>
  <div class="rate-app-ask">
    <h4 class="rate-app-ask__title">Enjoying Freegle?</h4>
    <p class="mb-2">
      If you like Freegle - or even if you don't - a quick rating and review
      really helps other people find us. It only takes a moment.
    </p>
    <p class="text-muted small mb-0">
      "Rate now" opens the {{ isiOS ? 'App Store' : 'Play Store' }}. The review
      option isn't always available there - that's not our fault!
    </p>
    <div class="rate-app-ask__actions">
      <b-button variant="outline-secondary" @click="notAgain">
        Don't ask again
      </b-button>
      <b-button variant="success" @click="rateNow"> Rate now </b-button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useMobileStore } from '~/stores/mobile'

const emit = defineEmits(['hide'])

const mobileStore = useMobileStore()
const isiOS = computed(() => mobileStore.isiOS)

// Store-review deep links. iOS opens the App Store review sheet directly;
// Android's market:// hands off to the Play Store app for the listing.
const IOS_REVIEW_URL =
  'https://apps.apple.com/gb/app/freegle/id970045029?action=write-review'
const ANDROID_REVIEW_URL = 'market://details?id=org.ilovefreegle.direct'

function remember() {
  try {
    window.localStorage.setItem('rateappnotagain', 'true')
  } catch (e) {
    // localStorage can throw in private mode - not worth failing the action over.
  }
}

function notAgain() {
  remember()
  emit('hide')
}

async function rateNow() {
  remember()
  const url = isiOS.value ? IOS_REVIEW_URL : ANDROID_REVIEW_URL
  try {
    // Dynamic import (matching ExternalLink.vue / stores/mobile.js) keeps the
    // native plugin out of the web/SSR bundle, so this single component works in
    // both the app build and the web build - no stub needed.
    const { AppLauncher } = await import('@capacitor/app-launcher')
    await AppLauncher.openUrl({ url })
  } catch (e) {
    // Launcher unavailable (e.g. plain web) - fall back to a normal navigation.
    window.open(url, '_blank')
  }
  emit('hide')
}
</script>

<style scoped lang="scss">
.rate-app-ask {
  background: white;
  padding: 1.25rem;
  text-align: center;
}

.rate-app-ask__title {
  margin-bottom: 0.75rem;
}

.rate-app-ask__actions {
  display: flex;
  gap: 0.5rem;
  justify-content: center;
  flex-wrap: wrap;
  margin-top: 1rem;
}
</style>
