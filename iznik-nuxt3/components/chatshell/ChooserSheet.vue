<template>
  <ShellSheet
    testid="chooser-sheet"
    :title="'Who should have the ' + title + '?'"
    @close="$emit('close')"
  >
    <template #subtitle>
      <span v-if="available > 1" data-testid="sheet-pool">
        {{ available }} available · {{ allocated }} allocated
      </span>
      <span v-else>
        Tap someone to promise it to them. You decide; it needn't be whoever
        replied first.
      </span>
    </template>
    <div v-for="r in ordered" :key="r.userid" class="sheet-row">
      <PersonCard :reply="r" :reasons="r.reasons">
        <template #actions>
          <div class="sheet-actions">
            <template v-if="available > 1">
              <div class="stepper" :aria-label="'How many for ' + r.name">
                <button
                  type="button"
                  class="step"
                  :aria-label="'Fewer for ' + r.name"
                  :disabled="counts[r.userid] <= 0"
                  @click="change(r.userid, -1)"
                >
                  −
                </button>
                <span class="step-value" :data-testid="'count-' + r.userid">{{
                  counts[r.userid]
                }}</span>
                <button
                  type="button"
                  class="step"
                  :aria-label="'More for ' + r.name"
                  :disabled="allocated >= available"
                  @click="change(r.userid, 1)"
                >
                  +
                </button>
              </div>
            </template>
            <button
              v-else
              type="button"
              class="choose-btn"
              :data-testid="'choose-' + r.userid"
              :disabled="busy"
              @click="choose(r.userid)"
            >
              {{ isOffer ? 'Promise' : 'Choose' }}
            </button>
            <button
              type="button"
              class="chat-btn"
              @click="$emit('chat', r.chatid)"
            >
              Chat
            </button>
          </div>
        </template>
      </PersonCard>
    </div>
    <template v-if="available > 1" #footer>
      <button
        type="button"
        class="confirm-btn"
        data-testid="chooser-confirm"
        :disabled="!allocated || busy"
        @click="confirm"
      >
        Promise {{ allocated }} {{ allocated === 1 ? 'item' : 'items' }}
      </button>
    </template>
  </ShellSheet>
</template>
<script setup>
import { computed, ref } from '#imports'
import { reactive } from 'vue'
import ShellSheet from '~/components/chatshell/ShellSheet.vue'
import PersonCard from '~/components/chatshell/PersonCard.vue'
import { orderRepliers, allocate } from '~/composables/yourposts'

// The chooser: a sheet over the chat, as WhatsApp opens for a poll, because several
// numeric inputs do not belong in a bubble. Single item: tap a row. Several: a
// stepper each, prefilled from what people asked for, never over the pool.
const props = defineProps({
  post: { type: Object, required: true },
  replies: { type: Array, required: true },
  available: { type: Number, required: false, default: 1 },
})
const emit = defineEmits(['promise', 'chat', 'close'])

// One promise per opening of the sheet: a second tap before it closes does nothing.
const busy = ref(false)
const isOffer = computed(() => props.post?.type !== 'Wanted')
const title = computed(() =>
  String(props.post?.subject || 'item')
    .replace(/^(OFFER|WANTED):\s*/i, '')
    .replace(/\s*\([^)]*\)\s*$/, '')
)
const ordered = computed(() => orderRepliers(props.replies, props.post))
const counts = reactive({})
for (const a of allocate(props.available, ordered.value))
  counts[a.userid] = a.count
const allocated = computed(() =>
  Object.values(counts).reduce((n, c) => n + c, 0)
)

function change(userid, delta) {
  const next = (counts[userid] || 0) + delta
  if (next < 0) return
  if (delta > 0 && allocated.value >= props.available) return
  counts[userid] = next
}

function choose(userid) {
  if (busy.value) return
  busy.value = true
  emit('promise', [{ userid, count: 1 }])
}

function confirm() {
  if (busy.value) return
  busy.value = true
  emit(
    'promise',
    Object.entries(counts)
      .filter(([, c]) => c > 0)
      .map(([userid, count]) => ({ userid: parseInt(userid), count }))
  )
}
</script>
<style scoped lang="scss">
.sheet-actions {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  margin-top: 0.4rem;
}

.stepper {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  border: 1px solid #d5dbe0;
  border-radius: 999px;
  padding: 0.1rem 0.3rem;
}

.step {
  border: 0;
  background: transparent;
  width: 32px;
  height: 32px;
  border-radius: 50%;
  font-size: 1.2rem;
  color: #1e7c4f;

  &:disabled {
    opacity: 0.3;
  }
}

.step-value {
  min-width: 1.5rem;
  text-align: center;
  font-weight: 600;
}

.choose-btn,
.confirm-btn {
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0.4rem 1rem;
  font-size: 0.95rem;

  &:disabled {
    opacity: 0.5;
  }
}

.chat-btn {
  background: transparent;
  border: 1px solid #1e7c4f;
  color: #1e7c4f;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.9rem;
}
</style>
