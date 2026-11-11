<template>
  <div
    class="bulk-bar d-flex flex-wrap align-items-center gap-2 p-2 mb-2 border"
    data-test="bulk-bar"
  >
    <div class="fw-bold" data-test="bulk-count">
      {{ count }} selected
      <span class="text-muted small fw-normal">(up to {{ limit }})</span>
    </div>
    <b-button
      variant="white"
      size="sm"
      :disabled="busy || !shownIds.length || allShownSelected"
      data-test="bulk-select-all"
      @click="$emit('select-all', shownIds)"
    >
      Select all shown
    </b-button>
    <b-button
      variant="white"
      size="sm"
      :disabled="busy || !count"
      data-test="bulk-clear"
      @click="$emit('clear')"
    >
      Clear
    </b-button>
    <b-button
      v-if="copy"
      variant="secondary"
      size="sm"
      :disabled="busy || !copyRows.length"
      :title="copyTitle"
      data-test="bulk-copy"
      @click="copyList"
    >
      <v-icon v-if="copied" icon="check" />
      <v-icon v-else-if="copyFailed" icon="exclamation-triangle" />
      <v-icon v-else icon="copy" />
      <span v-if="copied">Copied</span>
      <span v-else-if="copyFailed">Copy failed</span>
      <span v-else>Copy {{ count ? 'selected' : 'list' }}</span>
    </b-button>
    <slot />
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'

// Columns in the order they are pasted, always all six, so that lists copied at
// different times line up when pasted under each other.
const COLUMNS = ['id', 'name', 'email', 'reason', 'added', 'by']

const props = defineProps({
  count: {
    type: Number,
    required: true,
  },
  limit: {
    type: Number,
    required: true,
  },
  // Ids of the rows on screen that may be selected.
  shownIds: {
    type: Array,
    required: true,
  },
  // Rows to put on the clipboard: the selected ones, or everything shown when
  // nothing is selected. Each is { id, name, email, reason?, added?, by? }.
  copyRows: {
    type: Array,
    required: false,
    default: () => [],
  },
  // Whether to offer Copy at all. The spammer list offers it, for the spam team's
  // records. The members list does not: moderators do not take member lists away
  // (.claude/rules/conventions.md, "Moderators do not export member data").
  copy: {
    type: Boolean,
    required: false,
    default: true,
  },
  busy: {
    type: Boolean,
    required: false,
    default: false,
  },
})

defineEmits(['select-all', 'clear'])

const copied = ref(false)
const copyFailed = ref(false)

const allShownSelected = computed(
  () => props.count >= props.limit || props.count >= props.shownIds.length
)

const copyTitle = computed(() =>
  props.count
    ? 'Copy the selected members as plain text, one per line'
    : 'Copy everything shown as plain text, one per line'
)

// A field as it goes on the clipboard: on one line, and never something a
// spreadsheet would run. A spammer chooses their own display name, and a name
// beginning with =, +, - or @ is a formula once pasted into Excel or Sheets; a
// leading apostrophe makes it text there and is invisible in a document.
function cell(value) {
  const text = String(value ?? '').replace(/[\t\r\n]+/g, ' ')
  return /^[=+\-@]/.test(text) ? "'" + text : text
}

// One member per line, fields separated by tabs, so it pastes into a spreadsheet
// as columns and into a document as readable lines. Nothing else comes with it.
function plainText(rows) {
  return rows.map((r) => COLUMNS.map((c) => cell(r[c])).join('\t')).join('\n')
}

async function copyList() {
  const text = plainText(props.copyRows)
  copyFailed.value = false

  try {
    if (import.meta.client) {
      if (process.env.IS_APP) {
        window.cordova.plugins.clipboard.copy(text)
      } else {
        await navigator.clipboard.writeText(text)
      }
    }

    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch (e) {
    console.error('Copy to clipboard failed', e)
    copyFailed.value = true
    setTimeout(() => (copyFailed.value = false), 3000)
  }
}

defineExpose({ plainText })
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';

.bulk-bar {
  background-color: $gray-100;
}
</style>
