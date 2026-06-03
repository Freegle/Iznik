<template>
  <div v-if="items.length" class="bulkitems mt-3">
    <h4 class="bulkitems__heading">
      <v-icon icon="gift" class="me-1 text-success" />
      {{ items.length }} items in this offer
    </h4>
    <p v-if="!isOwner" class="text-muted small mb-2">
      Turn on the items you'd like and choose how many.
    </p>

    <ul class="bulkitems__list">
      <li v-for="(item, idx) in items" :key="item.id" class="bitem">
        <div class="bitem__photo">
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            :alt="item.name"
            loading="lazy"
          />
          <v-icon v-else icon="image" class="bitem__nophoto" />
        </div>

        <div class="bitem__detail">
          <div class="bitem__name">
            <span class="bitem__ref">#{{ idx + 1 }}</span> {{ item.name }}
          </div>
          <div class="bitem__meta">
            <b-badge variant="light" class="me-1"
              >{{ item.quantity }} available</b-badge
            >
            <b-badge
              v-if="item.condition && item.condition !== 'Unknown'"
              variant="info"
              class="me-1"
            >
              {{ conditionLabel(item.condition) }}
            </b-badge>
            <span v-if="item.dimensions" class="text-muted small">{{
              item.dimensions
            }}</span>
          </div>

          <!-- Owner sees a live interest summary. -->
          <div v-if="isOwner" class="bitem__interest small">
            <span v-if="item.interestcount">
              <strong>{{ item.interestcount }}</strong>
              interested · <strong>{{ item.interestedquantity }}</strong>
              requested
            </span>
            <span v-else class="text-muted">No interest yet</span>
          </div>
        </div>

        <!-- Recipient: a standard on/off toggle; quantity appears once it's on. -->
        <div v-if="!isOwner && !message.successful" class="bitem__pick">
          <b-form-checkbox
            v-model="picks[item.id].checked"
            switch
            size="lg"
            class="bitem__toggle"
            :data-testid="'pick-' + item.id"
            @change="onCheck(item)"
          />
          <div class="bitem__qty">
            <NumberIncrementDecrement
              v-if="picks[item.id].checked"
              v-model="picks[item.id].quantity"
              :min="1"
              :max="item.quantity"
              size="sm"
              label="How many?"
              :label-s-r-only="true"
            />
          </div>
        </div>
      </li>
    </ul>

    <div v-if="!isOwner && !message.successful" class="bulkitems__actions">
      <b-form-group label="Which collection time suits you?" class="mb-2">
        <b-form-radio-group
          v-if="slots.length"
          v-model="cancollect"
          :options="slotOptions"
          stacked
          data-testid="slot-picker"
        />
        <b-form-input
          v-else
          v-model="cancollect"
          placeholder="When could you collect?"
          maxlength="255"
        />
      </b-form-group>

      <SpinButton
        variant="primary"
        :disabled="!canRegister"
        icon-name="check"
        :label="submitted ? 'Update my interest' : 'Register interest'"
        data-testid="register-interest"
        @handle="submit"
      />
      <NoticeMessage v-if="submitted" variant="success" class="mt-2">
        Thanks! We've let the giver know which items you're interested in.
      </NoticeMessage>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import NumberIncrementDecrement from '~/components/NumberIncrementDecrement'
import SpinButton from '~/components/SpinButton'
import NoticeMessage from '~/components/NoticeMessage'

const props = defineProps({
  id: { type: Number, required: true },
})

const messageStore = useMessageStore()
const authStore = useAuthStore()

const message = computed(() => messageStore.byId(props.id) || {})
const items = computed(() => message.value?.bulkitems || [])
const slots = computed(() => message.value?.bulkslots || [])
const slotOptions = computed(() =>
  slots.value.map((s) => ({ value: s, text: s }))
)
const isOwner = computed(
  () =>
    !!authStore.user &&
    !!message.value &&
    authStore.user.id === message.value.fromuser
)

const submitted = ref(false)
const cancollect = ref('')

// Per-item pick state, seeded from any interest the user already expressed.
const picks = reactive({})

function seedPicks() {
  for (const item of items.value) {
    if (!item.id) continue
    const yi = item.yourinterest
    const existing = picks[item.id]
    if (!existing) {
      picks[item.id] = {
        checked: !!yi && yi.state !== 'Withdrawn',
        quantity: yi && yi.quantity > 0 ? yi.quantity : 1,
      }
      if (yi && yi.cancollect && !cancollect.value) {
        cancollect.value = yi.cancollect
      }
      if (yi && yi.state !== 'Withdrawn') {
        submitted.value = true
      }
    }
  }
}

watch(items, seedPicks, { immediate: true })

const anyPicked = computed(() =>
  Object.values(picks).some((p) => p.checked && p.quantity > 0)
)

// Can only register once something is picked, and — when the giver has set
// collection windows — once one of those has been chosen.
const canRegister = computed(() => {
  if (!anyPicked.value) return false
  if (slots.value.length && !cancollect.value) return false
  return true
})

function onCheck(item) {
  const p = picks[item.id]
  if (p.checked && (!p.quantity || p.quantity < 1)) {
    p.quantity = 1
  }
}

function thumb(item) {
  const att = item.attachments && item.attachments[0]
  if (att) return att.paththumb || att.path || null
  // Fall back to a spreadsheet-supplied photo link.
  return item.photourl || null
}

function conditionLabel(c) {
  return c === 'LikeNew' ? 'Like new' : c
}

// Build the API payload: checked items at their quantity (with the chosen
// collection time), plus any item previously wanted but now switched off
// (quantity 0 withdraws it).
function buildPayload() {
  const out = []
  for (const item of items.value) {
    const p = picks[item.id]
    const had = item.yourinterest && item.yourinterest.state !== 'Withdrawn'
    if (p && p.checked && p.quantity > 0) {
      out.push({
        bulkitemid: item.id,
        quantity: p.quantity,
        cancollect: cancollect.value || null,
      })
    } else if (had) {
      out.push({ bulkitemid: item.id, quantity: 0 })
    }
  }
  return out
}

async function submit(callback) {
  if (!authStore.user) {
    authStore.forceLogin = true
    if (callback) callback()
    return
  }
  const payload = buildPayload()
  if (payload.length) {
    await messageStore.bulkInterest(props.id, payload)
    submitted.value = true
  }
  if (callback) callback()
}

defineExpose({ buildPayload, picks, canRegister })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.bulkitems {
  border-top: 1px solid $color-gray--lighter;
  padding-top: 0.75rem;
}

.bulkitems__heading {
  font-size: 1.1rem;
  font-weight: 600;
}

.bulkitems__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

/* Consistent row: photo | details (flex) | pick column (fixed width). */
.bitem {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0;
  border-bottom: 1px solid $color-gray--lighter;
  min-height: 64px;
}

.bitem__photo {
  flex: 0 0 56px;
  width: 56px;
  height: 56px;
  border-radius: 6px;
  overflow: hidden;
  background-color: $color-gray--lighter;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.bitem__nophoto {
  color: $color-gray--normal;
  font-size: 1.4rem;
}

.bitem__detail {
  flex: 1 1 auto;
  min-width: 0;
}

.bitem__name {
  font-weight: 600;
}

.bitem__ref {
  color: $color-gray--normal;
  font-weight: 400;
  font-size: 0.85em;
}

/* Fixed-width pick column so rows stay the same shape whether on or off. */
.bitem__pick {
  flex: 0 0 7.5rem;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.35rem;
}

.bitem__qty {
  min-height: 2rem;
  width: 7rem;
}
</style>
