<template>
  <div v-if="group" class="group-card" :data-testid="'group-card-' + id">
    <div class="group-card-row">
      <div class="group-card-icon">
        <ProxyImage
          v-if="group.profile"
          :src="group.profile"
          alt=""
          :width="48"
          :height="48"
          sizes="48px"
          class="group-card-img"
        />
        <div v-else class="group-card-fallback">
          {{ (group.namedisplay || '?').slice(0, 1) }}
        </div>
      </div>
      <div class="group-card-body">
        <div class="group-card-name">{{ group.namedisplay }}</div>
        <div class="group-card-meta">
          <span v-if="group.membercount"
            >{{ group.membercount.toLocaleString() }} freeglers</span
          >
          <span v-if="miles !== null"
            >· about {{ Math.round(miles) }} mile{{
              Math.round(miles) === 1 ? '' : 's'
            }}</span
          >
        </div>
        <div v-if="expanded && group.tagline" class="group-card-tagline">
          {{ group.tagline }}
        </div>
      </div>
    </div>
    <div class="group-card-actions">
      <button
        v-if="!joined"
        type="button"
        class="group-card-btn"
        :data-testid="'group-join-' + id"
        @click="$emit('join', id)"
      >
        Join
      </button>
      <span v-else class="group-card-joined">Joined</span>
      <button type="button" class="group-card-link" @click="$emit('about', id)">
        {{ expanded ? 'Less' : 'About' }}
      </button>
    </div>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProxyImage from '~/components/ProxyImage.vue'
import { useGroupStore } from '~/stores/group'
import { useAuthStore } from '~/stores/auth'

// A community as a card inside the chat.
const props = defineProps({
  id: { type: Number, required: true },
  expanded: { type: Boolean, required: false, default: false },
  miles: { type: Number, required: false, default: null },
})
defineEmits(['join', 'about'])

const groupStore = useGroupStore()
const authStore = useAuthStore()
const group = computed(
  () =>
    groupStore.get(props.id) ||
    groupStore.summaryList?.find?.((g) => g.id === props.id)
)
const joined = computed(
  () =>
    !!authStore.user?.memberships?.some?.(
      (m) => m.groupid === props.id || m.id === props.id
    )
)
</script>
<style scoped lang="scss">
.group-card {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
  padding: 0.5rem;
}

.group-card-row {
  display: flex;
  gap: 0.6rem;
}

.group-card-icon {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  overflow: hidden;
  background: #e8f5ee;
  flex: 0 0 auto;
}

.group-card-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.group-card-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e7c4f;
  font-weight: 700;
}

.group-card-body {
  min-width: 0;
}

.group-card-name {
  font-weight: 600;
}

.group-card-meta {
  font-size: 0.78rem;
  color: #5a6470;
}

.group-card-tagline {
  margin-top: 0.3rem;
  font-size: 0.9rem;
}

.group-card-actions {
  display: flex;
  gap: 0.6rem;
  align-items: center;
  margin-top: 0.4rem;
}

.group-card-btn {
  background: #1e7c4f;
  color: #fff;
  border: 0;
  border-radius: 999px;
  padding: 0.35rem 0.9rem;
  font-size: 0.9rem;
}

.group-card-joined {
  color: #1e7c4f;
  font-weight: 600;
  font-size: 0.9rem;
}

.group-card-link {
  background: transparent;
  border: 0;
  color: #1e7c4f;
  font-size: 0.9rem;
}
</style>
