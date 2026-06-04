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
      <!-- One compact line per item: thumb | #ref name + badges | toggle + qty. -->
      <li v-for="(item, idx) in items" :key="item.id" class="bitem">
        <button
          type="button"
          class="bitem__photo"
          :class="{ 'bitem__photo--zoom': thumb(item) }"
          :title="thumb(item) ? 'Click to enlarge' : ''"
          @click="enlarge(item)"
        >
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            alt=""
            loading="lazy"
            @error="brokenImage"
          />
          <v-icon v-else icon="image" class="bitem__nophoto" />
        </button>

        <div class="bitem__detail">
          <span class="bitem__ref">#{{ idx + 1 }}</span>
          <span class="bitem__name">{{ item.name }}</span>
          <b-badge variant="light">{{ item.quantity }} left</b-badge>
          <b-badge
            v-if="item.condition && item.condition !== 'Unknown'"
            variant="info"
          >
            {{ conditionLabel(item.condition) }}
          </b-badge>
          <span v-if="item.dimensions" class="text-muted small">{{
            item.dimensions
          }}</span>
        </div>

        <!-- Owner: compact interest summary. -->
        <div v-if="isOwner" class="bitem__interest small text-muted">
          <span v-if="item.interestcount"
            >{{ item.interestcount }} interested ·
            {{ item.interestedquantity }} req'd</span
          >
          <span v-else>—</span>
        </div>

        <!-- Recipient: labelled toggle + quantity dropdown, inline. -->
        <div v-else-if="!message.successful" class="bitem__pick">
          <b-form-checkbox
            v-model="picks[item.id].checked"
            switch
            class="bitem__toggle"
            :data-testid="'pick-' + item.id"
            @change="onCheck(item)"
          >
            I'd like this
          </b-form-checkbox>
          <b-form-select
            v-if="picks[item.id].checked"
            v-model="picks[item.id].quantity"
            :options="qtyOptions(item)"
            size="sm"
            class="bitem__qtysel"
            :aria-label="'How many ' + item.name"
            :data-testid="'qty-' + item.id"
          />
        </div>
      </li>
    </ul>

    <!-- Reuse the shared photo zoom modal; the bulk photos are the message's
         attachments, so it opens at the clicked item's photo. -->
    <MessagePhotosModal
      v-if="showPhotos"
      :id="id"
      :initial-index="photoIndex"
      @hidden="showPhotos = false"
    />

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
import { ref, reactive, computed, watch, defineAsyncComponent } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import SpinButton from '~/components/SpinButton'
import NoticeMessage from '~/components/NoticeMessage'

const MessagePhotosModal = defineAsyncComponent(() =>
  import('~/components/MessagePhotosModal')
)

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

// If a thumbnail fails to load, fall back to the shared placeholder rather
// than showing a broken-image box (matches NewsPhotoModal's behaviour).
function brokenImage(event) {
  event.target.src = '/placeholder.jpg'
}

// Quantity choices for an item: 1..available, defaulting to 1.
function qtyOptions(item) {
  const max = Math.max(1, parseInt(item.quantity, 10) || 1)
  return Array.from({ length: max }, (_, i) => i + 1)
}

// Click a thumbnail to open the shared photo zoom modal at that item's photo.
const showPhotos = ref(false)
const photoIndex = ref(0)
function enlarge(item) {
  const att = item.attachments && item.attachments[0]
  if (!att) return
  const all = message.value?.attachments || []
  const idx = all.findIndex((a) => a.id === att.id)
  photoIndex.value = idx >= 0 ? idx : 0
  showPhotos.value = true
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

/* One compact line: photo | details (flex, truncates) | toggle + qty. */
.bitem {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.3rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.bitem__photo {
  flex: 0 0 40px;
  width: 40px;
  height: 40px;
  padding: 0;
  border: 0;
  border-radius: 5px;
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

.bitem__photo--zoom {
  cursor: zoom-in;
}

.bitem__nophoto {
  color: $color-gray--normal;
}

/* Name + badges share one line; the name truncates if the row is tight. */
.bitem__detail {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 0.4rem;
  white-space: nowrap;
  overflow: hidden;

  .badge {
    flex: 0 0 auto;
  }
}

.bitem__name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
}

.bitem__ref {
  color: $color-gray--normal;
  font-weight: 400;
  font-size: 0.85em;
  flex: 0 0 auto;
}

.bitem__interest {
  flex: 0 0 auto;
  white-space: nowrap;
}

/* Toggle + quantity inline, never wrapping. */
.bitem__pick {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  white-space: nowrap;
}

.bitem__qtysel {
  width: 4.25rem;
}

/* On a phone the photo + name + badges + the pick/interest controls don't fit
   on one line (the row overflows ~390px). Wrap the recipient's toggle+quantity
   — and the owner's interest summary — onto their own full-width line so the
   photo, name and badges keep the first line and nothing is pushed off-screen. */
@media (max-width: 575.98px) {
  .bitem {
    flex-wrap: wrap;
  }

  .bitem__pick,
  .bitem__interest {
    flex-basis: 100%;
    /* Indent with padding (inside the 100% basis) not margin (which would add
       on top of 100% and re-introduce overflow). */
    padding-left: calc(40px + 0.5rem);
    margin-top: 0.25rem;
  }
}
</style>
