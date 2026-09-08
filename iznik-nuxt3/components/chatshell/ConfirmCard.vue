<template>
  <div class="confirm-card" data-testid="confirm-card">
    <div class="confirm-type">{{ postType === 'Wanted' ? 'Wanted' : 'Offer' }}</div>
    <div v-if="photos.length" class="confirm-photos">
      <ProxyImage v-for="p in photos" :key="p.id" :src="p.paththumb || p.path" alt="" :width="72" :height="72" sizes="72px" class="confirm-photo" />
    </div>
    <button type="button" class="confirm-field" data-testid="confirm-item" @click="$emit('edit', 'item')">
      <span class="confirm-label">What</span>
      <span class="confirm-value">{{ slots.item }}<span v-if="slots.quantity > 1"> · {{ slots.quantity }} available</span></span>
    </button>
    <button type="button" class="confirm-field" data-testid="confirm-description" @click="$emit('edit', 'description')">
      <span class="confirm-label">Details</span>
      <span class="confirm-value" :class="{ muted: !slots.description }">{{ slots.description || 'None added' }}</span>
    </button>
    <button type="button" class="confirm-field" data-testid="confirm-where" @click="$emit('edit', 'where')">
      <span class="confirm-label">Where</span>
      <span class="confirm-value">{{ whereText }}</span>
    </button>
    <div v-if="postType === 'Offer'" class="confirm-toggles">
      <label class="confirm-toggle">
        <input type="checkbox" :checked="!!slots.delivery" @change="$emit('toggle', 'delivery', $event.target.checked)" />
        Could deliver
      </label>
    </div>
  </div>
</template>
<script setup>
import { computed } from '#imports'
import ProxyImage from '~/components/ProxyImage.vue'

// What will be posted, each line tappable to change it. The Post button is a chip
// under the message, so the card has no button of its own.
const props = defineProps({
  postType: { type: String, required: true },
  slots: { type: Object, required: true },
  photos: { type: Array, required: false, default: () => [] },
  locationName: { type: String, required: false, default: null },
  community: { type: String, required: false, default: null },
})
defineEmits(['edit', 'toggle'])

const whereText = computed(() => {
  const parts = []
  if (props.locationName) parts.push('Near ' + props.locationName)
  else if (props.slots.postcode) parts.push('Near ' + props.slots.postcode)
  if (props.community) parts.push(props.community)
  return parts.join(' · ') || 'Your location'
})
</script>
<style scoped lang="scss">
.confirm-card {
  background: #fff;
  border-radius: 12px;
  margin: 0.3rem 0.6rem;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
  padding: 0.5rem;
}

.confirm-type {
  font-size: 0.7rem;
  text-transform: uppercase;
  color: #1e7c4f;
  font-weight: 700;
  letter-spacing: 0.03em;
}

.confirm-photos {
  display: flex;
  gap: 0.3rem;
  margin: 0.3rem 0;
}

.confirm-photo {
  width: 72px;
  height: 72px;
  border-radius: 8px;
  object-fit: cover;
}

.confirm-field {
  display: flex;
  flex-direction: column;
  width: 100%;
  border: 0;
  border-top: 1px solid #eef2f4;
  background: transparent;
  text-align: left;
  padding: 0.45rem 0;
}

.confirm-label {
  font-size: 0.72rem;
  color: #7a838c;
  text-transform: uppercase;
}

.confirm-value {
  font-size: 0.95rem;
  white-space: pre-wrap;

  &.muted {
    color: #7a838c;
  }
}

.confirm-toggles {
  border-top: 1px solid #eef2f4;
  padding-top: 0.45rem;
}

.confirm-toggle {
  display: flex;
  gap: 0.4rem;
  align-items: center;
  font-size: 0.9rem;
}
</style>
