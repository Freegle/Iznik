<template>
  <div class="person-card" :data-testid="'person-card-' + reply.userid">
    <div class="person-row">
      <ProfileImage v-if="user?.profile?.turl || user?.profile?.url" :image="user.profile.turl || user.profile.url" :name="reply.name" class="person-avatar" is-thumbnail size="lg" />
      <div v-else class="person-avatar person-fallback">{{ (reply.name || '?').slice(0, 1) }}</div>
      <div class="person-body">
        <div class="person-name">{{ reply.name }}</div>
        <div class="person-meta">
          <span v-if="reply.miles !== null && reply.miles !== undefined">about {{ Math.round(reply.miles) }} mile{{ Math.round(reply.miles) === 1 ? '' : 's' }} away</span>
          <span v-if="ratings">· {{ ratings }}</span>
        </div>
        <div v-if="reply.snippet" class="person-snippet">"{{ reply.snippet }}"</div>
        <div v-if="reasons?.length" class="person-reasons">
          <span v-for="r in reasons" :key="r" class="person-reason">{{ r }}</span>
        </div>
      </div>
    </div>
    <slot name="actions" />
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProfileImage from '~/components/ProfileImage.vue'
import { useUserStore } from '~/stores/user'

// One replier: who they are, what they said, how far, and the reasons the ordering
// gives. The giver decides; nothing here decides for them.
const props = defineProps({
  reply: { type: Object, required: true },
  reasons: { type: Array, required: false, default: () => [] },
})

const userStore = useUserStore()
const user = computed(() => userStore.byId(props.reply.userid))
const ratings = computed(() => {
  const up = user.value?.info?.ratings?.Up || props.reply.ratings?.Up || 0
  const down = user.value?.info?.ratings?.Down || props.reply.ratings?.Down || 0
  if (!up && !down) return ''
  return `${up} up${down ? `, ${down} down` : ''}`
})
</script>
<style scoped lang="scss">
.person-card {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  padding: 0.5rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
}

.person-row {
  display: flex;
  gap: 0.6rem;
}

.person-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  flex: 0 0 auto;
}

.person-fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  background: #e8f5ee;
  color: #1e7c4f;
  font-weight: 700;
}

.person-body {
  min-width: 0;
  flex: 1 1 auto;
}

.person-name {
  font-weight: 600;
}

.person-meta {
  font-size: 0.78rem;
  color: #5a6470;
}

.person-snippet {
  font-size: 0.9rem;
  margin-top: 0.2rem;
  color: #2b333a;
}

.person-reasons {
  display: flex;
  gap: 0.3rem;
  flex-wrap: wrap;
  margin-top: 0.3rem;
}

.person-reason {
  font-size: 0.72rem;
  background: #e8f5ee;
  color: #1e7c4f;
  border-radius: 999px;
  padding: 0.1rem 0.5rem;
}
</style>
