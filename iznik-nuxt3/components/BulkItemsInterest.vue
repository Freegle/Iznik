<template>
  <div v-if="items.length" class="bulkitems mt-3">
    <h4 class="bulkitems__heading">
      <v-icon icon="box-open" class="me-1 text-success" />
      {{ items.length }} items in this offer
    </h4>
    <p v-if="!isOwner" class="text-muted small mb-2">
      Tick the items you'd like and how many. We'll let the giver know in one
      message.
    </p>

    <ul class="bulkitems__list">
      <li v-for="item in items" :key="item.id" class="bulkitem">
        <div class="bulkitem__photo">
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            :alt="item.name"
            loading="lazy"
          />
          <v-icon v-else icon="image" class="bulkitem__nophoto" />
        </div>

        <div class="bulkitem__detail">
          <div class="bulkitem__name">{{ item.name }}</div>
          <div class="bulkitem__meta">
            <b-badge variant="light" class="me-1">{{
              item.quantity
            }} available</b-badge>
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
          <div v-if="item.description" class="bulkitem__desc small text-muted">
            {{ item.description }}
          </div>

          <!-- Owner sees a live interest summary. -->
          <div v-if="isOwner" class="bulkitem__interest small">
            <span v-if="item.interestcount">
              <strong>{{ item.interestcount }}</strong>
              interested · <strong>{{ item.interestedquantity }}</strong>
              requested
            </span>
            <span v-else class="text-muted">No interest yet</span>
          </div>
        </div>

        <!-- Recipient picks quantity. -->
        <div v-if="!isOwner && !message.successful" class="bulkitem__pick">
          <b-form-checkbox
            v-model="picks[item.id].checked"
            :data-testid="'pick-' + item.id"
            @change="onCheck(item)"
          >
            Want
          </b-form-checkbox>
          <NumberIncrementDecrement
            v-if="picks[item.id].checked"
            v-model="picks[item.id].quantity"
            :min="1"
            :max="item.quantity"
            size="sm"
            label="How many?"
            :label-s-r-only="true"
            class="bulkitem__qty"
          />
        </div>
      </li>
    </ul>

    <div v-if="!isOwner && !message.successful" class="bulkitems__actions">
      <b-form-group
        label="When could you collect?"
        label-for="bulk-cancollect"
        class="mb-2"
      >
        <b-form-input
          id="bulk-cancollect"
          v-model="cancollect"
          placeholder="e.g. weekday afternoons, or Sat 10am-2pm"
          maxlength="255"
        />
      </b-form-group>

      <SpinButton
        variant="primary"
        :disabled="!anyPicked"
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

function onCheck(item) {
  // Default a freshly-checked item to 1 if it had no quantity.
  const p = picks[item.id]
  if (p.checked && (!p.quantity || p.quantity < 1)) {
    p.quantity = 1
  }
}

function thumb(item) {
  const att = item.attachments && item.attachments[0]
  if (!att) return null
  return att.paththumb || att.path || null
}

function conditionLabel(c) {
  return c === 'LikeNew' ? 'Like new' : c
}

// Build the API payload: checked items at their quantity, plus any item the
// user had previously expressed interest in but has now unchecked (quantity 0
// withdraws it).
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
    // Prompt login; the user can submit again once authenticated.
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

defineExpose({ buildPayload, picks })
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

.bulkitem {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.6rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.bulkitem__photo {
  flex: 0 0 64px;
  width: 64px;
  height: 64px;
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

.bulkitem__nophoto {
  color: $color-gray--normal;
  font-size: 1.5rem;
}

.bulkitem__detail {
  flex: 1 1 auto;
  min-width: 0;
}

.bulkitem__name {
  font-weight: 600;
}

.bulkitem__pick {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.25rem;
}

.bulkitem__qty {
  width: 7rem;
}
</style>
