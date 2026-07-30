<template>
  <div
    class="cand"
    :class="{ 'cand--inactive': inactive, 'cand--needsyou': needsYou }"
    :data-testid="'candidate-' + interest.userid"
  >
    <!-- Score (left): click to reveal the Helper's breakdown. -->
    <button
      v-if="scoreText"
      type="button"
      class="cand__score"
      :title="'Helper priority score — click for the breakdown'"
      data-testid="helper-score"
      @click="showBreakdown = !showBreakdown"
    >
      <v-icon icon="star" class="cand__star" />
      <span class="cand__scoreval">{{ scoreText }}</span>
    </button>
    <span v-else class="cand__score cand__score--none">—</span>

    <!-- Who: name, reputation, single-visit hint. -->
    <div class="cand__who">
      <span class="cand__name">{{ displayName }}</span>
      <span
        v-if="reputation"
        class="cand__rep small"
        :title="reputation.title"
        data-testid="reputation"
      >
        <span v-if="reputation.up" class="text-success me-1"
          ><v-icon icon="thumbs-up" /> {{ reputation.up }}</span
        >
        <span v-if="reputation.down" class="text-danger"
          ><v-icon icon="thumbs-down" /> {{ reputation.down }}</span
        >
      </span>
      <b-badge
        v-if="otherAllocations.length"
        variant="info"
        class="cand__also"
        :title="'Already collecting: ' + otherAllocations.join(', ')"
        data-testid="also-collecting"
      >
        <v-icon icon="truck" class="me-1" />also collecting
        {{ otherAllocations.length }} more
      </b-badge>
    </div>

    <!-- Wants: editable quantity. -->
    <label class="cand__wants">
      <span class="cand__collabel">Wants</span>
      <b-form-input
        v-model.number="qty"
        type="number"
        min="0"
        size="sm"
        class="cand__qty"
        :disabled="busy"
        data-testid="wants-qty"
        @change="saveQty"
      />
    </label>

    <!-- Collection times + transport. -->
    <div class="cand__collect">
      <span class="cand__collabel">Can collect</span>
      <span v-if="interest.cancollect" class="cand__collectval">{{
        interest.cancollect
      }}</span>
      <span v-else class="cand__collectval text-muted">not said yet</span>
    </div>

    <!-- One merged status + AI indicator. -->
    <div class="cand__status">
      <b-badge
        v-if="status"
        :variant="status.variant"
        data-testid="cand-status"
        >{{ status.label }}</b-badge
      >
      <b-badge
        v-if="aiSent"
        variant="light"
        class="cand__ai"
        title="Freegle Helper has been replying to this person for you"
        data-testid="ai-badge"
      >
        <v-icon icon="robot" class="me-1" />AI replying
      </b-badge>
    </div>

    <!-- Actions. -->
    <div class="cand__actions">
      <b-button
        v-for="a in actions"
        :key="a.state"
        :variant="a.variant"
        size="sm"
        :disabled="busy"
        class="ms-1 mb-1"
        :title="actionHint(a.state)"
        :data-testid="'action-' + interest.userid + '-' + a.state"
        @click="setState(a.state)"
      >
        {{ a.label }}
      </b-button>
      <b-button
        variant="outline-primary"
        size="sm"
        class="ms-1 mb-1"
        :disabled="!interest.chatid"
        :title="
          interest.chatid ? 'Open the chat with this person' : 'No chat yet'
        "
        data-testid="open-chat"
        @click="showChat = true"
      >
        <v-icon icon="comments" class="me-1" />Open chat
      </b-button>
    </div>

    <!-- Chat opens in a modal so the offerer stays on the management page. -->
    <ClearanceChatModal
      v-if="interest.chatid"
      v-model="showChat"
      :chatid="interest.chatid"
      :title="'Chat with ' + displayName"
    />

    <!-- Escalation reason: why the AI handed this conversation to you. -->
    <div
      v-if="needsYou && note"
      class="cand__note"
      data-testid="escalation-note"
    >
      <v-icon icon="circle-exclamation" class="me-1" />{{ note }}
    </div>

    <!-- Score breakdown, revealed on click. -->
    <div
      v-if="showBreakdown && breakdown.length"
      class="cand__breakdown"
      data-testid="score-breakdown"
    >
      <span class="cand__bdtitle small text-muted">Why this score:</span>
      <span v-for="f in breakdown" :key="f.k" class="cand__bditem small">
        {{ f.k }} <strong>+{{ f.v }}</strong>
      </span>
    </div>
    <p
      v-if="excludedNotTold"
      class="cand__excluded small text-muted"
      data-testid="excluded-note"
    >
      Not told yet — they're only notified when you finish allocating, so you
      can still change your mind.
    </p>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import ClearanceChatModal from '~/components/ClearanceChatModal'
import {
  clearanceActions,
  isInactiveState,
  candidateStatus,
  isNeedsYouState,
  formatScore,
} from '~/composables/useClearance'

const props = defineProps({
  messageId: { type: Number, required: true },
  bulkitemid: { type: Number, required: true },
  interest: { type: Object, required: true },
  otherAllocations: { type: Array, default: () => [] },
  // Freegle Helper FSM state for this candidate/item (null when Helper is off).
  helperState: { type: String, default: null },
  score: { type: [Number, String], default: null },
  // JSON string: { factor: contribution, ... }
  scoreBreakdown: { type: String, default: null },
  // Whether the Helper has messaged this person.
  aiSent: { type: Boolean, default: false },
  // Escalation reason, when the AI handed the chat to the human.
  note: { type: String, default: null },
})

