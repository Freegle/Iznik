<template>
  <div ref="root" class="position-relative mb-2">
    <b-input-group>
      <b-input-group-text>
        <v-icon icon="search" />
      </b-input-group-text>
      <b-form-input
        v-model="query"
        type="search"
        placeholder="Search settings, e.g. spam, digest, autorepost"
        autocomplete="off"
        aria-label="Search settings"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="choose(results[active])"
        @keydown.esc="clear"
        @focus="focused = true"
      />
      <b-button v-if="query" variant="white" @click="clear"> Clear </b-button>
    </b-input-group>

    <div v-if="showResults" class="results shadow bg-white border">
      <p v-if="!results.length" class="text-muted small p-2 mb-0">
        No settings match <strong>{{ query }}</strong
        >.
      </p>
      <button
        v-for="(result, index) in results"
        :key="result.id"
        type="button"
        class="result d-block w-100 text-start border-0 bg-transparent p-2"
        :class="{ active: index === active }"
        @mouseenter="active = index"
        @click="choose(result)"
      >
        <span class="fw-bold">{{ result.label }}</span>
        <span class="text-muted small ms-2">
          {{ result.tabLabel
          }}<template v-if="result.sectionLabel">
            &rsaquo; {{ result.sectionLabel }}</template
          >
        </span>
        <span v-if="result.description" class="d-block text-muted small">
          {{ truncate(result.description) }}
        </span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { onClickOutside } from '@vueuse/core'
import { useSettingsSearch } from '~/composables/useSettingsSearch'

const DESCRIPTION_CHARS = 110

const emit = defineEmits(['select'])

const query = ref('')
const active = ref(0)
const focused = ref(false)
const root = ref(null)

const { results } = useSettingsSearch(query)

const showResults = computed(
  () => focused.value && query.value.trim().length > 0
)

onClickOutside(root, () => {
  focused.value = false
})

// Keep the keyboard selection on a row that still exists as the user types.
watch(results, () => {
  active.value = 0
})

function move(delta) {
  if (!results.value.length) return

  active.value =
    (active.value + delta + results.value.length) % results.value.length
}

function choose(result) {
  if (!result) return

  emit('select', result)
  focused.value = false
}

function clear() {
  query.value = ''
  focused.value = false
}

function truncate(text) {
  return text.length > DESCRIPTION_CHARS
    ? text.slice(0, DESCRIPTION_CHARS).trimEnd() + '...'
    : text
}
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';

.results {
  position: absolute;
  z-index: 1000;
  left: 0;
  right: 0;
  max-height: 60vh;
  overflow-y: auto;
}

.result {
  cursor: pointer;

  &.active {
    background-color: $gray-200 !important;
  }

  & + .result {
    border-top: 1px solid $gray-300 !important;
  }
}
</style>
