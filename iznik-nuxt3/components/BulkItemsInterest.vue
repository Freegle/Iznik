<template>
  <div v-if="items.length" class="bulkitems mt-3">
    <h4 class="bulkitems__heading">
      <v-icon icon="gift" class="me-1 text-success" />
      {{ items.length }} items in this offer
    </h4>
    <p v-if="!isOwner && !message.successful" class="bulkitems__prompt">
      Turn on <strong>“I'd like this”</strong> for each item you want — pick as
      many as you like, and choose how many of each. You'll need at least one
      turned on before you can register your interest.
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
          <b-badge variant="light">{{ item.quantity }} available</b-badge>
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

        <!-- Recipient: decision badge when the giver has acted; toggle otherwise. -->
        <div v-else-if="!message.successful" class="bitem__pick">
          <!-- Giver allocated this item to the recipient. -->
          <b-badge
            v-if="item.yourinterest && item.yourinterest.state === 'Reserved'"
            variant="success"
            class="bitem__decision"
            :data-testid="'decision-reserved-' + item.id"
          >
            <v-icon icon="check-circle" class="me-1" />Allocated to you
          </b-badge>

          <!-- Giver did not select the recipient for this item. -->
          <span
            v-else-if="
              item.yourinterest && item.yourinterest.state === 'Rejected'
            "
            class="bitem__decision bitem__decision--rejected"
            :data-testid="'decision-rejected-' + item.id"
          >
            <v-icon icon="times-circle" class="me-1" />Not this time
          </span>

          <!-- No decision yet — show the normal toggle + quantity controls. -->
          <template v-else>
            <b-form-checkbox
              v-model="picks[item.id].checked"
              switch
              class="bitem__toggle"
              :class="{ 'bitem__toggle--on': picks[item.id].checked }"
              :data-testid="'pick-' + item.id"
              :aria-label="'I\'d like ' + item.name"
              @change="onCheck(item)"
            >
              <span class="bitem__picktext">I'd like this</span>
            </b-form-checkbox>
            <!-- Quantity only matters when more than one is available. The slot
                 keeps a fixed width so turning the toggle on doesn't shift it. -->
            <div v-if="item.quantity > 1" class="bitem__qtyslot">
              <template v-if="picks[item.id].checked">
                <label :for="'qty-' + item.id" class="bitem__qtylabel"
                  >How many?</label
                >
                <b-form-select
                  :id="'qty-' + item.id"
                  v-model="picks[item.id].quantity"
                  :options="qtyOptions(item)"
                  size="sm"
                  class="bitem__qtysel"
                  :aria-label="'How many ' + item.name"
                  :data-testid="'qty-' + item.id"
                />
              </template>
            </div>
          </template>
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
      <b-form-group
        label="Which collection times can you make? Tick all that suit you."
        class="mb-2"
      >
        <b-form-checkbox-group
          v-if="slots.length"
          v-model="cancollectTimes"
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

      <p v-if="registerHint" class="bulkitems__hint small">
        {{ registerHint }}
      </p>
      <!-- The "Register interest" action lives in the page's main reply button
           (see MessageExpanded), which calls submit() and reflects canRegister. -->
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
import NoticeMessage from '~/components/NoticeMessage'

const MessagePhotosModal = defineAsyncComponent(() =>
  import('~/components/MessagePhotosModal')
)

const props = defineProps({
  id: { type: Number, required: true },
})

// The page's main reply button drives the actual "Register interest" action, so
// it needs to know whether we're ready, and whether interest is already in.
const emit = defineEmits(['can-register', 'submitted'])

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
// Free-text collection time (used when the giver set no fixed windows).
const cancollect = ref('')
// Selected fixed collection windows — recipients tick all the times they can
// make, not just one.
const cancollectTimes = ref([])

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
      if (
        yi &&
        yi.cancollect &&
        !cancollect.value &&
        !cancollectTimes.value.length
      ) {
        cancollect.value = yi.cancollect
        // Pre-tick the fixed windows the user already chose (stored joined).
        if (slots.value.length) {
          cancollectTimes.value = yi.cancollect
            .split(';')
            .map((s) => s.trim())
            .filter((s) => slots.value.includes(s))
        }
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

// Can only register once at least one item is turned on, and — when the giver
// has set collection windows — once at least one of those is ticked.
const canRegister = computed(() => {
  if (!anyPicked.value) return false
  if (slots.value.length && !cancollectTimes.value.length) return false
  return true
})

// Tells the recipient why the Register button is disabled.
const registerHint = computed(() => {
  if (!anyPicked.value) {
    return "Turn on “I'd like this” for at least one item above before you can register."
  }
  if (slots.value.length && !cancollectTimes.value.length) {
    return 'Tick at least one collection time you can make.'
  }
  return ''
})

// Keep the page's reply button in sync (it shows "Register interest" and is
// disabled until canRegister, then "Update my interest" once interest is in).
watch(canRegister, (v) => emit('can-register', v), { immediate: true })
watch(submitted, (v) => emit('submitted', v), { immediate: true })

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
  // Fixed windows are stored joined; free text is used when there are none.
  const collect = slots.value.length
    ? cancollectTimes.value.join('; ')
    : cancollect.value
  const out = []
  for (const item of items.value) {
    const p = picks[item.id]
    const had = item.yourinterest && item.yourinterest.state !== 'Withdrawn'
    if (p && p.checked && p.quantity > 0) {
      out.push({
        bulkitemid: item.id,
        quantity: p.quantity,
        cancollect: collect || null,
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

defineExpose({
  submit,
  buildPayload,
  picks,
  canRegister,
  cancollectTimes,
  registerHint,
})
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

/* Prominent instruction so it's obvious the toggles are the key action. */
.bulkitems__prompt {
  background-color: $color-green--light;
  border-left: 3px solid $color-green--darker;
  border-radius: 4px;
  padding: 0.5rem 0.75rem;
  margin-bottom: 0.75rem;
  font-size: 0.95rem;
}

/* The "I'd like this" label — clear, and greens up when turned on. */
.bitem__picktext {
  font-weight: 600;
}

.bitem__toggle--on .bitem__picktext {
  color: $color-green--darker;
}

.bulkitems__hint {
  color: $color-gray--dark;
  margin-bottom: 0.5rem;
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

/* Toggle on top; the quantity (label + dropdown) sits BELOW it when turned on,
   so turning the toggle on never shifts its position. */
.bitem__pick {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.25rem;
  white-space: nowrap;
}

.bitem__qtyslot {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.bitem__qtylabel {
  margin: 0;
  font-size: 0.85rem;
  color: $color-gray--dark;
  white-space: nowrap;
}

.bitem__qtysel {
  width: 4.25rem;
}

/* Decision badges shown once the giver has allocated or declined an item. */
.bitem__decision {
  font-size: 0.85rem;
  display: inline-flex;
  align-items: center;
}

/* Subdued "not this time" state: muted text, no strong colour. */
.bitem__decision--rejected {
  color: $color-gray--dark;
  font-size: 0.85rem;
}

/* Give the collection-times block some breathing room from the items list. */
.bulkitems__actions {
  margin-top: 1rem;
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

  /* Left-align the stacked toggle + quantity under the item name on mobile. */
  .bitem__pick {
    align-items: flex-start;
  }
}
</style>
