<template>
  <div>
    <client-only>
      <b-row class="m-0">
        <b-col cols="12" lg="8" offset-lg="2" class="bg-white">
          <h1 class="mt-3">
            {{ isEditing ? 'Edit your clearance' : 'Offer lots of items at once' }}
          </h1>
          <template v-if="!isEditing">
            <p class="text-muted mb-2">
              New to Freegle? Each thing you give away is an <em>offer</em>, and
              people reply to you in the chat to ask for it. A clearance lets you
              list lots of items in one post instead of posting them one at a
              time — people tell you which ones they'd like and how many.
            </p>
            <p class="text-muted">
              You'll hear from several Freeglers and you'll need to organise who
              gets what and when they collect it. We're building an assisted way
              to make that easy — if you'd like to try it, email
              <a href="mailto:geeks@ilovefreegle.org">geeks@ilovefreegle.org</a>.
            </p>
          </template>

          <template v-if="hasClearance">
          <!-- Where (postcode) first: it picks the community. Not shown when
               editing — the group is already set on the existing post. -->
          <template v-if="!isEditing">
            <h2 class="bulk-section">Where are you?</h2>
            <PostCode
              :value="initialPostcode"
              @selected="postcodeSelect"
              @cleared="postcodeClear"
            />
            <ComposeGroup v-if="postcodeValid" class="mt-2" />
            <NoticeMessage
              v-if="postcode && noGroups"
              variant="warning"
              class="mt-2"
            >
              There's no Freegle community covering that area yet.
            </NoticeMessage>
          </template>

          <h2 class="bulk-section">What are you offering?</h2>
          <b-form-input
            v-model="title"
            placeholder="e.g. Office Clearance"
            maxlength="60"
            data-testid="clearance-title"
            aria-label="Offer title"
          />
          <p class="bulk-help mt-2 mb-1">
            A few words about the whole offer — this applies to every item.
            Don't put anything here that you'd only want to share once you've
            agreed someone can have an item; use
            <strong>Access instructions</strong>
            below for that.
          </p>
          <b-form-textarea
            v-model="description"
            rows="4"
            placeholder="e.g. Charity office clearance — everything must go by Friday. Collection from central Brighton."
            maxlength="2000"
            aria-label="Description of the whole offer"
          />

          <h2 class="bulk-section">The items</h2>
          <BulkItemEditor
            :key="editorKey"
            v-model="items"
            v-model:tray="tray"
          />

          <h2 class="bulk-section">When can people collect?</h2>
          <p class="bulk-help">
            These are shown publicly. People pick one when they reply and we ask
            them to confirm it, so collections stay in set times.
          </p>
          <div
            v-for="(s, i) in slots"
            :key="i"
            class="d-flex gap-2 mb-2 align-items-center"
          >
            <b-form-input
              v-model="slots[i]"
              placeholder="e.g. Tue 7 Apr, 10am–4pm"
              maxlength="120"
              :data-testid="'slot-' + i"
              :aria-label="'Collection time ' + (i + 1)"
            />
            <b-button
              variant="link"
              class="text-danger p-0"
              aria-label="Remove collection time"
              @click="removeSlot(i)"
            >
              <v-icon icon="trash" />
            </b-button>
          </div>
          <b-button
            variant="outline-primary"
            size="sm"
            data-testid="add-slot"
            @click="slots.push('')"
          >
            <v-icon icon="plus" /> Add a collection time
          </b-button>

          <h2 class="bulk-section">Closing date</h2>
          <p class="bulk-help">
            Optional. After this date the offer disappears. Leave it blank to
            keep it open until you take it down.
          </p>
          <b-form-input
            v-model="deadline"
            type="date"
            :min="today"
            class="bulk-deadline"
            data-testid="clearance-deadline"
            aria-label="Closing date for replies"
          />

          <h2 class="bulk-section">Access instructions</h2>
          <p class="bulk-help">
            Only shared with someone once you promise them an item — e.g. the
            exact address, a gate code, or intercom instructions.
          </p>
          <b-form-textarea
            v-model="accessInstructions"
            rows="2"
            placeholder="e.g. 12 High St, side door, buzz flat 3. Optional."
            maxlength="2000"
            data-testid="clearance-access"
            aria-label="Access instructions, shared once you promise an item"
          />

          <div class="my-4 d-flex align-items-center flex-wrap gap-2">
            <SpinButton
              variant="primary"
              size="lg"
              :icon-name="isEditing ? 'save' : 'gift'"
              :disabled="!canSubmit"
              :label="isEditing ? 'Save changes' : 'Post these items'"
              data-testid="clearance-submit"
              @handle="submit"
            />
            <b-button
              variant="outline-secondary"
              data-testid="clearance-clear"
              @click="onClearForm"
            >
              <v-icon icon="eraser" /> Clear form
            </b-button>
            <b-button
              variant="outline-secondary"
              data-testid="clearance-export"
              title="Save the whole form (items, photos, slots…) to a file"
              @click="exportForm"
            >
              <v-icon icon="download" /> Export
            </b-button>
            <label
              class="btn btn-outline-secondary mb-0"
              title="Load a previously exported form"
            >
              <v-icon icon="upload" /> Import
              <input
                type="file"
                accept="application/json,.json"
                class="d-none"
                data-testid="clearance-import"
                @change="importForm"
              />
            </label>
          </div>
          <NoticeMessage v-if="wentWrong" variant="danger" class="mt-2">
            Something went wrong posting your items. Please try again.
          </NoticeMessage>
          </template>
          <NoticeMessage
            v-else-if="loggedIn"
            variant="warning"
            class="mt-3"
          >
            This feature isn't available on your account yet.
          </NoticeMessage>
        </b-col>
      </b-row>
    </client-only>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter, useRoute } from '#imports'
