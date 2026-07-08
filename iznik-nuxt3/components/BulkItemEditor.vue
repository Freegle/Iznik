<template>
  <div class="bulkeditor">
    <!-- Choose how to add items. Nothing else shows until a choice is made. -->
    <p class="bulkeditor__intro">
      How would you like to add your items? You can type them in one at a time,
      or fill in a spreadsheet and upload it.
    </p>
    <div
      class="bulkeditor__mode mb-3"
      role="group"
      aria-label="How to add items"
    >
      <b-button
        :variant="mode === 'manual' ? 'primary' : 'outline-secondary'"
        data-testid="mode-manual"
        @click="mode = 'manual'"
      >
        <v-icon icon="pen" /> Type them in
      </b-button>
      <b-button
        :variant="mode === 'upload' ? 'primary' : 'outline-secondary'"
        data-testid="mode-upload"
        @click="mode = 'upload'"
      >
        <v-icon icon="table" /> Upload a spreadsheet
      </b-button>
    </div>

    <!-- MANUAL: items table on top, batch-photo tools full-width below. -->
    <div v-if="mode === 'manual'" class="bulkeditor__cols">
      <!-- Shared hidden picker for the per-row "Add photo" button. -->
      <input
        ref="rowFileInput"
        type="file"
        accept="image/*"
        class="d-none"
        data-testid="row-photo-input"
        @change="onRowFile"
      />
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
            :class="{ 'brow--over': dragOverIdx === idx, 'brow--drag': isDragging }"
            @dragover.prevent="dragOverIdx = idx"
            @drop.prevent="onDropToItem($event, idx)"
          >
            <div
              class="bcell-photo brow__photos"
              :class="{
                'bcell-photo--target': selectedTrayPhoto !== null || isDragging,
              }"
              :data-testid="'item-photocell-' + idx"
              @click="onRowPhotoClick(idx)"
            >
              <Spinner v-if="item.uploading" :size="20" />
              <template v-else>
                <div
                  v-for="(p, pi) in item.photos"
                  :key="p.id || pi"
                  class="pthumb pthumb--sm"
                  draggable="true"
                  title="Drag to move, click to remove"
                  @dragstart="onDragStart($event, idx, pi)"
                  @dragend="onDragEnd"
                  @click.stop="unassign(idx, pi)"
                >
                  <OurUploadedImage
                  v-if="p.ouruid"
                  :src="p.ouruid"
                  :width="56"
                  fit="cover"
                  alt=""
                />
                <img v-else :src="thumbSrc(p)" alt="" />
                </div>
                <div
                  v-if="!item.photos.length && item.photourl"
                  class="pthumb pthumb--sm"
                  title="From spreadsheet link"
                >
                  <img :src="item.photourl" alt="" />
                </div>
                <!-- While a batch photo is "picked up", the cell is a drop
                     target — show that instead of the add button. -->
                <span
                  v-else-if="
                    !item.photos.length &&
                    (selectedTrayPhoto !== null || isDragging)
                  "
                  class="brow__drop"
                  aria-hidden="true"
                >
                  <v-icon icon="image" />
                </span>
                <!-- Otherwise: add a photo straight to this row (one per item). -->
                <b-button
                  v-else-if="!item.photos.length"
                  variant="link"
                  class="brow__addphoto p-0"
                  :data-testid="'item-addphoto-' + idx"
                  :aria-label="'Add a photo for item ' + (idx + 1)"
                  title="Add a photo for this item"
                  @click.stop="addPhotoToRow(item)"
                >
                  <v-icon icon="camera" />
                </b-button>
              </template>
            </div>

            <b-form-input
              v-model="item.name"
              class="bfield"
              size="sm"
              placeholder="Item name"
              maxlength="255"
              :data-testid="'item-name-' + idx"
              :aria-label="'Item ' + (idx + 1) + ' name'"
            />
            <b-form-input
              v-model.number="item.quantity"
              class="bfield bfield--qty"
              size="sm"
              type="number"
              min="1"
              max="999"
              :data-testid="'item-qty-' + idx"
              :aria-label="'Item ' + (idx + 1) + ' quantity'"
            />
            <b-form-select
              v-model="item.condition"
              :options="conditions"
              size="sm"
              class="bfield"
              :data-testid="'item-condition-' + idx"
              :aria-label="'Item ' + (idx + 1) + ' condition'"
            />
            <b-form-input
              v-model="item.dimensions"
              class="bfield"
              size="sm"
              placeholder="optional"
              maxlength="255"
              :aria-label="'Item ' + (idx + 1) + ' size (optional)'"
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

        <p v-if="photoError" class="small text-danger mt-2 mb-0" role="alert">
          {{ photoError }}
        </p>
      </div>

      <aside class="bulkeditor__tools">
        <div class="tool">
          <div class="tool__title">Photos (optional)</div>
          <p class="tool__help">
            Two ways to add them: tap
            <v-icon icon="camera" class="text-success" /> on an item's row to
            add a photo just for that item — or, if you've taken a pile of
            photos, add them all here and drag each onto its item.
          </p>
          <PhotoUploader
            v-model="uploaderModel"
            type="Message"
            :max-photos="50"
            compact
            empty-title="Add a batch of photos"
            empty-subtitle="Then drag each onto its item below"
          />
          <div
            v-if="tray.length || uploadingPhotos.length"
            class="bulkeditor__tray"
            data-testid="photo-tray"
          >
            <span class="bulkeditor__tray-label">
              <template v-if="selectedTrayPhoto !== null">
                <v-icon icon="plus" /> Now tap the item this photo belongs to
              </template>
              <template v-else>
                <v-icon icon="arrow-down" /> Drag a photo onto its item below —
                or tap a photo, then tap its item
              </template>
            </span>
            <div class="bulkeditor__tray-strip">
              <div
                v-for="(p, i) in tray"
                :key="p.id || i"
                class="pthumb"
                :class="{ 'pthumb--selected': selectedTrayPhoto === i }"
                draggable="true"
                role="button"
                :aria-pressed="selectedTrayPhoto === i"
                :data-testid="'tray-photo-' + i"
                @dragstart="onDragStart($event, -1, i)"
                @dragend="onDragEnd"
                @click="onTrayPhotoClick(i)"
              >
                <OurUploadedImage
                  v-if="p.ouruid"
                  :src="p.ouruid"
                  :width="56"
                  fit="cover"
                  alt=""
                />
                <img v-else :src="thumbSrc(p)" alt="" />
                <button
                  type="button"
                  class="pthumb__x"
                  aria-label="Remove photo"
                  @click.stop="removeTrayPhoto(i)"
                >
                  ×
                </button>
              </div>
              <!-- Photos still uploading: shown so nothing is hidden, but not
                   draggable until they finish and move into the tray above. -->
              <div
                v-for="(p, i) in uploadingPhotos"
                :key="'up-' + (p.tempId || p.preview || i)"
                class="pthumb pthumb--uploading"
                title="Uploading…"
                aria-label="Photo uploading"
              >
                <img v-if="p.preview" :src="p.preview" alt="" />
                <span class="pthumb__spin"><Spinner :size="18" /></span>
              </div>
            </div>
          </div>
        </div>
      </aside>
    </div>

    <!-- UPLOAD: download the template, fill it in, upload it. -->
    <div v-else-if="mode === 'upload'" class="bulkeditor__upload">
      <p class="mb-2">
        Download this template, fill in a row per item, then upload it here. It
        has a condition drop-down and an optional <strong>photo</strong> column
        (http links — we fetch &amp; store them). Accepts .xlsx or CSV.
      </p>
      <div class="d-flex gap-2 flex-wrap align-items-center">
        <a
          class="btn btn-outline-success"
          href="/freegle-clearance-template.xlsx"
          download
          data-testid="download-template"
        >
          <v-icon icon="download" /> Download template
        </a>
        <label class="btn btn-primary mb-0">
          <v-icon icon="upload" /> Upload spreadsheet
          <input
            type="file"
            accept=".csv,.tsv,.xlsx,text/csv"
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
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import {
  BULK_CONDITIONS,
  parseItemsCsv,
  parseItemsRows,
  blankBulkItem,
} from '~/composables/useBulkItems'
import { useBulkPhotoUpload } from '~/composables/useBulkPhotoUpload'
import PhotoUploader from '~/components/PhotoUploader'
import OurUploadedImage from '~/components/OurUploadedImage.vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  // Unassigned photos "pile" — lifted out so the parent can persist it across a
  // refresh (photos uploaded but not yet dragged onto an item).
  tray: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'update:tray'])

