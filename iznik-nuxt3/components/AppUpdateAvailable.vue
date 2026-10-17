<template>
  <div v-if="mobileStore.appupdaterequired">
    <NoticeMessage variant="danger" class="mb-2">
      <p class="fw-bold mb-1">Update Required</p>
      <p class="mb-2">
        A new version of the app is required. Please update to continue using
        Freegle.
      </p>
      <b-button variant="primary" size="sm" @click="openStore">
        Update Now
      </b-button>
    </NoticeMessage>
  </div>
</template>

<script setup>
import { useMobileStore } from '~/stores/mobile'
import { APP_STORE_URL_ANDROID, APP_STORE_URL_IOS } from '~/constants'

const mobileStore = useMobileStore()

const openStore = () => {
  const url = mobileStore.isiOS ? APP_STORE_URL_IOS : APP_STORE_URL_ANDROID

  window.open(url, '_blank')
}
</script>
