<template>
  <div>
    <b-modal
      ref="modal"
      :title="'Post Summary for ' + (user ? user.displayname : '#' + userid)"
      size="lg"
      no-stacking
    >
      <template #default>
        <NoticeMessage v-if="!messages.length" variant="info" class="mb-2">
          There are no posts to show.
        </NoticeMessage>
        <b-row
          v-for="message in messages"
          :key="message.id"
          class="history-row small"
        >
          <b-col cols="8" sm="3" class="text-nowrap">
            <div>{{ datetimeshort(message.arrival) }}</div>
          </b-col>
          <b-col cols="4" sm="2" class="text-nowrap">
            <div>
              <v-icon icon="hashtag" scale="0.75" class="text-muted" />{{
                message.id
              }}
              <span v-if="message.repost">
                <v-icon
                  v-if="message.autorepost"
                  icon="sync"
                  class="text-danger"
                  title="Auto-repost"
                />
                <v-icon
                  v-else
                  icon="hand-paper"
                  class="text-danger"
                  title="Manual repost"
                />
              </span>
            </div>
          </b-col>
          <b-col cols="12" sm="7">
            <div>
              {{ message.subject }}
            </div>
            <div class="text-muted">
              <span v-if="message.outcome">Now {{ message.outcome }}</span
              ><span v-else-if="message.collection === 'Rejected'"
                >Rejected</span
              ><span v-else>Still open</span>
              <span v-if="message.collection === 'Pending'" class="text-danger">
                Pending</span
              >
            </div>
          </b-col>
        </b-row>
      </template>

      <template #footer>
        <b-button variant="primary" @click="hide"> Close </b-button>
      </template>
    </b-modal>
  </div>
</template>

<script setup>
import { computed, watch } from 'vue'
import { useUserStore } from '~/stores/user'
import { useOurModal } from '~/composables/useOurModal'

const props = defineProps({
  userid: {
    type: Number,
    required: true,
  },
  type: {
    type: String,
    required: false,
    default: null,
  },
})

const userStore = useUserStore()
const { modal, hide } = useOurModal()

const user = computed(() => userStore.byId(props.userid))

watch(
  () => props.userid,
  (uid) => {
    if (uid && !userStore.byId(uid)) {
      userStore.fetch(uid)
    }
  },
  { immediate: true }
)

const messages = computed(() => {
  let ret = []

  if (user.value && user.value.messagehistory) {
    ret = user.value.messagehistory.filter((message) => {
      return !props.type || props.type === message.type
    })

    ret.sort((a, b) => {
      return new Date(b.arrival).getTime() - new Date(a.arrival).getTime()
    })
  }

  return ret
})

function show() {
  modal.value.show()
}

defineExpose({ show })
</script>
