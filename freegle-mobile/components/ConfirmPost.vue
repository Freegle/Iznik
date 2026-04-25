<template>
  <div class="confirm-overlay" @click.self="$emit('edit')">
    <div class="confirm-panel">
      <h2 class="confirm-panel__heading">Review your post</h2>

      <div class="confirm-card" :class="cardClass">
        <span class="badge" :class="badgeClass">{{ type.toUpperCase() }}</span>

        <div v-if="imagePreview" class="confirm-card__image-wrap">
          <img :src="imagePreview" alt="Post image" class="confirm-card__image" />
        </div>

        <h3 class="confirm-card__title">{{ title }}</h3>

        <p v-if="description" class="confirm-card__desc">{{ description }}</p>
      </div>

      <div class="confirm-panel__actions">
        <button class="confirm-btn confirm-btn--edit" @click="$emit('edit')">
          Edit
        </button>
        <button class="confirm-btn confirm-btn--post" @click="$emit('confirm')">
          Post it!
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  type: {
    type: String,
    required: true,
    validator: (v) => ['Offer', 'Wanted', 'Discussion'].includes(v),
  },
  title: {
    type: String,
    required: true,
  },
  description: {
    type: String,
    default: '',
  },
  imagePreview: {
    type: String,
    default: null,
  },
})

defineEmits(['edit', 'confirm'])

const cardClass = computed(() => {
  switch (props.type) {
    case 'Offer':
      return 'confirm-card--offer'
    case 'Wanted':
      return 'confirm-card--wanted'
    default:
      return 'confirm-card--discussion'
  }
})

const badgeClass = computed(() => {
  switch (props.type) {
    case 'Offer':
      return 'badge--offer'
    case 'Wanted':
      return 'badge--wanted'
    default:
      return 'badge--discussion'
  }
})
</script>

<style scoped>
.confirm-overlay {
  position: fixed;
  inset: 0;
  z-index: 1000;
  background: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.confirm-panel {
  background: #fff;
  border-radius: 16px;
  padding: 24px;
  width: 100%;
  max-width: 380px;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.18);
}

.confirm-panel__heading {
  font-size: 18px;
  font-weight: 700;
  color: #222;
  margin: 0 0 16px 0;
  text-align: center;
}

/* Preview card */
.confirm-card {
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 20px;
}

.confirm-card--offer {
  background: #f0f9e8;
}

.confirm-card--wanted {
  background: #e8f0fd;
}

.confirm-card--discussion {
  background: #f5f5f5;
}

.badge {
  display: inline-block;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  padding: 2px 6px;
  border-radius: 4px;
  margin-bottom: 8px;
}

.badge--offer {
  background: #d4edbc;
  color: #2d6b0e;
}

.badge--wanted {
  background: #c8ddfb;
  color: #1a4fa0;
}

.badge--discussion {
  background: #ddd;
  color: #555;
}

.confirm-card__image-wrap {
  margin-bottom: 12px;
  border-radius: 8px;
  overflow: hidden;
}

.confirm-card__image {
  width: 100%;
  max-height: 200px;
  object-fit: cover;
  display: block;
}

.confirm-card__title {
  font-size: 16px;
  font-weight: 600;
  color: #222;
  margin: 0 0 6px 0;
}

.confirm-card__desc {
  font-size: 14px;
  color: #555;
  line-height: 1.4;
  margin: 0;
}

/* Action buttons */
.confirm-panel__actions {
  display: flex;
  gap: 12px;
}

.confirm-btn {
  flex: 1;
  padding: 12px 0;
  border-radius: 12px;
  font-size: 15px;
  font-weight: 600;
  border: none;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.confirm-btn--edit {
  background: #e8e8e8;
  color: #444;
}

.confirm-btn--edit:hover {
  background: #ddd;
}

.confirm-btn--post {
  background: #3a8a14;
  color: #fff;
}

.confirm-btn--post:hover {
  background: #2d6b0e;
}
</style>
