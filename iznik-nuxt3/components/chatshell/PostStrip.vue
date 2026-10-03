<template>
  <div class="post-strip" data-testid="post-strip">
    <div v-if="ids.length" class="post-strip-thumbs" aria-hidden="true">
      <div
        v-for="id in ids.slice(0, 3)"
        :key="'strip-' + id"
        class="post-strip-thumb"
      >
        <ProxyImage
          v-if="photoOf(id)"
          :src="photoOf(id)"
          alt=""
          class="post-strip-img"
          :width="44"
          :height="44"
          sizes="44px"
        />
        <div v-else class="post-strip-placeholder">
          <v-icon :icon="iconOf(id)" />
        </div>
      </div>
    </div>
    <div class="post-strip-text" data-testid="post-strip-text">{{ text }}</div>
    <button
      type="button"
      class="post-strip-btn"
      data-testid="see-all"
      @click="$emit('look')"
    >
      {{ ids.length ? 'Look' : 'Search' }}
    </button>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProxyImage from '~/components/ProxyImage.vue'
import { useMessageStore } from '~/stores/message'

// What a search or a look around found, as one line in the chat: how many, a glimpse
// of three, and a button. The list itself lives in the sheet, not in the transcript.
const props = defineProps({
  ids: { type: Array, required: false, default: () => [] },
  count: { type: Number, required: false, default: 0 },
  // nearby, search, matches, or samples (the landing's glimpse for a visitor).
  kind: { type: String, required: false, default: 'nearby' },
  filter: { type: String, required: false, default: '' },
  term: { type: String, required: false, default: '' },
})
defineEmits(['look'])

const messageStore = useMessageStore()

function photoOf(id) {
  const m = messageStore.byId(id)
  return m?.attachments?.[0]?.paththumb || m?.attachments?.[0]?.path || null
}

function iconOf(id) {
  return messageStore.byId(id)?.type === 'Wanted' ? 'shopping-cart' : 'gift'
}

const text = computed(() => {
  const n = props.count
  const s = (one, many) => `${n} ${n === 1 ? one : many}`
  if (props.kind === 'samples') return 'Offered near you recently'
  if (props.kind === 'search') {
    return n
      ? `${s('match', 'matches')} for "${props.term}"`
      : `Nothing matching "${props.term}" just now`
  }
  if (props.kind === 'matches') {
    return `${s('offer', 'offers')} nearby might be what you're after`
  }
  if (!n) return 'Nothing nearby just now'
  if (props.filter === 'offers') return `${s('offer', 'offers')} nearby`
  if (props.filter === 'wanted') {
    return `${s('wanted post', 'wanted posts')} nearby`
  }
  return `${s('thing', 'things')} nearby`
})
</script>
<style scoped lang="scss">
.post-strip {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  padding: 0.5rem 0.6rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.post-strip-thumbs {
  display: flex;
  flex: 0 0 auto;
}

.post-strip-thumb {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  overflow: hidden;
  background: #eef2f4;
  border: 2px solid #fff;
  margin-left: -10px;

  &:first-child {
    margin-left: 0;
  }
}

.post-strip-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.post-strip-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e7c4f;
}

.post-strip-text {
  flex: 1 1 auto;
  min-width: 0;
  font-size: 0.95rem;
  line-height: 1.25;
}

.post-strip-btn {
  flex: 0 0 auto;
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.9rem;
}
</style>
