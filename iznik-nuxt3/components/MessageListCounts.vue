<template>
  <div v-if="browseCount" class="unread-divider">
    <div class="divider-line" />
    <div class="divider-content">
      <v-icon icon="eye" class="unread-icon" />
      <span class="unread-text">{{ browseCountPlural }}</span>
      <button class="mark-seen-btn" @click="markSeen">Mark seen</button>
    </div>
    <div class="divider-line" />
  </div>
</template>
<script setup>
import pluralize from 'pluralize'
import { computed } from 'vue'

const props = defineProps({
  count: {
    type: Number,
    required: false,
    default: 0,
  },
})

const emit = defineEmits(['markSeen'])

// The nav badge caps at this and has no room for a trailing "+", so a member
// sitting on a four-figure backlog reads it as a number that will not move
// however much they clear (Discourse 10055). The divider has the room, so it
// shows the cap AS a cap rather than either lying or quoting a demoralising
// five-figure total.
const BROWSE_COUNT_CAP = 99

const browseCount = computed(() => {
  return Math.min(BROWSE_COUNT_CAP, props.count)
})

const browseCountPlural = computed(() => {
  if (props.count > BROWSE_COUNT_CAP) {
    return BROWSE_COUNT_CAP + '+ ' + pluralize('new post', props.count)
  }

  return pluralize('new post', props.count, true)
})

function markSeen() {
  emit('markSeen')
}
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.unread-divider {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 12px 16px;
  margin: 4px 0;
  background: $color-white;
  text-align: center;
}

.divider-line {
  flex: 1;
  height: 1px;
  background: linear-gradient(
    to right,
    transparent,
    rgba($color-secondary, 0.3),
    rgba($color-secondary, 0.3),
    transparent
  );
}

.divider-content {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  white-space: nowrap;
  flex-shrink: 0;
}

.unread-icon {
  font-size: 0.9rem;
  color: $color-secondary;
}

.unread-text {
  font-size: 0.8rem;
  font-weight: 600;
  color: $color-secondary;
}

.mark-seen-btn {
  font-size: 0.75rem;
  font-weight: 500;
  color: var(--color-gray-600);
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  text-decoration: underline;
  transition: all var(--transition-fast);

  &:hover {
    color: $color-secondary;
  }
}
</style>