import {
  setup,
  postcodeSelect,
  postcodeClear,
  makeCanSubmit,
} from '~/composables/useCompose'
import { useComposeStore } from '~/stores/compose'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import PostCode from '~/components/PostCode.vue'
import ComposeGroup from '~/components/ComposeGroup.vue'
import BulkItemEditor from '~/components/BulkItemEditor.vue'
import SpinButton from '~/components/SpinButton.vue'
import NoticeMessage from '~/components/NoticeMessage.vue'

const composeStore = useComposeStore()
const messageStore = useMessageStore()
const authStore = useAuthStore()
const router = useRouter()
const route = useRoute()

// Edit mode: /give/clearance?id=<msgid> loads an existing clearance to edit
// (reached from the Manage page). Otherwise we're creating a new one.
const editId = computed(() => {
  const q = Array.isArray(route.query.id) ? route.query.id[0] : route.query.id
  const n = parseInt(q, 10)
  return Number.isFinite(n) && n > 0 ? n : null
})
const isEditing = computed(() => editId.value != null)

const { email, loggedIn, initialPostcode, postcode, postcodeValid, noGroups } =
  await setup('Offer')

// The clearance feature is gated on the Clearance permission. (The email-outreach
// sub-feature is gated further, to users whose primary email is @ilovefreegle.org,
// where the outreach option is offered.)
const me = computed(() => authStore.user)
const hasClearance = computed(() =>
  (me.value?.permissions || []).includes('Clearance')
)

const title = ref('')
const description = ref('')
const items = ref([])
const tray = ref([]) // unassigned uploaded photos, persisted with the draft
const slots = ref([''])
const accessInstructions = ref('')
const deadline = ref('')
const wentWrong = ref(false)

// Bumped after restoring a saved draft to force BulkItemEditor to re-seed from
// the restored items (it reads its model once, on setup).
const editorKey = ref(0)
// Guard so the child editor's initial empty emit during mount doesn't overwrite
// a saved draft before we've had a chance to restore it (see onMounted).
const draftReady = ref(false)

// Earliest selectable closing date is today.
const today = new Date().toISOString().slice(0, 10)

// Persist photos by their server identity only — drop the transient blob
// `preview` URL (invalid after a refresh and potentially large).
function slimPhotos(list) {
  return (list || []).map(({ preview, ...rest }) => rest)
}
function slimItems(list) {
  return (list || []).map((it) => ({ ...it, photos: slimPhotos(it.photos) }))
}

function isEmptyDraft() {
  return (
    !title.value.trim() &&
    !description.value.trim() &&
    !accessInstructions.value.trim() &&
    !deadline.value &&
    !items.value.some((i) => i.name && i.name.trim()) &&
    !slots.value.some((s) => s && s.trim()) &&
    !tray.value.length
  )
}

// Save the whole in-progress form to the persisted compose store on any change,
// so a refresh doesn't lose it. Skipped until the initial restore has run.
watch(
  [title, description, items, tray, slots, accessInstructions, deadline],
  () => {
    // In edit mode we're editing a posted offer, not the create-draft — don't
    // persist it into the compose store's clearance draft.
    if (!draftReady.value || isEditing.value) return
    if (isEmptyDraft()) {
      composeStore.clearClearanceDraft()
      return
    }
    composeStore.saveClearanceDraft({
      title: title.value,
      description: description.value,
      items: slimItems(items.value),
      tray: slimPhotos(tray.value),
      slots: slots.value,
      accessInstructions: accessInstructions.value,
      deadline: deadline.value,
    })
  },
  { deep: true }
)

