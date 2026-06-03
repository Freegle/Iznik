<template>
  <div class="bulkeditor">
    <div class="bulkeditor__cols">
      <!-- LEFT: the items table -->
      <div class="bulkeditor__items">
        <div class="bgrid bgrid--head">
          <span class="bcell-photo" />
          <span>Item</span>
          <span>Qty</span>
          <span>Condition</span>
          <span>Size</span>
          <span class="bcell-rm" />
        </div>

        <ul class="bulkeditor__list">
          <li
            v-for="(item, idx) in items"
            :key="idx"
            class="bgrid brow"
            :class="{ 'brow--over': dragOverIdx === idx }"
            @dragover.prevent
            @dragenter.prevent="dragOverIdx = idx"
            @dragleave="onRowLeave(idx)"
            @drop="onDropToItem(idx)"
          >
            <!-- Photo cell: small thumbnails + drop hint -->
            <div class="bcell-photo brow__photos">
              <div
                v-for="(p, pi) in item.photos"
                :key="p.id || pi"
                class="pthumb pthumb--sm"
                draggable="true"
                title="Drag to move, click to remove"
                @dragstart="onDragStart($event, idx, pi)"
                @click="unassign(idx, pi)"
              >
                <img :src="thumbSrc(p)" alt="" />
              </div>
              <div
                v-if="!item.photos.length && item.photourl"
                class="pthumb pthumb--sm"
                title="From spreadsheet link"
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
            <b-form-input
              v-model.number="item.quantity"
              class="brow__qty"
              type="number"
              min="1"
              max="999"
              :data-testid="'item-qty-' + idx"
              aria-label="Quantity"
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
              placeholder="optional"
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

        <div
          class="d-flex justify-content-between align-items-center mt-2 flex-wrap gap-2"
        >
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
            {{ totalQuantity }} thing{{ totalQuantity === 1 ? '' : 's' }} in
            total
          </span>
        </div>
      </div>

      <!-- RIGHT: upload tools -->
      <aside class="bulkeditor__tools">
        <div class="tool">
          <div class="tool__title">Photos</div>
          <PhotoUploader
            v-model="uploaderModel"
            type="Message"
            :max-photos="50"
            empty-title="Add photos"
            empty-subtitle="Then drag each onto its item"
          />
          <div
            v-if="tray.length"
            class="bulkeditor__tray"
            data-testid="photo-tray"
          >
            <span class="bulkeditor__tray-label">
              <v-icon icon="arrow-left" /> Drag a photo onto its item
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
        </div>

        <div class="tool">
          <div class="tool__title">Got a lot? Upload a spreadsheet</div>
          <p class="small text-muted mb-2">
            The template has a condition drop-down and an optional
            <strong>photo</strong> column (http links — we fetch &amp; store
            them). Fill it in and upload it.
          </p>
          <a
            class="btn btn-outline-success btn-sm mb-2 w-100"
            href="/freegle-clearance-template.xlsx"
            download
            data-testid="download-template"
          >
            <v-icon icon="download" /> Download template
          </a>
          <label class="btn btn-primary btn-sm mb-0 w-100">
            <v-icon icon="upload" /> Upload spreadsheet
            <input
              type="file"
              accept=".csv,.tsv,.xlsx,text/csv"
              class="d-none"
              data-testid="import-file"
              @change="onFile"
            />
          </label>
          <span v-if="importMessage" class="small text-success d-block mt-2">{{
            importMessage
          }}</span>
        </div>
      </aside>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import {
  BULK_CONDITIONS,
  parseItemsCsv,
  parseItemsRows,
  blankBulkItem,
} from '~/composables/useBulkItems'
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

// The uploader's model — we drain finished uploads from it into `tray`,
// so it always shows just the "Add photos" affordance (never a big gallery).
const uploaderModel = ref([])
const tray = ref([])

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

// Prepend a parsed batch of items (newest at the top), keeping their order.
function prependItems(parsed) {
  if (!parsed.length) {
    importMessage.value = 'No items found in that file'
    return
  }
  const existing = items.value.filter((i) => i.name && i.name.trim())
  items.value = [...parsed.map((i) => ({ ...i, photos: [] })), ...existing]
  importMessage.value = `Added ${parsed.length} item${
    parsed.length === 1 ? '' : 's'
  }`
}

function applyImportText(text) {
  prependItems(parseItemsCsv(text))
}
// exposed name kept stable for tests
function applyImport(text) {
  applyImportText(text)
}

async function onFile(e) {
  const file = e.target.files && e.target.files[0]
  if (!file) return
  try {
    if (/\.xlsx$/i.test(file.name)) {
      // Parse the filled .xlsx in the browser. read-excel-file is lazy-loaded so
      // it never bloats the main bundle — only fetched when someone uploads one.
      const readXlsxFile = (await import('read-excel-file')).default
      const rows = await readXlsxFile(file)
      const parsed = parseItemsRows(rows)
      prependItems(parsed)
    } else {
      applyImportText(await file.text())
    }
  } catch (err) {
    importMessage.value = "Sorry — couldn't read that file"
  } finally {
    // Allow re-uploading the same file.
    e.target.value = ''
  }
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

defineExpose({ items, applyImport, applyImportText, addItem, removeItem, tray })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.bulkeditor__cols {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
}

.bulkeditor__items {
  flex: 1 1 auto;
  min-width: 0;
}

/* Fixed-width tools rail on the right of the items. */
.bulkeditor__tools {
  flex: 0 0 16rem;
  width: 16rem;
}

.tool {
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  padding: 0.6rem 0.7rem;
  margin-bottom: 0.75rem;
  background-color: $color-gray--lighter;
}

.tool__title {
  font-weight: 600;
  font-size: 0.9rem;
  margin-bottom: 0.4rem;
}

/* Keep the uploader compact — no big empty drop zone. */
.bulkeditor__uploader,
:deep(.bulkeditor__tools .uploader) {
  max-height: 9rem;
}

/* Shared grid for the heading row and each item row, so columns line up. */
.bgrid {
  display: grid;
  grid-template-columns: 38px minmax(7rem, 1fr) 4.5rem 8rem 5.5rem 1.75rem;
  gap: 0.4rem;
  align-items: center;
}

.bgrid--head {
  font-size: 0.78rem;
  font-weight: 600;
  color: $color-gray--dark;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  padding: 0 0 0.25rem;
  border-bottom: 1px solid $color-gray--light;
}

.bulkeditor__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.brow {
  padding: 0.35rem 0;
  border-bottom: 1px solid $color-gray--lighter;
}

.brow--over {
  background-color: $color-green-background;
  border-radius: 6px;
}

.bcell-photo {
  display: flex;
  align-items: center;
  justify-content: center;
}

.brow__drop {
  width: 34px;
  height: 34px;
  border: 1px dashed $color-gray--light;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: $color-gray--normal;
}

.brow__qty :deep(input),
.brow__qty {
  text-align: center;
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
  width: 34px;
  height: 34px;
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

.bulkeditor__tray {
  margin-top: 0.5rem;
}

.bulkeditor__tray-label {
  display: block;
  font-size: 0.78rem;
  color: $color-gray--dark;
  margin-bottom: 0.35rem;
}

.bulkeditor__tray-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

/* Stack the tools under the items on narrow screens. */
@include media-breakpoint-down(md) {
  .bulkeditor__cols {
    flex-direction: column;
  }
  .bulkeditor__tools {
    flex-basis: auto;
    width: 100%;
  }
  .bgrid {
    grid-template-columns: 34px minmax(5rem, 1fr) 3.5rem 6.5rem 4.5rem 1.5rem;
  }
}
</style>
