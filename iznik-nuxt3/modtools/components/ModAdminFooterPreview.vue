<template>
  <div class="admin-footer-preview mb-3">
    <p class="small fw-bold mb-1">
      Freegle adds this footer under your content in every copy:
    </p>
    <div class="footer-sample border rounded p-2 small text-center">
      <p v-if="groupid > 0" class="volunteers fst-italic mb-1">
        <span v-if="volunteersLine">{{ volunteersLine }}</span>
        <span v-else>No local volunteers will be named.</span>
      </p>
      <p v-else-if="groupid < 0" class="volunteers fst-italic mb-1">
        Each community's own volunteers are named here.
      </p>
      <p class="mb-1">
        This email was sent to the member's address<br />
        Change your email settings &bull; Unsubscribe
      </p>
      <p class="mb-0">
        {{ FOOTER_CHARITY }}<br />
        {{ FOOTER_ADDRESS }}
      </p>
    </div>
    <p class="small text-muted mt-1 mb-0 volunteers-help">
      So you don't need to add your names. Volunteers are named if they are a
      moderator here, have used Freegle in the last year, and have
      <em>Show me as a volunteer</em> switched on in their own Settings.
    </p>
    <p v-if="mjml !== null" class="small mt-2 mb-0">
      <ModClipboard :value="mjmlForLiveEditor(mjml, volunteers)" class="me-1" />
      Copy the designed version with this footer, ready to paste into the
      <ExternalLink :href="MJML_TRY_IT">MJML live editor</ExternalLink>.
    </p>
  </div>
</template>
<script setup>
import { computed, watch } from 'vue'
import { useGroupStore } from '~/stores/group'
import {
  FOOTER_CHARITY,
  FOOTER_ADDRESS,
  MJML_TRY_IT,
  mjmlForLiveEditor,
  localVolunteers,
  volunteersSentence,
} from '~/modtools/composables/useAdminContent'

const props = defineProps({
  // The MJML part, to offer it for the live editor; null when composing plain text only.
  mjml: { type: String, default: null },
  // The community it goes to, to name its volunteers; none or negative for a suggested ADMIN.
  groupid: { type: Number, default: null },
})

const groupStore = useGroupStore()

const volunteers = computed(() =>
  props.groupid > 0
    ? localVolunteers(groupStore.get(props.groupid)?.showmods)
    : []
)

const volunteersLine = computed(() => volunteersSentence(volunteers.value))

watch(
  () => props.groupid,
  (id) => {
    // A group fetched in a batch has showmods null, the same as one with no volunteers, so
    // fetch it on its own to be sure.
    if (id > 0) {
      groupStore.fetch(id, true)
    }
  },
  { immediate: true }
)
</script>
<style scoped>
.footer-sample {
  background-color: #f5f5f5;
  color: #666666;
}
</style>