// Clear everything (and the saved draft).
function clearForm() {
  title.value = ''
  description.value = ''
  items.value = []
  tray.value = []
  slots.value = ['']
  accessInstructions.value = ''
  deadline.value = ''
  composeStore.clearClearanceDraft()
  editorKey.value++ // re-seed the item editor to a single blank row
}

// Mirror compose.js createDraft's mapping so the export shows exactly the
// bulkitems payload the server would receive (post name-filter, with attachments).
function buildSubmitBulkitems() {
  return items.value
    .filter((i) => i.name && i.name.trim())
    .map((i) => ({
      name: i.name.trim(),
      quantity: parseInt(i.quantity, 10) || 1,
      condition: i.condition || 'Unknown',
      dimensions: i.dimensions || null,
      photourl: i.photourl || null,
      description: i.description || null,
      attachments: Array.isArray(i.attachments) ? i.attachments : [],
    }))
}

// --- Edit mode -------------------------------------------------------------
function stripSubjectPrefix(s) {
  return (s || '').replace(/^(OFFER|WANTED):\s*/i, '')
}

// Load an existing clearance into the form for editing.
async function loadForEdit(id) {
  const m = (await messageStore.fetch(id, true)) || messageStore.byId(id)
  if (!m) return
  title.value = m.item?.name || stripSubjectPrefix(m.subject)
  description.value = m.textbody || ''
  items.value = (m.bulkitems || []).map((bi) => ({
    id: bi.id,
    name: bi.name,
    quantity: bi.quantity,
    condition: bi.condition,
    dimensions: bi.dimensions,
    description: bi.description,
    photourl: bi.photourl,
    photos: (bi.attachments || []).map((a) => ({
      id: a.id,
      ouruid: a.ouruid,
      path: a.path,
      paththumb: a.paththumb,
    })),
    attachments: (bi.attachments || []).map((a) => a.id),
  }))
  slots.value = m.bulkslots && m.bulkslots.length ? [...m.bulkslots] : ['']
  accessInstructions.value = m.accessinstructions || ''
  deadline.value = m.deadline ? String(m.deadline).slice(0, 10) : ''
  editorKey.value++ // re-seed the editor from the loaded items
}

// Edit payload keeps each item's id so the server updates it in place
// (preserving interest) instead of deleting and recreating the catalogue.
function buildEditBulkitems() {
  return items.value
    .filter((i) => i.name && i.name.trim())
    .map((i) => ({
      ...(i.id ? { id: i.id } : {}),
      name: i.name.trim(),
      quantity: parseInt(i.quantity, 10) || 1,
      condition: i.condition || 'Unknown',
      dimensions: i.dimensions || null,
      photourl: i.photourl || null,
      description: i.description || null,
      attachments: Array.isArray(i.attachments) ? i.attachments : [],
    }))
}

// Save edits to an existing clearance via PATCH, then return to Manage.
async function saveEdit() {
  const payload = {
    id: editId.value,
    item: title.value.trim(),
    textbody: description.value,
    bulkitems: buildEditBulkitems(),
    bulkslots: slots.value.map((s) => s.trim()).filter(Boolean),
    accessinstructions: accessInstructions.value.trim(),
  }
  if (deadline.value) {
    payload.deadline = new Date(deadline.value).toISOString()
  }
  await messageStore.patch(payload)
  router.push('/clearance/' + editId.value)
}

