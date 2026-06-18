<template>
  <div
    class="clearance-candidate"
    :class="{ 'clearance-candidate--inactive': inactive }"
    :data-testid="'candidate-' + interest.userid"
  >
    <div class="clearance-candidate__who">
      <span class="clearance-candidate__name">{{ displayName }}</span>
      <UserRatings
        v-if="interest.userid"
        :id="interest.userid"
        class="clearance-candidate__ratings"
      />
    </div>

    <div class="clearance-candidate__detail small text-muted">
      <span class="me-2">wants {{ interest.quantity }}</span>
      <span v-if="interest.cancollect" class="clearance-candidate__collect">
        · can collect: {{ interest.cancollect }}
      </span>
    </div>

    <div class="clearance-candidate__badges">
      <b-badge :variant="stateVariant">{{ stateLabel }}</b-badge>
      <!-- Cross-item hint: this person is already collecting other items in the
           same clearance, so giving them this one saves a separate visit. -->
      <b-badge
        v-if="otherAllocations.length"
        variant="info"
        class="ms-1"
        :title="'Already collecting: ' + otherAllocations.join(', ')"
        data-testid="also-collecting"
      >
        <v-icon icon="truck" class="me-1" />also collecting
        {{ otherAllocations.length }} more
      </b-badge>
    </div>

    <div class="clearance-candidate__actions">
      <b-button
        v-for="a in actions"
        :key="a.state"
        :variant="a.variant"
        size="sm"
        :disabled="busy"
        class="ms-1 mb-1"
        :data-testid="'action-' + interest.userid + '-' + a.state"
        @click="setState(a.state)"
      >
        {{ a.label }}
      </b-button>
      <b-button
        v-if="interest.chatid"
        variant="link"
        size="sm"
        class="ms-1"
        :to="'/chats/' + interest.chatid"
        data-testid="open-chat"
      >
        <v-icon icon="comments" class="me-1" />Message
      </b-button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import UserRatings from '~/components/UserRatings'
import {
  clearanceStateLabel,
  clearanceStateVariant,
  clearanceActions,
  isInactiveState,
} from '~/composables/useClearance'

const props = defineProps({
  // The bulk offer this interest belongs to.
  messageId: { type: Number, required: true },
  // The catalogue item this interest is for.
  bulkitemid: { type: Number, required: true },
  // One interest row: { userid, quantity, cancollect, state, chatid }.
  interest: { type: Object, required: true },
  // Names of OTHER items in this clearance this person is already allocated, so
  // we can flag a single-visit collector.
  otherAllocations: { type: Array, default: () => [] },
})

const emit = defineEmits(['changed'])

const messageStore = useMessageStore()
const userStore = useUserStore()

const busy = ref(false)

const displayName = computed(
  () => userStore.byId(props.interest.userid)?.displayname || 'Freegler'
)
const stateLabel = computed(() => clearanceStateLabel(props.interest.state))
const stateVariant = computed(() => clearanceStateVariant(props.interest.state))
const actions = computed(() => clearanceActions(props.interest.state))
const inactive = computed(() => isInactiveState(props.interest.state))

// Transition this interest to a new state. The store refetches the message
// afterwards, so the parent's grouping/totals update reactively.
async function setState(state) {
  if (busy.value) return
  busy.value = true
  try {
    await messageStore.bulkInterestState(
      props.messageId,
      props.bulkitemid,
      props.interest.userid,
      state
    )
    emit('changed', { userid: props.interest.userid, state })
  } catch (e) {
    console.error('Failed to change interest state', e)
  } finally {
    busy.value = false
  }
}

defineExpose({ setState, actions, displayName, inactive, busy })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.clearance-candidate {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 0.6rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.clearance-candidate--inactive {
  opacity: 0.6;
}

.clearance-candidate__who {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex: 1 1 12rem;
  min-width: 0;
}

.clearance-candidate__name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.clearance-candidate__detail {
  flex: 1 1 10rem;
  min-width: 0;
}

.clearance-candidate__badges {
  flex: 0 0 auto;
}

.clearance-candidate__actions {
  flex: 1 1 auto;
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
}
</style>
