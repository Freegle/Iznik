<template>
  <div class="admin-footer-preview mb-3">
    <p class="small fw-bold mb-1">
      Freegle adds this footer under your content in every copy:
    </p>
    <div class="footer-sample border rounded p-2 small text-center">
      <p class="mb-1">
        This email was sent to the member's address<br />
        Change your email settings &bull; Unsubscribe
      </p>
      <p class="mb-0">
        {{ FOOTER_CHARITY }}<br />
        {{ FOOTER_ADDRESS }}
      </p>
    </div>
    <p v-if="mjml !== null" class="small mt-2 mb-0">
      <ModClipboard :value="mjmlForLiveEditor(mjml)" class="me-1" />
      Copy the designed version with this footer, ready to paste into the
      <ExternalLink :href="MJML_TRY_IT">MJML live editor</ExternalLink>.
    </p>
  </div>
</template>
<script setup>
import {
  FOOTER_CHARITY,
  FOOTER_ADDRESS,
  MJML_TRY_IT,
  mjmlForLiveEditor,
} from '~/modtools/composables/useAdminContent'

defineProps({
  // The MJML part, to offer it for the live editor; null when composing plain text only.
  mjml: { type: String, default: null },
})
</script>
<style scoped>
.footer-sample {
  background-color: #f5f5f5;
  color: #666666;
}
</style>
