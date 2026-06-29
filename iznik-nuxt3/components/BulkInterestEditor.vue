<template>
  <div
    v-if="items.length"
    class="bulkinteresteditor"
    data-testid="bulk-interest-editor"
  >
    <div class="bulkinteresteditor__intro small text-muted mb-1">
      <template v-if="editingOwn">
        The items you'd like — turn on any others you want, or turn one off to
        drop it.
      </template>
      <template v-else-if="editable">
        What {{ theirName }} would like — turn on anything they've asked for in
        chat so it's tracked.
      </template>
      <template v-else> Items they're interested in: </template>
    </div>

    <ul class="bulkinteresteditor__list">
      <li v-for="item in items" :key="item.id" class="biedit">
        <b-form-checkbox
          v-model="picks[item.id].checked"
          :disabled="!editable || busy || picks[item.id].locked"
          switch
          class="biedit__check"
          :data-testid="'chatpick-' + item.id"
          :aria-label="'Interested in ' + item.name"
          @change="onChange(item)"
        />
        <span class="biedit__photo">
          <img
            v-if="thumb(item)"
            :src="thumb(item)"
            alt=""
            loading="lazy"
            @error="brokenImage"
          />
          <v-icon v-else icon="image" class="biedit__nophoto" />
        </span>
        <span class="biedit__name" :class="{ 'biedit__name--off': !picks[item.id].checked }">
          {{ item.name }}
        </span>
        <b-form-select
          v-if="picks[item.id].checked"
          v-model="picks[item.id].quantity"
          :options="qtyOptions(item)"
          size="sm"
          :disabled="!editable || busy || picks[item.id].locked"
          class="biedit__qty"
          :aria-label="'How many ' + item.name"
          @change="onChange(item)"
        />
        <b-badge
          v-if="picks[item.id].stateLabel"
          variant="info"
          class="biedit__state"
        >
          {{ picks[item.id].stateLabel }}
        </b-badge>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'

const props = defineProps({
  // The bulk offer.
  messageid: { type: Number, required: true },
  // Whose interest this editor shows/edits.
  interestuserid: { type: Number, required: true },
})

const messageStore = useMessageStore()
const userStore = useUserStore()
const { myid } = useMe()

const busy = ref(false)

const message = computed(() => messageStore.byId(props.messageid) || {})
const items = computed(() => message.value?.bulkitems || [])

// Editing your own interest, or (as the offerer) recording someone else's.
const editingOwn = computed(() => props.interestuserid === myid.value)
const isOfferer = computed(() => message.value?.fromuser === myid.value)
const editable = computed(() => editingOwn.value || isOfferer.value)

const theirName = computed(() => {
  const u = userStore.byId(props.interestuserid)
  return u?.displayname || 'they'
})

// The interest row relevant to interestuserid: the caller's own (yourinterest)
// or, when the offerer is viewing, the entry for that user in the full list.
function interestFor(item) {
  if (editingOwn.value) return item.yourinterest || null
  return (item.interest || []).find((i) => i.userid === props.interestuserid) || null
}

function stateLabel(state) {
  if (state === 'Reserved') return 'Promised'
  if (state === 'Collected') return 'Collected'
  return ''
}

// Per-item editor state, seeded from the current interest. `had` records whether
// the item started as active interest (so turning it off sends an explicit
// withdraw); `locked` keeps a promised/collected item from being toggled away.
const picks = reactive({})

function seed() {
  for (const item of items.value) {
    if (!item.id) continue
    const yi = interestFor(item)
    const active = !!yi && yi.state !== 'Withdrawn' && yi.quantity > 0
    picks[item.id] = {
      checked: active,
      quantity: yi && yi.quantity > 0 ? yi.quantity : 1,
      cancollect: (yi && yi.cancollect) || null,
      had: active,
      stateLabel: yi ? stateLabel(yi.state) : '',
      locked: yi ? yi.state === 'Reserved' || yi.state === 'Collected' : false,
    }
  }
}

watch(items, seed, { immediate: true, deep: true })

function thumb(item) {
  const a = item.attachments && item.attachments[0]
  return (a && (a.paththumb || a.path)) || item.photourl || null
}

function qtyOptions(item) {
  const max = Math.max(1, parseInt(item.quantity, 10) || 1)
  return Array.from({ length: max }, (_, i) => i + 1)
}

function brokenImage(event) {
  event.target.src = '/placeholder.jpg'
}

// Build the full desired state: turned-on items at their quantity, plus any item
// that was active but is now turned off (quantity 0 withdraws it).
function buildPayload() {
  const out = []
  for (const item of items.value) {
    const p = picks[item.id]
    if (!p) continue
    if (p.checked && p.quantity > 0) {
      out.push({
        bulkitemid: item.id,
        quantity: p.quantity,
        cancollect: p.cancollect || null,
      })
    } else if (p.had) {
      out.push({ bulkitemid: item.id, quantity: 0 })
    }
  }
  return out
}

async function onChange(item) {
  const p = picks[item.id]
  if (p.checked && (!p.quantity || p.quantity < 1)) p.quantity = 1
  const payload = buildPayload()
  if (!payload.length) return
  busy.value = true
  try {
    // When recording for someone else, pass their id; for yourself, omit it.
    await messageStore.bulkInterest(
      props.messageid,
      payload,
      editingOwn.value ? undefined : props.interestuserid
    )
  } catch (e) {
    console.error('Bulk interest update failed', e)
    // Re-seed from the (unchanged) store so the UI reflects reality.
    seed()
  } finally {
    busy.value = false
  }
}

defineExpose({ picks, buildPayload, editable, onChange, editingOwn })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.bulkinteresteditor__list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 16rem;
  overflow-y: auto;
}

.biedit {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.25rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.biedit__check {
  flex: 0 0 auto;
}

.biedit__photo {
  flex: 0 0 32px;
  width: 32px;
  height: 32px;
  border-radius: 4px;
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

.biedit__nophoto {
  color: $color-gray--normal;
}

.biedit__name {
  flex: 1 1 auto;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 600;
}

.biedit__name--off {
  font-weight: 400;
  color: $color-gray--dark;
}

.biedit__qty {
  flex: 0 0 auto;
  width: 4rem;
}

.biedit__state {
  flex: 0 0 auto;
}
</style>