// Export the whole form to a JSON file (debugging aid + save/share a draft).
function exportForm() {
  const data = {
    _kind: 'freegle-clearance-form',
    _exportedAt: new Date().toISOString(),
    title: title.value,
    description: description.value,
    items: slimItems(items.value),
    tray: slimPhotos(tray.value),
    slots: slots.value,
    accessInstructions: accessInstructions.value,
    deadline: deadline.value,
    // Exactly what createDraft would send to the server:
    wouldSend: {
      bulkitems: buildSubmitBulkitems(),
      bulkslots: slots.value.map((s) => s.trim()).filter(Boolean),
      accessinstructions: accessInstructions.value.trim(),
    },
  }
  const blob = new Blob([JSON.stringify(data, null, 2)], {
    type: 'application/json',
  })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'clearance-form-' + Date.now() + '.json'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// Import a previously exported form and repopulate the fields.
async function importForm(e) {
  const file = e.target.files && e.target.files[0]
  e.target.value = ''
  if (!file) return
  try {
    const d = JSON.parse(await file.text())
    title.value = d.title || ''
    description.value = d.description || ''
    items.value = d.items || []
    tray.value = d.tray || []
    slots.value = d.slots && d.slots.length ? d.slots : ['']
    accessInstructions.value = d.accessInstructions || ''
    deadline.value = d.deadline || ''
    editorKey.value++
  } catch (err) {
    console.error('Failed to import clearance form', err)
    // eslint-disable-next-line no-alert
    window.alert('Could not read that file — is it a clearance export?')
  }
}

// Confirm before wiping a form that has anything in it.
function onClearForm() {
  if (
    isEmptyDraft() ||
    window.confirm(
      'Clear the whole form? This removes all items, photos and details you have entered.'
    )
  ) {
    clearForm()
  }
}

// Bulk freegling is login-only — there's no logged-out flow. Show the
// sign-up/login modal immediately so people start from a signed-in state.
onMounted(async () => {
  if (editId.value) {
    // Editing an existing clearance (from the Manage page).
    await loadForEdit(editId.value)
  } else {
    // Restore an in-progress clearance the offerer was filling in before a refresh.
    const saved = composeStore.clearanceDraft
    if (saved) {
      title.value = saved.title || ''
      description.value = saved.description || ''
      items.value = saved.items || []
      tray.value = saved.tray || []
      slots.value = saved.slots && saved.slots.length ? saved.slots : ['']
      accessInstructions.value = saved.accessInstructions || ''
      deadline.value = saved.deadline || ''
      editorKey.value++ // force the editor to seed from the restored items
    }
  }
  draftReady.value = true

  if (!loggedIn.value) {
    authStore.forceLogin = true
  }
})

function removeSlot(i) {
  slots.value.splice(i, 1)
  if (!slots.value.length) slots.value.push('')
}

const messageValid = computed(
  () => !!title.value.trim() && items.value.some((i) => i.name && i.name.trim())
)

const canSubmit = makeCanSubmit({
  messageValid,
  loggedIn,
  emailValid: computed(() => false),
  emailBelongsToSomeoneElse: computed(() => false),
  postcodeValid,
  closed: computed(() => false),
  noGroups,
  // When editing an existing clearance the group/location is already set, so we
  // don't ask for a postcode again.
  requirePostcode: !isEditing.value,
})

async function submit(callback) {
  if (!loggedIn.value) {
    authStore.forceLogin = true
    if (callback) callback()
    return
  }
  wentWrong.value = false
  try {
    if (isEditing.value) {
      await saveEdit()
      return
    }
    const message = {
      type: 'Offer',
      item: title.value.trim(),
      description: description.value,
      availablenow: 1,
      attachments: [],
      bulkitems: items.value,
      bulkslots: slots.value.map((s) => s.trim()).filter(Boolean),
      accessinstructions: accessInstructions.value.trim(),
    }
    const wouldSendItems = buildSubmitBulkitems()
    console.log('[clearance] submit start', {
      title: message.item,
      itemCount: items.value.length,
      namedItemCount: wouldSendItems.length,
      bulkitems: wouldSendItems,
      trayPhotos: tray.value.length,
      perItem: items.value.map((i) => ({
        name: i.name,
        photos: (i.photos || []).length,
        attachments: i.attachments || [],
      })),
      bulkslots: message.bulkslots,
      accessLen: message.accessinstructions.length,
    })
    if (!wouldSendItems.length) {
      console.warn(
        '[clearance] submit has NO named items — nothing bulk will be stored'
      )
    }
    const submitOptions = {}
    if (deadline.value) {
      submitOptions.deadline = new Date(deadline.value).toISOString()
    }
    const id = await composeStore.createDraft(message, email.value)
    console.log('[clearance] createDraft -> draft id', id)
    const res = await composeStore.submitDraft(id, email.value, submitOptions)
    console.log('[clearance] submitDraft done', { id, res })
    // Posted successfully — drop the saved draft so it doesn't reappear.
    composeStore.clearClearanceDraft()
    router.push('/myposts')
  } catch (e) {
    console.error('[clearance] submit FAILED', e)
    wentWrong.value = true
  } finally {
    if (callback) callback()
  }
}

definePageMeta({ layout: 'default' })
useHead({ title: 'Offer lots of items at once' })
</script>

<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'assets/css/_color-vars.scss';

/* One consistent heading style for every section on the page. */
.bulk-section {
  font-size: 1.15rem;
  font-weight: 600;
  color: $color-green--darker;
  margin: 1.25rem 0 0.5rem;
}

.bulk-help {
  font-size: 0.85rem;
  color: $color-gray--dark;
  margin-bottom: 0.5rem;
}
</style>
