<template>
  <div class="qr-page py-4">
    <b-container>
      <h1 class="h3 mb-2">Freegle QR code generator</h1>

      <!-- This is a volunteer tool: only show it to mods (or above). -->
      <div v-if="mod">
        <p class="text-muted">
          Enter a link and we'll make a Freegle-branded QR code you can download
          as a PNG to print, share or stick up wherever you like.
        </p>

        <b-form-group label="Link (URL)" label-for="qr-url" class="qr-input">
          <b-form-input
            id="qr-url"
            v-model="url"
            type="url"
            placeholder="https://www.ilovefreegle.org"
            autocomplete="off"
          />
        </b-form-group>

        <div class="qr-stage my-3">
          <div ref="qrTarget" class="qr-target" :class="{ 'd-none': !hasCode }" />
          <p v-if="!url" class="text-muted mb-0">
            Enter a link above to see your QR code.
          </p>
          <p v-else-if="loadError" class="text-danger mb-0">
            Sorry — the QR generator failed to load. Please reload the page.
          </p>
        </div>

        <b-button variant="primary" :disabled="!hasCode" @click="download">
          Download PNG
        </b-button>
      </div>

      <!-- Logged in, but not a volunteer. -->
      <b-alert v-else-if="me" :model-value="true" variant="warning">
        Sorry, this page is only available to Freegle volunteers.
      </b-alert>

      <!-- Not logged in. -->
      <div v-else>
        <p class="text-muted">
          This page is only available to Freegle volunteers. Please log in to
          continue.
        </p>
        <b-button variant="primary" size="lg" @click="forceLogin">
          Log in to continue <v-icon icon="angle-double-right" />
        </b-button>
      </div>
    </b-container>
  </div>
</template>

<script setup>
import { ref, watch, nextTick, onMounted } from 'vue'
import { createFreegleQr } from '~/composables/useFreegleQr'
import { useMe } from '~/composables/useMe'
import { useAuthStore } from '~/stores/auth'

const { me, mod } = useMe()
const authStore = useAuthStore()

const url = ref('https://www.ilovefreegle.org')
const qrTarget = ref(null)
const hasCode = ref(false)
const loadError = ref(false)
let qrCode = null

function forceLogin() {
  authStore.forceLogin = true
}

// Build the generator only for a mod, and only once the target element is in
// the DOM. Both `mod` (auth loads async) and the v-if'd target may resolve
// after mount, so initialise on whichever happens last.
async function initQr() {
  if (qrCode || !mod.value || !qrTarget.value) return
  try {
    // qr-code-styling touches the DOM, so build it only on the client.
    qrCode = await createFreegleQr(url.value)
    qrCode.append(qrTarget.value)
    hasCode.value = !!url.value
  } catch (e) {
    loadError.value = true
  }
}

onMounted(() => {
  initQr()
})

watch(mod, (isMod) => {
  if (isMod) {
    nextTick(initQr)
  }
})

watch(url, (value) => {
  if (!qrCode) return
  qrCode.update({ data: value || ' ' })
  hasCode.value = !!value
})

function download() {
  if (qrCode) {
    qrCode.download({ name: 'freegle-qr', extension: 'png' })
  }
}
</script>

<style scoped lang="scss">
.qr-input {
  max-width: 32rem;
}
.qr-stage {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  min-height: 320px;
}
.qr-target {
  /* qr-code-styling injects a <canvas> here */
  line-height: 0;
}
</style>