const conditions = BULK_CONDITIONS

// Internal list, seeded once from the incoming value (or one blank row).
const items = ref(
  props.modelValue && props.modelValue.length
    ? props.modelValue.map((i) => ({ photos: [], ...i }))
    : [{ ...blankBulkItem(), photos: [] }]
)

// null = no choice yet (show only the prompt + buttons); 'manual' = type items
// into the table; 'upload' = import a spreadsheet. If we're seeded with real
// content (a restored draft — named items or tray photos), open the table
// straight away rather than making the user pick "type them in" again.
const seededWithContent =
  (props.modelValue &&
    props.modelValue.some((i) => i && i.name && i.name.trim())) ||
  (props.tray && props.tray.length > 0)
const mode = ref(seededWithContent ? 'manual' : null)

// The uploader's model — we drain finished uploads from it into `tray`,
// so it always shows just the "Add photos" affordance (never a big gallery).
const uploaderModel = ref([])
// Seed the tray from the parent (restored draft) once, then own it locally and
// emit changes back up so the parent can persist it.
const tray = ref(
  props.tray && props.tray.length ? props.tray.map((p) => ({ ...p })) : []
)
watch(
  tray,
  (val) => {
    emit('update:tray', val)
  },
  { deep: true }
)

// Photos still uploading (no real numeric id yet) — kept in the uploader model
// until they finish, when the drain watch moves them into `tray`. Surfaced in
// the tray strip so an in-flight photo is never invisible.
const uploadingPhotos = computed(() =>
  uploaderModel.value.filter((p) => !(p.id && typeof p.id === 'number'))
)

