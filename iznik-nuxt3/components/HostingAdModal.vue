<template>
  <b-modal
    ref="modal"
    scrollable
    title="A greener home for your website"
    no-close-on-backdrop
    @hidden="onHidden"
  >
    <template #default>
      <div class="d-flex align-items-start gap-3">
        <img src="/krystal.png" alt="Krystal" class="logo flex-shrink-0" />
        <div>
          <p>It looks like you have a WordPress website.</p>
          <p>
            Krystal hosts Freegle for free. They're a UK company, run on green
            energy, and they'll move your site over for you.
          </p>
          <p class="mb-0">
            If you switch through this link, Freegle gets a small payment. It
            costs you nothing extra.
          </p>
        </div>
      </div>
    </template>
    <template #footer>
      <b-button variant="link" class="no-thanks" @click="noThanks">
        No thanks
      </b-button>
      <b-button
        variant="primary"
        class="take-a-look"
        :href="HOSTING_AD_LINK"
        target="_blank"
        rel="noopener sponsored"
        @click="clicked"
      >
        Take a look
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { onMounted, ref } from 'vue'
import { useUserStore } from '~/stores/user'
import { useOurModal } from '~/composables/useOurModal'
import { action } from '~/composables/useClientLog'
import { HOSTING_AD_LINK, closeHostingAd } from '~/composables/useHostingAd'

const userStore = useUserStore()
const { modal, hide } = useOurModal()

const closing = ref(false)

onMounted(() => {
  action('hosting_ad_shown')
})

// The server keeps the first click and the first dismissal on the member's exposure row; a failure
// there must not stop the link opening or the modal closing.
async function report(event) {
  try {
    await userStore.hostingAdEvent(event)
  } catch (e) {
    // Nothing to do: the click is also in the client log.
  }
}

function clicked() {
  action('hosting_ad_click')
  report('click')
  closing.value = true
  hide()
}

function closeWith(how) {
  if (closing.value) return
  closing.value = true
  action('hosting_ad_dismissed', { how })
  report('dismiss')
}

function noThanks() {
  closeWith('not_now')
  hide()
}

function onHidden() {
  // Escape, or the modal hidden by a navigation: still a dismissal.
  closeWith('hidden')
  closeHostingAd()
}
</script>
<style scoped lang="scss">
.logo {
  width: 48px;
  height: 48px;
  object-fit: contain;
}
</style>
