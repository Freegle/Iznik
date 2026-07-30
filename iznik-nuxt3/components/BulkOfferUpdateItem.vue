<template>
  <div
    class="bulkupdate-item"
    :class="{ 'bulkupdate-item--taken': !item.available }"
    :data-testid="'bulkupdate-item-' + item.id"
  >
    <div class="bulkupdate-item__head">
      <div class="bulkupdate-item__photo">
        <img
          v-if="item.photo"
          :src="item.photo"
          alt=""
          loading="lazy"
          @error="brokenImage"
        />
        <v-icon v-else icon="image" class="bulkupdate-item__nophoto" />
      </div>
      <div class="bulkupdate-item__title">
        <span class="bulkupdate-item__ref">#{{ index + 1 }}</span>
        <span class="bulkupdate-item__name">{{ item.name }}</span>
        <b-badge
          v-if="item.condition && item.condition !== 'Unknown'"
          variant="info"
        >
          {{ conditionLabel }}
        </b-badge>
        <span
          v-if="item.dimensions"
          class="bulkupdate-item__dims small text-muted"
        >
          {{ item.dimensions }}
        </span>
      </div>
    </div>

    <div class="bulkupdate-item__controls">
      <!-- Available / taken toggle -->
      <div class="bulkupdate-item__avail">
        <b-form-checkbox
          v-model="availableModel"
          switch
          :disabled="saving"
          :data-testid="'bulkupdate-toggle-' + item.id"
        >
          <span v-if="item.available" class="text-success fw-semibold"
            >Available</span
          >
          <span v-else class="text-muted fw-semibold">Taken / gone</span>
        </b-form-checkbox>
      </div>

      <!-- Count editor -->
      <div
        class="bulkupdate-item__count"
        :class="{ 'is-muted': !item.available }"
      >
        <label :for="'bulkupdate-qty-' + item.id" class="small mb-0 me-1">
          Number available
        </label>
        <b-button
          variant="outline-secondary"
          size="sm"
          :disabled="saving || count <= 0"
          aria-label="Decrease"
          :data-testid="'bulkupdate-dec-' + item.id"
          @click="step(-1)"
        >
          <v-icon icon="minus" />
        </b-button>
        <b-form-input
          :id="'bulkupdate-qty-' + item.id"
          v-model.number="count"
          type="number"
          min="0"
          class="bulkupdate-item__qtyinput"
          :disabled="saving"
          :data-testid="'bulkupdate-qty-' + item.id"
          @change="commitCount"
          @blur="commitCount"
        />
        <b-button
          variant="outline-secondary"
          size="sm"
          :disabled="saving"
          aria-label="Increase"
          :data-testid="'bulkupdate-inc-' + item.id"
          @click="step(1)"
        >
          <v-icon icon="plus" />
        </b-button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  // One catalogue item: { id, name, quantity, condition, dimensions, available, photo }.
  item: { type: Object, required: true },
  // Zero-based position, for the #N reference.
  index: { type: Number, default: 0 },
  // Disable the controls while a save is in flight.
  saving: { type: Boolean, default: false },
})

const emit = defineEmits(['update'])

// Local count, kept in step with the item (the server is the source of truth:
// the parent updates the prop from the API response after each change).
const count = ref(props.item.quantity)
watch(
  () => props.item.quantity,
  (v) => {
    count.value = v
  }
)

// The switch's two-way model: flipping it emits an availability change. We don't
// mutate the prop directly - the parent re-renders us from the API response.
const availableModel = computed({
  get: () => !!props.item.available,
  set: (val) => {
    if (val !== !!props.item.available) {
      emit('update', { itemid: props.item.id, available: val })
    }
  },
})

function step(delta) {
  const next = Math.max(0, (parseInt(count.value, 10) || 0) + delta)
  count.value = next
  emit('update', { itemid: props.item.id, quantity: next })
}

function commitCount() {
  let next = parseInt(count.value, 10)
  if (isNaN(next) || next < 0) next = 0
  count.value = next
  if (next !== props.item.quantity) {
    emit('update', { itemid: props.item.id, quantity: next })
  }
}

const conditionLabel = computed(() =>
  props.item.condition === 'LikeNew' ? 'Like new' : props.item.condition
)

function brokenImage(event) {
  event.target.style.display = 'none'
}

defineExpose({ count, availableModel, step, commitCount })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.bulkupdate-item {
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  padding: 0.85rem 1rem;
  margin-bottom: 0.85rem;
  background-color: $color-white;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  transition: opacity 0.15s ease;
}

/* A taken item is dimmed so the eye skips to what's still available. */
.bulkupdate-item--taken {
  opacity: 0.65;
  background-color: $color-gray--lighter;
}
.bulkupdate-item--taken .bulkupdate-item__name {
  text-decoration: line-through;
}

.bulkupdate-item__head {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.bulkupdate-item__photo {
  flex: 0 0 48px;
  width: 48px;
  height: 48px;
  border-radius: 5px;
  overflow: hidden;
  background-color: $color-gray--lighter;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.bulkupdate-item__nophoto {
  color: $color-gray--normal;
}

.bulkupdate-item__title {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.bulkupdate-item__ref {
  color: $color-gray--normal;
  font-size: 0.85em;
}

.bulkupdate-item__name {
  font-weight: 600;
}

.bulkupdate-item__dims {
  flex-basis: 100%;
}

.bulkupdate-item__controls {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  margin-top: 0.6rem;
}

.bulkupdate-item__count {
  display: flex;
  align-items: center;
  gap: 0.35rem;

  &.is-muted {
    opacity: 0.7;
  }
}

.bulkupdate-item__qtyinput {
  width: 4.5rem;
  text-align: center;
}
</style>
