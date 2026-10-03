<template>
  <div v-if="event && event.pending">
    <b-card no-body>
      <b-card-header>
        <b-row>
          <b-col cols="6" md="4">
            Event <v-icon icon="hashtag" scale="0.75" class="text-muted" />{{
              event.id
            }}
          </b-col>
          <b-col cols="6" md="4">
            <span v-if="!event.userid"> Added by the system </span>
            <span v-else>
              {{ eventUser?.displayname }}
              <span class="text-muted">
                <v-icon icon="hashtag" scale="0.75" class="text-muted" />{{
                  event.userid
                }}
              </span>
            </span>
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body>
        <NoticeMessage v-if="heldError" variant="warning" class="mb-2">
          {{ heldError }}
        </NoticeMessage>
        <NoticeMessage
          v-if="eventUser?.postingstatus === 'PROHIBITED'"
          variant="danger"
          class="mb-2"
        >
          This member is set not to be able to post OFFERs/WANTEDs.
        </NoticeMessage>
        <CommunityEvent :id="event.id" :summary="false" />
      </b-card-body>
      <b-card-footer>
        <b-button variant="primary" class="me-1" @click="approve">
          <v-icon icon="check" /> Approve
        </b-button>
        <b-button variant="white" class="me-1" @click="edit">
          <v-icon icon="pen" /> Edit
        </b-button>
        <b-button variant="danger" class="me-1" @click="confirmDelete">
          <v-icon icon="trash-alt" /> Delete
        </b-button>
        <ChatButton
          v-if="event.userid"
          :userid="event.userid"
          title="Chat"
          variant="white"
          class="me-1"
        />
      </b-card-footer>
    </b-card>
    <CommunityEventModal
      v-if="showModal"
      :id="event.id"
      ref="eventmodal"
      :start-edit="true"
      :ismod="true"
      @hidden="showModal = false"
    />
    <ConfirmModal
      v-if="showDeleteConfirm"
      ref="confirmDeleteModal"
      title="Delete Event"
      message="Are you sure you want to delete this community event?"
      @confirm="deleteme"
      @hidden="showDeleteConfirm = false"
    />
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import { useCommunityEventStore } from '~/stores/communityevent'
import { useHeldNotice } from '~/composables/useHeldNotice'
import { useUserStore } from '~/stores/user'

const props = defineProps({
  eventid: {
    type: Number,
    required: true,
  },
})

const communityEventStore = useCommunityEventStore()
const { heldError, guardHold } = useHeldNotice()
const userStore = useUserStore()

const event = computed(() => communityEventStore.byId(props.eventid))

const showModal = ref(false)
const eventmodal = ref(null)
const showDeleteConfirm = ref(false)

// Fetch user details for display
watch(
  () => event.value?.userid,
  (userid) => {
    if (userid) {
      userStore.fetch(userid)
    }
  },
  { immediate: true }
)

const eventUser = computed(() => {
  return event.value?.userid ? userStore.byId(event.value.userid) : null
})

function edit() {
  showModal.value = true
  eventmodal.value?.show()
}

function confirmDelete() {
  showDeleteConfirm.value = true
}

function deleteme() {
  communityEventStore.delete(event.value.id)
  showDeleteConfirm.value = false
}

function approve() {
  guardHold(() =>
    communityEventStore.save({
      id: event.value.id,
      pending: false,
    })
  )
}
</script>
