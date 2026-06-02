<template>
  <div class="bulkeditor">
    <!-- Quick add from a spreadsheet. -->
    <div class="bulkeditor__import">
      <b-button variant="outline-secondary" size="sm" @click="showImport = !showImport">
        <v-icon icon="table" /> Paste or upload a spreadsheet
      </b-button>
      <div v-if="showImport" class="bulkeditor__import-panel mt-2">
        <p class="small text-muted mb-1">
          One item per line. Columns: <strong>name, quantity, condition,
          dimensions, description</strong> (a header row is optional). You can
          paste straight from a spreadsheet, or choose a CSV file.
        </p>
        <b-form-textarea
          v-model="importText"
          rows="4"
          placeholder="Office desk, 4, Good, 120x80cm&#10;Chair, 14, Used"
          data-testid="import-text"
        />
        <div class="d-flex gap-2 mt-2 align-items-center">
          <b-button
            variant="primary"
            size="sm"
            :disabled="!importText.trim()"
            data-testid="import-apply"
            @click="applyImport"
          >
            Add these items
          </b-button>
          <label class="btn btn-outline-secondary btn-sm mb-0">
            Choose CSV file…
            <input
              type="file"
              accept=".csv,text/csv"
              class="d-none"
              data-testid="import-file"
              @change="onFile"
            />
          </label>
          <span v-if="importMessage" class="small text-success">{{
            importMessage
          }}</span>
        </div>
      </div>
    </div>

    <!-- The item rows. -->
    <ul class="bulkeditor__list mt-3">
      <li v-for="(item, idx) in items" :key="idx" class="bulkeditor__row">
        <div class="bulkeditor__fields">
          <b-form-input
            v-model="item.name"
            class="bulkeditor__name"
            placeholder="Item name"
            maxlength="255"
            :data-testid="'item-name-' + idx"
          />
          <NumberIncrementDecrement
            v-model="item.quantity"
            :min="1"
            :max="999"
            size="sm"
            label="Quantity"
            :label-s-r-only="true"
            class="bulkeditor__qty"
          />
          <b-form-select
            v-model="item.condition"
            :options="conditions"
            size="sm"
            class="bulkeditor__condition"
            :data-testid="'item-condition-' + idx"
          />
          <b-form-input
            v-model="item.dimensions"
            class="bulkeditor__dims"
            placeholder="Size (optional)"
            maxlength="255"
          />
          <b-button
            variant="link"
            class="bulkeditor__remove text-danger"
            :aria-label="'Remove ' + (item.name || 'item')"
            :data-testid="'item-remove-' + idx"
            @click="removeItem(idx)"
          >
            <v-icon icon="trash" />
          </b-button>
        </div>
        <PhotoUploader
          v-model="item.photos"
          type="Message"
          :max-photos="4"
          class="bulkeditor__photos"
          empty-title="Add photos"
          empty-subtitle="Optional"
        />
      </li>
    </ul>

    <b-button variant="outline-primary" size="sm" data-testid="add-item" @click="addItem">
      <v-icon icon="plus" /> Add another item
    </b-button>
    <div class="small text-muted mt-2">
      {{ totalItems }} item{{ totalItems === 1 ? '' : 's' }},
      {{ totalQuantity }} thing{{ totalQuantity === 1 ? '' : 's' }} in total
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import {
  BULK_CONDITIONS,
  parseItemsCsv,
  blankBulkItem,
} from '~/composables/useBulkItems'
import NumberIncrementDecrement from '~/components/NumberIncrementDecrement'
import PhotoUploader from '~/components/PhotoUploader'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue'])

const conditions = BULK_CONDITIONS

// Internal list, seeded once from the incoming value (or one blank row).
const items = ref(
  props.modelValue && props.modelValue.length
    ? props.modelValue.map((i) => ({ photos: [], ...i }))
    : [{ ...blankBulkItem(), photos: [] }]
)

const showImport = ref(false)
const importText = ref('')
const importMessage = ref('')

const totalItems = computed(
  () => items.value.filter((i) => i.name && i.name.trim()).length
)
const totalQuantity = computed(() =>
  items.value
    .filter((i) => i.name && i.name.trim())
    .reduce((sum, i) => sum + (parseInt(i.quantity, 10) || 0), 0)
)

function addItem() {
  items.value.push({ ...blankBulkItem(), photos: [] })
}

function removeItem(idx) {
  items.value.splice(idx, 1)
  if (!items.value.length) addItem()
}

function applyImport() {
  const parsed = parseItemsCsv(importText.value)
  if (!parsed.length) {
    importMessage.value = 'No items found'
    return
  }
  // Replace any leading blank row, then append the parsed items.
  const existing = items.value.filter((i) => i.name && i.name.trim())
  items.value = [...existing, ...parsed.map((i) => ({ ...i, photos: [] }))]
  importMessage.value = `Added ${parsed.length} item${parsed.length === 1 ? '' : 's'}`
  importText.value = ''
}

function onFile(e) {
  const file = e.target.files && e.target.files[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    importText.value = String(reader.result || '')
    applyImport()
  }
  reader.readAsText(file)
}

// Keep attachment IDs in sync with uploaded photos and emit upward. Only write
// when the derived ids actually change, otherwise mutating `attachments` inside
// a deep watcher on `items` would retrigger the watcher endlessly.
watch(
  items,
  (val) => {
    for (const item of val) {
      const ids = (item.photos || [])
        .map((p) => p.id)
        .filter((id) => typeof id === 'number')
      if (JSON.stringify(item.attachments || []) !== JSON.stringify(ids)) {
        item.attachments = ids
      }
    }
    emit('update:modelValue', val)
  },
  { deep: true, immediate: true }
)

defineExpose({ items, applyImport, addItem, removeItem, importText })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.bulkeditor__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.bulkeditor__row {
  padding: 0.6rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.bulkeditor__fields {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.bulkeditor__name {
  flex: 2 1 12rem;
}

.bulkeditor__dims {
  flex: 1 1 8rem;
}

.bulkeditor__condition {
  flex: 0 0 9rem;
}

.bulkeditor__qty {
  width: 6.5rem;
}

.bulkeditor__photos {
  margin-top: 0.5rem;
}

.bulkeditor__remove {
  margin-left: auto;
}
</style>
