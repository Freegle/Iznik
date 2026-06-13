<template>
  <div class="qr-page py-4">
    <b-container>
      <h1 class="h3 mb-2">Freegle QR code generator</h1>
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
    </b-container>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { createFreegleQr } from '~/composables/useFreegleQr'

const url = ref('https://www.ilovefreegle.org')
const qrTarget = ref(null)
const hasCode = ref(false)
const loadError = ref(false)
let qrCode = null

onMounted(async () => {
  try {
    // qr-code-styling touches the DOM, so build it only on the client.
    qrCode = await createFreegleQr(url.value)
    qrCode.append(qrTarget.value)
    hasCode.value = !!url.value
  } catch (e) {
    loadError.value = true
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