const importMessage = ref('')
const photoError = ref('')

// Per-row "Add photo": a single shared file picker, plus the item it should
// attach to (captured by object reference, not index — addItem unshifts, so
// indices shift while an upload is in flight).
const { uploadPhoto } = useBulkPhotoUpload()
const rowFileInput = ref(null)
let pendingPhotoItem = null

// Drag source: { from: -1 for tray else item index, index: position in that list }
let dragSrc = null
const dragOverIdx = ref(null)
// True while a photo is being dragged, so every item row can advertise itself as
// a drop target (not just the small photo cell).
const isDragging = ref(false)

// Tap-to-assign (touch fallback for drag-and-drop, which doesn't fire on touch):
// the index of the tray photo the user has tapped to "pick up", or null.
const selectedTrayPhoto = ref(null)

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
  // Append below the existing rows, so the list builds downward in the order
  // the offerer adds things (photos sit above the list).
  items.value.push({ ...blankBulkItem(), photos: [] })
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
  // Indices shifted — drop any pending tap selection to avoid mis-assignment.
  selectedTrayPhoto.value = null
}

function onDragStart(e, from, index) {
  dragSrc = { from, index }
  isDragging.value = true
  if (e.dataTransfer) {
    e.dataTransfer.effectAllowed = 'move'
    // Required for Firefox to initiate the drag.
    e.dataTransfer.setData('text/plain', String(index))
  }
}

