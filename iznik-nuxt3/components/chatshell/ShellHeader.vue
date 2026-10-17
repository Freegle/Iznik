<template>
  <header class="shell-header" data-testid="shell-header">
    <nuxt-link
      v-if="back"
      :to="back"
      class="shell-back"
      no-prefetch
      aria-label="Back to chats"
      data-testid="shell-back"
    >
      <v-icon icon="arrow-left" />
      <span v-if="badge > 0" class="shell-badge" data-testid="shell-badge">{{
        badge > 99 ? '99+' : badge
      }}</span>
    </nuxt-link>
    <div class="shell-avatar" aria-hidden="true">
      <img v-if="avatar" :src="avatar" alt="" />
      <div v-else class="shell-avatar-fallback">{{ initial }}</div>
    </div>
    <div class="shell-title">
      <div class="shell-name">{{ title }}</div>
      <div v-if="subtitle" class="shell-subtitle">{{ subtitle }}</div>
    </div>
    <div class="shell-menu">
      <slot name="menu" />
    </div>
  </header>
</template>
<script setup>
import { computed } from '#imports'

const props = defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, required: false, default: null },
  avatar: { type: String, required: false, default: null },
  back: { type: String, required: false, default: null },
  badge: { type: Number, required: false, default: 0 },
})

const initial = computed(() => (props.title || '?').slice(0, 1).toUpperCase())
</script>
<style scoped lang="scss">
.shell-header {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.6rem 0.75rem;
  background: #1e7c4f;
  color: #fff;
  min-height: 56px;
  flex: 0 0 auto;
}

.shell-back {
  color: #fff;
  position: relative;
  padding: 0.25rem 0.4rem 0.25rem 0;
  font-size: 1.1rem;
}

.shell-badge {
  position: absolute;
  top: -4px;
  right: -8px;
  background: #ffd23f;
  color: #1f2427;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  padding: 0 5px;
  line-height: 1.2rem;
}

.shell-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  overflow: hidden;
  background: #fff;
  flex: 0 0 auto;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.shell-avatar-fallback {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1e7c4f;
  font-weight: 700;
}

.shell-title {
  flex: 1 1 auto;
  min-width: 0;
}

.shell-name {
  font-weight: 600;
  font-size: 1rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.shell-subtitle {
  font-size: 0.75rem;
  opacity: 0.9;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.shell-menu {
  flex: 0 0 auto;
}
</style>
