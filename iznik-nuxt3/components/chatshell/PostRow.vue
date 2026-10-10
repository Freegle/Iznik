<template>
  <div
    v-if="message"
    class="post-row"
    :class="{ expanded }"
    :data-testid="'nearby-row-' + id"
  >
    <button
      type="button"
      class="post-row-main"
      :aria-expanded="expanded ? 'true' : 'false'"
      @click="$emit('expand', id)"
    >
      <div class="post-row-thumb">
        <ProxyImage
          v-if="photo"
          :src="photo"
          alt=""
          class="post-row-img"
          :width="48"
          :height="48"
          sizes="48px"
        />
        <div v-else class="post-row-placeholder">
          <v-icon
            :icon="message.type === 'Wanted' ? 'shopping-cart' : 'gift'"
          />
        </div>
      </div>
      <div class="post-row-body">
        <div class="post-row-title">{{ title }}</div>
        <div class="post-row-meta">
          <span
            class="post-row-type"
            :class="{ wanted: message.type === 'Wanted' }"
            >{{ message.type === 'Wanted' ? 'Wanted' : 'Offer' }}</span
          >
          <span v-if="miles !== null">{{ milesText }}</span>
          <span v-if="place">{{ place }}</span>
        </div>
      </div>
    </button>
    <div v-if="expanded" class="post-row-detail">
      <ProxyImage
        v-if="bigPhoto"
        :src="bigPhoto"
        alt=""
        class="post-row-photo"
        :width="320"
        :height="240"
        sizes="320px"
      />
      <p v-if="message.textbody" class="post-row-text">
        {{ message.textbody }}
      </p>
      <button
        type="button"
        class="post-row-btn"
        :data-testid="'post-reply-' + id"
        @click="$emit('reply', id)"
      >
        {{ message.type === 'Wanted' ? 'I have one' : 'Reply' }}
      </button>
    </div>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProxyImage from '~/components/ProxyImage.vue'
import { useMessageStore } from '~/stores/message'

// A post as one line in a list: thumbnail, name, type, distance. Tapping the line
// opens it in place with the photo, the words and Reply, so a long list stays a
// list until someone wants more.
const props = defineProps({
  id: { type: Number, required: true },
  expanded: { type: Boolean, required: false, default: false },
  miles: { type: Number, required: false, default: null },
})
defineEmits(['reply', 'expand'])

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.id))

// The area a post is in. Full records carry location as an object; summaries as text.
const place = computed(() => {
  const m = message.value
  if (!m) return ''
  if (m.area?.name) return m.area.name
  if (typeof m.location === 'string') return m.location
  return m.location?.name || ''
})
const photo = computed(
  () =>
    message.value?.attachments?.[0]?.paththumb ||
    message.value?.attachments?.[0]?.path ||
    null
)
const bigPhoto = computed(
  () =>
    message.value?.attachments?.[0]?.path ||
    message.value?.attachments?.[0]?.paththumb ||
    null
)
const title = computed(() => {
  const s = message.value?.subject || ''
  return s
    .replace(/^(OFFER|WANTED|TAKEN|RECEIVED):\s*/i, '')
    .replace(/\s*\([^)]*\)\s*$/, '')
})
const milesText = computed(() => {
  if (props.miles === null) return ''
  if (props.miles < 1) return 'Under a mile'
  const n = Math.round(props.miles)
  return `${n} mile${n === 1 ? '' : 's'}`
})
</script>
<style scoped lang="scss">
.post-row {
  background: #fff;
  border-bottom: 1px solid #e6eaed;

  &.expanded {
    background: #f9fbfa;
  }
}

.post-row-main {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.45rem 0.9rem;
}

.post-row-thumb {
  width: 48px;
  height: 48px;
  flex: 0 0 auto;
  border-radius: 8px;
  overflow: hidden;
  background: #eef2f4;
}

.post-row-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.post-row-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e7c4f;
  font-size: 1.2rem;
}

.post-row-body {
  min-width: 0;
  flex: 1 1 auto;
}

.post-row-title {
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.post-row-meta {
  font-size: 0.78rem;
  color: #5a6470;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.post-row-type {
  color: #1e7c4f;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  font-size: 0.7rem;

  &.wanted {
    color: #7a4b00;
  }
}

.post-row-detail {
  padding: 0 0.9rem 0.8rem;
}

.post-row-photo {
  width: 100%;
  max-height: 240px;
  object-fit: cover;
  border-radius: 8px;
  margin-bottom: 0.5rem;
}

.post-row-text {
  font-size: 0.9rem;
  white-space: pre-wrap;
  margin: 0 0 0.5rem;
}

.post-row-btn {
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.9rem;
}
</style>
