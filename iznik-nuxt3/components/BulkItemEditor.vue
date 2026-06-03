<template>
  <div class="bulkeditor">
    <!-- ONE uploader at the top. Finished uploads drop into the tray below. -->
    <div class="bulkeditor__uploader">
      <PhotoUploader
        v-model="uploaderModel"
        type="Message"
        :max-photos="50"
        empty-title="Add photos"
        empty-subtitle="Add all your photos here, then drag each onto its item"
      />
    </div>

    <!-- Tray of uploaded-but-unassigned photos. Drag one onto an item row. -->
    <div v-if="tray.length" class="bulkeditor__tray" data-testid="photo-tray">
      <span class="bulkeditor__tray-label">
        <v-icon icon="arrow-down" /> Drag a photo onto its item
      </span>
      <div class="bulkeditor__tray-strip">
        <div
          v-for="(p, i) in tray"
          :key="p.id || i"
          class="pthumb"
          draggable="true"
          @dragstart="onDragStart($event, -1, i)"
        >
          <img :src="thumbSrc(p)" alt="" />
          <button
            type="button"
            class="pthumb__x"
            aria-label="Remove photo"
            @click="removeTrayPhoto(i)"
          >
            ×
          </button>
        </div>
      </div>
    </div>

    <!-- Spreadsheet import -->
    <div class="bulkeditor__import">
      <b-button
        variant="outline-secondary"
        size="sm"
        @click="showImport = !showImport"
      >
        <v-icon icon="list" /> Paste or upload a spreadsheet
      </b-button>
      <div v-if="showImport" class="bulkeditor__import-panel mt-2">
        <p class="small text-muted mb-1">
          Download the spreadsheet template (it has a condition drop-down and a
          <strong>photo</strong> column for optional http(s) links — we download
          and store those photos for you). Fill it in, then either copy the rows
          and paste them below, or save it as CSV and choose the file. Columns:
          <strong
            >name, quantity, condition, dimensions, photo, description</strong
          >.
        </p>
        <a
          class="btn btn-outline-success btn-sm mb-2"
          href="/freegle-clearance-template.xlsx"
          download
          data-testid="download-template"
        >
          <v-icon icon="download" /> Download spreadsheet template
        </a>
        <b-form-textarea
          v-model="importText"
          rows="4"
          placeholder="Office desk, 4, Good, 120x80cm&#10;Chair, 14, Used"
          data-testid="import-text"
        />
        <div class="d-flex gap-2 mt-2 align-items-center flex-wrap">
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

    <!-- Add control sits above the list so new rows appear at the top. -->
    <div class="d-flex justify-content-between align-items-center mt-3 mb-1">
      <b-button
        variant="outline-primary"
        size="sm"
        data-testid="add-item"
        @click="addItem"
      >
        <v-icon icon="plus" /> Add another item
      </b-button>
      <span class="small text-muted">
        {{ totalItems }} item{{ totalItems === 1 ? '' : 's' }},
        {{ totalQuantity }} thing{{ totalQuantity === 1 ? '' : 's' }} in total
      </span>
    </div>

    <!-- Compact item rows (most recently added first) -->
    <ul class="bulkeditor__list mt-2">
      <li
        v-for="(item, idx) in items"
        :key="idx"
        class="brow"
        :class="{ 'brow--over': dragOverIdx === idx }"
        @dragover.prevent
        @dragenter.prevent="dragOverIdx = idx"
        @dragleave="onRowLeave(idx)"
        @drop="onDropToItem(idx)"
      >
        <!-- Photo cell: small thumbnails + drop hint -->
        <div class="brow__photos">
          <div
            v-for="(p, pi) in item.photos"
            :key="p.id || pi"
            class="pthumb pthumb--sm"
            draggable="true"
            :title="'Drag to move, click to remove'"
            @dragstart="onDragStart($event, idx, pi)"
            @click="unassign(idx, pi)"
          >
            <img :src="thumbSrc(p)" alt="" />
          </div>
          <!-- Fall back to a spreadsheet-supplied photo URL when no photo dropped. -->
          <div
            v-if="!item.photos.length && item.photourl"
            class="pthumb pthumb--sm"
            :title="'From spreadsheet link'"
          >
            <img :src="item.photourl" alt="" />
          </div>
          <span
            v-else-if="!item.photos.length"
            class="brow__drop"
            aria-hidden="true"
          >
            <v-icon icon="image" />
          </span>
        </div>

        <b-form-input
          v-model="item.name"
          class="brow__name"
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
          class="brow__qty"
        />
        <b-form-select
          v-model="item.condition"
          :options="conditions"
          size="sm"
          class="brow__cond"
          :data-testid="'item-condition-' + idx"
        />
        <b-form-input
          v-model="item.dimensions"
          class="brow__dims"
          placeholder="Size (optional)"
          maxlength="255"
        />
        <b-button
          variant="link"
          class="brow__rm text-danger p-0"
          :aria-label="'Remove ' + (item.name || 'item')"
          :data-testid="'item-remove-' + idx"
          @click="removeItem(idx)"
        >
          <v-icon icon="trash" />
        </b-button>
      </li>
    </ul>
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

// The top uploader's model — we drain finished uploads from it into `tray`,
// so it always shows just the "Add photos" affordance (never a big gallery).
const uploaderModel = ref([])
const tray = ref([])

