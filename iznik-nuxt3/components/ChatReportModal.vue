<template>
  <b-modal
    ref="modal"
    scrollable
    title="Oh dear..."
    size="lg"
    no-stacking
    modal-class="confirm-modal"
  >
    <template #default>
      <b-row>
        <b-col>
          <p>Sorry you're having trouble.</p>
          <div v-if="loading" class="text-center my-3">
            <b-spinner />
          </div>
          <template v-else>
            <template v-if="commonGroups.length">
              <h4>Which community is this about?</h4>
              <b-form-select
                v-model="groupid"
                class="mt-1 mb-1"
                data-testid="group-select"
              >
                <option :value="null">-- Please choose --</option>
                <option v-for="g in commonGroups" :key="g.id" :value="g.id">
                  {{ g.namedisplay }}
                </option>
              </b-form-select>
            </template>
            <p v-else class="text-muted">
              We'll pass this to our central volunteers who deal with this kind of
              thing.
            </p>
            <h4>Why are you reporting this?</h4>
            <b-form-select
              v-model="reason"
              class="mt-1 mb-1"
              data-testid="reason-select"
            >
              <option :value="null">-- Please choose --</option>
              <option value="Spam">It's Spam</option>
              <option value="Other">Something else</option>
            </b-form-select>
            <h4>What's wrong?</h4>
            <b-form-textarea
              v-model="comments"
              placeholder="Please tell us what's wrong.  This will go to our lovely volunteers, who will try to help you."
            />
          </template>
        </b-col>
      </b-row>
    </template>
    <template #footer>
      <b-button variant="white" @click="hide"> Close </b-button>
      <b-button variant="primary" :disabled="loading" @click="send">
        Send Report
      </b-button>
    </template>
  </b-modal>
</template>
<script setup>
import { ref, onMounted } from 'vue'
import { useChatStore } from '~/stores/chat'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  user: {
    type: Object,
    required: true,
  },
  chatid: {
    type: Number,
    required: true,
  },
})

const chatStore = useChatStore()
const { modal, hide } = useOurModal()

const groupid = ref(null)
const reason = ref(null)
const comments = ref(null)
const commonGroups = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const groups = await chatStore.commonGroups(props.chatid)
    commonGroups.value = Array.isArray(groups) ? groups : []
    if (commonGroups.value.length === 1) {
      groupid.value = commonGroups.value[0].id
    }
  } catch (e) {
    commonGroups.value = []
  } finally {
    loading.value = false
  }
})

async function send() {
  if (!reason.value) {
    return
  }

  if (commonGroups.value.length) {
    // Route to the chosen community's moderators (existing flow).
    if (!groupid.value || !comments.value) {
      return
    }
    const chatid = await chatStore.openChatToMods(groupid.value)
    await chatStore.report(chatid, reason.value, comments.value, props.chatid)
  } else {
    // No group in common: route to the central spam team. Comment optional.
    await chatStore.reportNoGroup(props.chatid, reason.value, comments.value || '')
  }

  hide()
}
</script>
