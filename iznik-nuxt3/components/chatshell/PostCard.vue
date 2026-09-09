<template>
  <div v-if="message" class="post-card" :data-testid="'post-card-' + id">
    <button type="button" class="post-card-main" @click="$emit('expand', id)">
      <div class="post-card-photo">
        <ProxyImage
          v-if="photo"
          :src="photo"
          alt=""
          class="post-card-img"
          :width="96"
          :height="96"
          sizes="96px"
        />
        <div v-else class="post-card-placeholder">
          <v-icon
            :icon="message.type === 'Wanted' ? 'shopping-cart' : 'gift'"
          />
        </div>
      </div>
      <div class="post-card-body">
        <div class="post-card-type">
          {{ message.type === 'Wanted' ? 'Wanted' : 'Offer' }}
        </div>
        <div class="post-card-title">{{ title }}</div>
        <div class="post-card-meta">
          <span v-if="miles !== null">{{ milesText }}</span>
          <span v-if="message.area?.name || message.location">{{
            message.area?.name || message.location
          }}</span>
        </div>
        <div v-if="expanded && message.textbody" class="post-card-text">
          {{ message.textbody }}
        </div>
      </div>
    </button>
    <div class="post-card-actions">
      <button
        type="button"
        class="post-card-btn"
        :data-testid="'post-reply-' + id"
        @click="$emit('reply', id)"
      >
        {{ message.type === 'Wanted' ? 'I have one' : 'Reply' }}
      </button>
      <button
        v-if="!expanded"
        type="button"
        class="post-card-link"
        @click="$emit('expand', id)"
      >
        More
      </button>
    </div>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProxyImage from '~/components/ProxyImage.vue'
import { useMessageStore } from '~/stores/message'

// A post as a card inside the chat: photo, name, distance, and one action. Tapping the
// card expands it in place; Reply starts the reply flow.
const props = defineProps({
  id: { type: Number, required: true },
  expanded: { type: Boolean, required: false, default: false },
  miles: { type: Number, required: false, default: null },
})
defineEmits(['reply', 'expand'])

const messageStore = useMessageStore()
const message = computed(() => messageStore.byId(props.id))
const photo = computed(
  () =>
    message.value?.attachments?.[0]?.paththumb ||
    message.value?.attachments?.[0]?.path ||
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
  if (props.miles < 1) return 'Under a mile away'
  return `About ${Math.round(props.miles)} mile${Math.round(props.miles) === 1 ? '' : 's'} away`
})
</script>
<style scoped lang="scss">
.post-card {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
  overflow: hidden;
}

.post-card-main {
  display: flex;
  gap: 0.6rem;
  width: 100%;
  border: 0;
  background: transparent;
  text-align: left;
  padding: 0.5rem;
}

.post-card-photo {
  width: 72px;
  height: 72px;
  flex: 0 0 auto;
  border-radius: 8px;
  overflow: hidden;
  background: #eef2f4;
}

.post-card-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.post-card-placeholder {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e7c4f;
  font-size: 1.5rem;
}

.post-card-body {
  min-width: 0;
  flex: 1 1 auto;
}

.post-card-type {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #1e7c4f;
  font-weight: 700;
}

.post-card-title {
  font-weight: 600;
  line-height: 1.2;
}

.post-card-meta {
  font-size: 0.78rem;
  color: #5a6470;
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.post-card-text {
  margin-top: 0.3rem;
  font-size: 0.9rem;
  white-space: pre-wrap;
}

.post-card-actions {
  display: flex;
  gap: 0.5rem;
  padding: 0 0.5rem 0.5rem;
  align-items: center;
}

.post-card-btn {
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.9rem;
}

.post-card-link {
  background: transparent;
  border: 0;
  color: #1e7c4f;
  font-size: 0.9rem;
}
</style>