const showImport = ref(false)
const importText = ref('')
const importMessage = ref('')

// Drag source: { from: -1 for tray else item index, index: position in that list }
let dragSrc = null
const dragOverIdx = ref(null)

const totalItems = computed(
  () => items.value.filter((i) => i.name && i.name.trim()).length
)
const totalQuantity = computed(() =>
  items.value
    .filter((i) => i.name && i.name.trim())
    .reduce((sum, i) => sum + (parseInt(i.quantity, 10) || 0), 0)
)

function thumbSrc(p) {
  return p.paththumb || p.path || p.preview || ''
}

// Move fully-uploaded photos (real numeric id) out of the uploader into the tray.
watch(
  uploaderModel,
  (val) => {
    const done = val.filter((p) => p.id && typeof p.id === 'number')
    if (done.length) {
      tray.value.push(...done)
      uploaderModel.value = val.filter(
        (p) => !(p.id && typeof p.id === 'number')
      )
    }
  },
  { deep: true }
)

function addItem() {
  // Newest at the top, next to the photo tray, so dragging a photo onto a
  // just-added item is a short move (no scrolling to the bottom).
  items.value.unshift({ ...blankBulkItem(), photos: [] })
}

function removeItem(idx) {
  // Return any photos on this row to the tray so they aren't lost.
  const it = items.value[idx]
  if (it && it.photos && it.photos.length) tray.value.push(...it.photos)
  items.value.splice(idx, 1)
  if (!items.value.length) addItem()
}

function removeTrayPhoto(i) {
  tray.value.splice(i, 1)
}

function onDragStart(e, from, index) {
  dragSrc = { from, index }
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    // Required for Firefox to initiate the drag.
    e.dataTransfer.setData('text/plain', String(index))
  }
}

function onRowLeave(idx) {
  if (dragOverIdx.value === idx) dragOverIdx.value = null
}

function takeDragged() {
  if (!dragSrc) return null
  const { from, index } = dragSrc
  let photo
  if (from === -1) {
    photo = tray.value.splice(index, 1)[0]
  } else if (items.value[from]) {
    photo = items.value[from].photos.splice(index, 1)[0]
  }
  dragSrc = null
  return photo || null
}

function onDropToItem(idx) {
  dragOverIdx.value = null
  const photo = takeDragged()
  if (photo && items.value[idx]) {
    items.value[idx].photos.push(photo)
  }
}

// Click a row thumbnail to send it back to the tray.
function unassign(idx, pi) {
  const photo = items.value[idx].photos.splice(pi, 1)[0]
  if (photo) tray.value.push(photo)
}

function applyImport() {
  const parsed = parseItemsCsv(importText.value)
  if (!parsed.length) {
    importMessage.value = 'No items found'
    return
  }
  const existing = items.value.filter((i) => i.name && i.name.trim())
  // Most-recently-added (the imported batch) at the top, keeping their order.
  items.value = [...parsed.map((i) => ({ ...i, photos: [] })), ...existing]
  importMessage.value = `Added ${parsed.length} item${
    parsed.length === 1 ? '' : 's'
  }`
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

// Keep attachment IDs in sync with assigned photos and emit upward. Only write
// when the derived ids change, to avoid retriggering this deep watcher endlessly.
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

defineExpose({ items, applyImport, addItem, removeItem, importText, tray })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

.bulkeditor__tray {
  margin: 0.5rem 0 0.75rem;
  padding: 0.5rem;
  background-color: $color-gray--lightest;
  border: 1px dashed $color-gray--light;
  border-radius: 6px;
}

.bulkeditor__tray-label {
  display: block;
  font-size: 0.8rem;
  color: $color-gray--dark;
  margin-bottom: 0.35rem;
}

.bulkeditor__tray-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.pthumb {
  position: relative;
  width: 56px;
  height: 56px;
  border-radius: 4px;
  overflow: hidden;
  cursor: grab;
  background-color: $color-gray--lighter;
  flex: 0 0 auto;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    pointer-events: none;
  }

  &:active {
    cursor: grabbing;
  }
}

.pthumb--sm {
  width: 36px;
  height: 36px;
}

.pthumb__x {
  position: absolute;
  top: 0;
  right: 0;
  border: none;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  line-height: 1;
  padding: 0 4px;
  font-size: 0.8rem;
  cursor: pointer;
}

.bulkeditor__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.brow {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid $color-gray--lighter;
  flex-wrap: nowrap;
}

.brow--over {
  background-color: $color-green-background;
  border-radius: 6px;
}

.brow__photos {
  flex: 0 0 auto;
  display: flex;
  align-items: center;
  gap: 2px;
  min-width: 40px;
}

.brow__drop {
  width: 36px;
  height: 36px;
  border: 1px dashed $color-gray--light;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: $color-gray--normal;
}

.brow__name {
  flex: 2 1 8rem;
  min-width: 6rem;
}

.brow__dims {
  flex: 1 1 5rem;
  min-width: 4rem;
}

.brow__cond {
  flex: 0 0 8rem;
}

.brow__qty {
  flex: 0 0 5.5rem;
  width: 5.5rem;
}

.brow__rm {
  flex: 0 0 auto;
}

@include media-breakpoint-down(md) {
  .brow {
    flex-wrap: wrap;
  }
  .brow__name {
    flex: 1 1 100%;
  }
}
</style>