const emit = defineEmits(['changed'])

const messageStore = useMessageStore()
const userStore = useUserStore()

const busy = ref(false)
const showBreakdown = ref(false)
const showChat = ref(false)
const qty = ref(props.interest.quantity)
watch(
  () => props.interest.quantity,
  (v) => (qty.value = v)
)

const displayName = computed(
  () => userStore.byId(props.interest.userid)?.displayname || 'Freegler'
)

const reputation = computed(() => {
  const r = userStore.byId(props.interest.userid)?.info?.ratings
  if (!r || (!r.Up && !r.Down)) return null
  return {
    up: r.Up || 0,
    down: r.Down || 0,
    title: `${r.Up || 0} thumbs up, ${r.Down || 0} down`,
  }
})

const status = computed(() =>
  candidateStatus(props.interest.state, props.helperState)
)
const actions = computed(() => clearanceActions(props.interest.state))
const inactive = computed(() => isInactiveState(props.interest.state))
const needsYou = computed(() => isNeedsYouState(props.helperState))
const scoreText = computed(() => formatScore(props.score))
const excludedNotTold = computed(() => props.interest.state === 'Rejected')

const breakdown = computed(() => {
  if (!props.scoreBreakdown) return []
  try {
    const obj = JSON.parse(props.scoreBreakdown)
    return Object.entries(obj).map(([k, v]) => ({ k, v }))
  } catch (e) {
    return []
  }
})

function actionHint(state) {
  switch (state) {
    case 'Reserved':
      return 'Allocate this item to them and send the collection details'
    case 'Rejected':
      return "Set aside — they're only told when you finish allocating"
    case 'Interested':
      return 'Put them back in the running'
    case 'Collected':
      return 'Mark as collected'
    default:
      return ''
  }
}

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

// Save an edited quantity on behalf of the replier (preserving their collection
// time and any allocation state).
async function saveQty() {
  const n = parseInt(qty.value, 10)
  if (busy.value || isNaN(n) || n === props.interest.quantity) return
  busy.value = true
  try {
    await messageStore.bulkInterest(
      props.messageId,
      [
        {
          bulkitemid: props.bulkitemid,
          quantity: n,
          cancollect: props.interest.cancollect,
        },
      ],
      props.interest.userid
    )
    emit('changed', { userid: props.interest.userid, quantity: n })
  } catch (e) {
    console.error('Failed to update quantity', e)
  } finally {
    busy.value = false
  }
}

defineExpose({
  setState,
  saveQty,
  qty,
  actions,
  displayName,
  inactive,
  needsYou,
  busy,
  status,
  scoreText,
  breakdown,
  showBreakdown,
  showChat,
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.cand {
  display: grid;
  grid-template-columns: 3.2rem minmax(9rem, 1fr) auto minmax(7rem, 12rem) auto auto;
  align-items: center;
  column-gap: 0.75rem;
  row-gap: 0.25rem;
  padding: 0.45rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.cand--inactive {
  opacity: 0.65;
}

.cand--needsyou {
  background-color: lighten(#dc3545, 42%);
  border-radius: 5px;
  padding-left: 0.4rem;
  padding-right: 0.4rem;
}

/* Score chip — clearly clickable. */
.cand__score {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  border: 1px solid $color-gray--light;
  background: white;
  border-radius: 1rem;
  padding: 0.05rem 0.5rem;
  font-weight: 700;
  cursor: pointer;
}
.cand__score:hover {
  border-color: $color-green--dark;
}
.cand__score--none {
  border: none;
  background: none;
  color: $color-gray--light;
  cursor: default;
}
.cand__star {
  color: #f0ad4e;
}

.cand__who {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.35rem;
  min-width: 0;
}
.cand__name {
  font-weight: 600;
  /* Long names / unbroken tokens (e.g. org names) wrap within the cell
     instead of overflowing into the quantity column. */
  overflow-wrap: anywhere;
  min-width: 0;
}
.cand__rep {
  white-space: nowrap;
}

.cand__wants {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  margin: 0;
}
.cand__qty {
  width: 4rem;
}

.cand__collect {
  min-width: 0;
}
.cand__collectval {
  display: block;
  font-size: 0.85rem;
}

/* Column labels: hidden on wide screens (columns are self-evident), shown when
   the row stacks on mobile. */
.cand__collabel {
  display: none;
  font-size: 0.7rem;
  text-transform: uppercase;
  color: $color-gray--normal;
  margin-right: 0.25rem;
}

.cand__status {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  align-items: flex-start;
}
.cand__ai {
  border: 1px solid $color-gray--light;
}

.cand__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.cand__note {
  grid-column: 1 / -1;
  color: #b02a37;
  font-size: 0.85rem;
}
.cand__breakdown,
.cand__excluded {
  grid-column: 1 / -1;
}
.cand__breakdown {
  display: flex;
  flex-wrap: wrap;
  gap: 0.6rem;
  padding: 0.2rem 0;
}

/* Stack into labelled rows on narrow screens. */
@media (max-width: 767.98px) {
  .cand {
    grid-template-columns: auto 1fr;
  }
  .cand__who {
    grid-column: 2;
  }
  .cand__wants,
  .cand__collect,
  .cand__status,
  .cand__actions {
    grid-column: 1 / -1;
  }
  .cand__collabel {
    display: inline;
  }
  .cand__actions {
    justify-content: flex-start;
  }
}
</style>