// Drag finished (dropped or cancelled, anywhere) — clear the drag cues.
function onDragEnd() {
  isDragging.value = false
  dragOverIdx.value = null
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

async function onDropToItem(e, idx) {
  dragOverIdx.value = null
  isDragging.value = false
  const item = items.value[idx]
  if (!item) return

  // External file drop (from the desktop / file explorer): upload each image
  // and attach it to this item. Without this the browser navigates to the
  // dropped file (opens a new tab) instead of uploading it.
  const files = e?.dataTransfer?.files
  if (files && files.length) {
    photoError.value = ''
    item.uploading = true
    try {
      for (const file of files) {
        if (!file.type || !file.type.startsWith('image/')) continue
        const photo = await uploadPhoto(file, { type: 'Message' })
        if (!item.photos) item.photos = []
        item.photos.push(photo)
      }
    } catch (err) {
      photoError.value =
        "Sorry — that photo couldn't be uploaded. Please try again."
    } finally {
      item.uploading = false
    }
    return
  }

  // Internal drag: a tray thumbnail or a thumbnail from another row.
  const photo = takeDragged()
  if (photo) {
    item.photos.push(photo)
  }
}

// Tap a tray photo to pick it up (or tap again to cancel).
function onTrayPhotoClick(i) {
  selectedTrayPhoto.value = selectedTrayPhoto.value === i ? null : i
}

// Tap an item while a tray photo is picked up to assign it there.
function onRowPhotoClick(idx) {
  if (selectedTrayPhoto.value === null) return
  const photo = tray.value.splice(selectedTrayPhoto.value, 1)[0]
  selectedTrayPhoto.value = null
  if (photo && items.value[idx]) {
    items.value[idx].photos.push(photo)
  }
}

// Click a row thumbnail to send it back to the tray.
function unassign(idx, pi) {
  const photo = items.value[idx].photos.splice(pi, 1)[0]
  if (photo) tray.value.push(photo)
}

// Per-row "Add photo": open the shared picker, remembering which item it's for.
function addPhotoToRow(item) {
  pendingPhotoItem = item
  if (rowFileInput.value) rowFileInput.value.click()
}

// Upload the chosen file and attach it straight to that item's row.
async function onRowFile(e) {
  const file = e.target.files && e.target.files[0]
  e.target.value = '' // allow re-picking the same file
  const item = pendingPhotoItem
  pendingPhotoItem = null
  if (!file || !item) return
  photoError.value = ''
  item.uploading = true
  try {
    const photo = await uploadPhoto(file, { type: 'Message' })
    if (!item.photos) item.photos = []
    item.photos.push(photo)
  } catch (err) {
    photoError.value = "Sorry — that photo couldn't be uploaded. Please try again."
  } finally {
    item.uploading = false
  }
}

// Prepend a parsed batch of items (newest at the top), keeping their order, and
// switch to the table so the imported rows are visible/editable.
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
  mode.value = 'manual'
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
      prependItems(parseItemsRows(rows))
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

defineExpose({
  items,
  mode,
  applyImport,
  applyImportText,
  addItem,
  removeItem,
  tray,
  selectedTrayPhoto,
  onTrayPhotoClick,
  onRowPhotoClick,
  addPhotoToRow,
  onRowFile,
  photoError,
  isDragging,
  dragOverIdx,
  onDragStart,
  onDragEnd,
  onDropToItem,
})
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.bulkeditor__intro {
  font-size: 0.95rem;
  color: $color-gray--dark;
  margin-bottom: 0.6rem;
}

.bulkeditor__mode {
  display: flex;
  gap: 0.5rem;
}

/* Items table on top, photos as a full-width row below it — so the table keeps
   its full width (no squeezed columns) and the photo area is wider and shorter. */
.bulkeditor__cols {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.bulkeditor__items {
  min-width: 0;
  order: 2;
}

/* Photos above the list of items. */
.bulkeditor__tools {
  width: 100%;
  order: 1;
}

.tool {
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  padding: 0.6rem 0.7rem;
  background-color: $color-gray--lighter;
}

.tool__title {
  font-weight: 600;
  font-size: 0.9rem;
  margin-bottom: 0.4rem;
}

.tool__help {
  font-size: 0.85rem;
  color: $color-gray--dark;
  margin-bottom: 0.6rem;
}

.bulkeditor__upload {
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  padding: 0.9rem 1rem;
  background-color: $color-gray--lighter;
}

/* Shared grid for the heading row and each item row, so columns line up. */
.bgrid {
  display: grid;
  grid-template-columns: 38px minmax(7rem, 1fr) 4.5rem 8rem 6rem 1.75rem;
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
  transition: background-color 0.12s ease, outline-color 0.12s ease,
    padding 0.12s ease;
}

/* While a photo is being dragged, every row shows it can receive a drop. */
.brow--drag {
  outline: 1px dashed $color-gray--base;
  outline-offset: -3px;
  border-radius: 6px;

  .brow__drop {
    border-color: $color-green--darker;
    color: $color-green--darker;
  }
}

/* The row under the pointer: strong highlight and a little taller, so it's a
   big, obvious target even though the photo cell itself is small. */
.brow--over {
  background-color: $color-green-background;
  outline: 2px dashed $color-green--darker;
  outline-offset: -2px;
  border-radius: 6px;
  padding-top: 0.7rem;
  padding-bottom: 0.7rem;

  .brow__drop {
    border-style: solid;
    border-color: $color-green--darker;
    color: $color-green--darker;
    transform: scale(1.15);
  }
}

/* Every input/select fills its grid column, so the row reads as one tidy table. */
.bfield,
.bfield :deep(input),
.bfield :deep(select) {
  width: 100%;
}

.bfield--qty :deep(input),
.bfield--qty {
  text-align: center;
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

/* Per-row "add a photo" affordance — a camera button sized like the drop slot. */
.brow__addphoto {
  width: 34px;
  height: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px dashed $color-gray--light;
  border-radius: 4px;
  color: $color-green--darker;
  text-decoration: none;

  &:hover,
  &:focus {
    border-color: $color-green--darker;
    background-color: $color-green-background;
  }
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

  /* OurUploadedImage renders a <picture><img>; reach into it so the thumbnail
     fills the cell just like a plain <img> does. */
  :deep(picture),
  :deep(picture img) {
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

/* An in-flight upload: dimmed, not draggable, with a spinner overlay. */
.pthumb--uploading {
  cursor: default;

  img {
    opacity: 0.5;
  }
}

.pthumb__spin {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

/* Tap-to-assign cues: the picked-up tray photo, and the item photo cells that
   are valid drop targets while one is picked up. */
.pthumb--selected {
  outline: 3px solid $color-green--darker;
  outline-offset: 1px;
}

.bcell-photo--target {
  cursor: pointer;

  .brow__drop {
    border-color: $color-green--darker;
    border-style: solid;
    color: $color-green--darker;
  }
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

/* Tighten the table's column widths on narrow screens so it stays readable. */
@include media-breakpoint-down(md) {
  .bgrid {
    grid-template-columns: 34px minmax(5rem, 1fr) 3.5rem 6.5rem 4.5rem 1.5rem;
  }
}
</style>
