<template>
  <div>
    <div>
      <div>
        <ModHelpAdmins />
        <b-tabs v-model="tabIndex" content-class="mt-3" card lazy>
          <b-tab active>
            <template #title>
              <h2 class="ms-2 me-2" @click="fetchPending">
                Pending
                <b-badge v-if="pendingcount" variant="danger">
                  {{ pendingcount }}
                </b-badge>
              </h2>
            </template>
            <ModGroupSelect
              v-model="groupidshow"
              all
              modonly
              :work="['pendingadmins']"
              class="mb-2"
            />
            <div v-if="pending.length">
              <ModAdmin
                v-for="admin in pending"
                :id="admin.id"
                :key="'pendingadmin-' + admin.id"
                :open="pending.length === 1"
              />
            </div>
            <div v-else>No ADMINs to review just now.</div>
          </b-tab>
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2">Create</h2>
            </template>
            <label for="groupidcreate" class="fw-bold">Group:</label>
            <ModGroupSelect
              id="groupidcreate"
              v-model="groupidcreate"
              modonly
              :systemwide="supportOrAdmin"
              class="mb-2"
            />
            <NoticeMessage
              v-if="groupidcreate < 0"
              class="mt-1 mb-1"
              variant="danger"
            >
              This is a suggested ADMIN. All local communities will get "copies"
              of this (unless they've opted out), and mods can then
              edit/approve/reject them. Members won't receive multiple copies.
              Copies only go to members active in the last six months.
            </NoticeMessage>
            <div
              v-if="groupidcreate < 0"
              class="modguidance border border-2 border-info rounded p-3 mb-3 mt-3"
            >
              <b-form-group
                label="Guidance for local moderators (NOT sent to members):"
                label-for="modguidance"
                label-class="mb-0 fw-bold"
              >
                <p class="small text-muted mb-1">
                  Optional. Local moderators see this above their copy of the
                  ADMIN, to help them adapt it for their community. It is stored
                  separately and is never part of the email that goes to
                  members.
                </p>
                <b-form-textarea
                  id="modguidance"
                  v-model="modguidance"
                  rows="5"
                  spellcheck="true"
                  placeholder="e.g. Please add your own local details at the end, and delete the paragraph about X if it doesn't apply to your area."
                />
              </b-form-group>
            </div>
            <VeeForm ref="form">
              <b-form-group
                label="Subject of ADMIN:"
                label-for="subject"
                label-class="mb-0"
              >
                <p>ADMINs come in two flavours:</p>
                <ul>
                  <li>
                    Essential - everyone must receive them. These are important
                    announcements about the running of the group.
                  </li>
                  <li>
                    Newsletter - people can opt out (via the setting which
                    mentions "to remind you"). These are encouragements to
                    freegle more, fundraising mails or newsletters.
                  </li>
                </ul>
                <OurToggle
                  v-model="essential"
                  class="mt-2"
                  :height="30"
                  :width="150"
                  :font-size="14"
                  :sync="true"
                  :labels="{ checked: 'Essential', unchecked: 'Newsletter' }"
                  variant="modgreen"
                />
              </b-form-group>
              <b-form-group
                v-if="!essential && supportOrAdmin"
                label="Template:"
                label-for="template"
                label-class="mb-0"
              >
                <b-form-select
                  id="template"
                  v-model="selectedTemplate"
                  class="mb-2"
                >
                  <option :value="null">None</option>
                </b-form-select>
              </b-form-group>
              <div v-if="selectedTemplate">
                <NoticeMessage class="mt-1 mb-3" variant="warning">
                  This admin uses a pre-designed email template. The subject,
                  body and donate buttons are all built into the template.
                  Editing will be disabled after creation.
                </NoticeMessage>
                <p>
                  <strong>Subject:</strong>
                  {{ templateDefaults[selectedTemplate]?.subject }}
                </p>
              </div>
              <div v-else>
                <b-form-group
                  label="Subject of ADMIN:"
                  label-for="subject"
                  label-class="mb-0"
                >
                  <Field
                    id="subject"
                    v-model="subject"
                    name="subject"
                    type="text"
                    placeholder="Subject (don't include ADMIN - added automatically)"
                    :rules="validateSubject"
                    class="form-control"
                  />
                  <ErrorMessage name="subject" class="text-danger fw-bold" />
                </b-form-group>
                <b-form-group
                  label="Body of ADMIN:"
                  label-for="body"
                  label-class="mb-0"
                >
                  <Field
                    id="body"
                    v-model="body"
                    as="textarea"
                    name="body"
                    rows="15"
                    max-rows="8"
                    spellcheck="true"
                    type="textarea"
                    placeholder="Put your message in here.  Plain-text only."
                    :rules="validateBody"
                    :validate-on-input="true"
                    class="form-control"
                  />
                  <ErrorMessage name="body" class="text-danger fw-bold" />
                </b-form-group>
                <div class="mjml-part mb-3">
                  <b-form-checkbox v-model="useMjml" class="mb-2">
                    Also add a designed version using MJML (experts only)
                  </b-form-checkbox>
                  <div v-if="useMjml">
                    <NoticeMessage variant="danger" class="mb-2">
                      <p class="fw-bold mb-1">
                        Only use this if you know what you are doing.
                      </p>
                      <p class="mb-0">
                        A mistake here goes straight into the inbox of every
                        member who gets this ADMIN, and email programs are far
                        less forgiving than web browsers. If you have not
                        written MJML before, leave this off and send the plain
                        text above. That always works.
                      </p>
                    </NoticeMessage>
                    <p class="small mb-1">
                      <ExternalLink :href="MJML_SITE">MJML</ExternalLink> is a
                      markup language for designing emails that look right in
                      all the main email programs and on phones. Design and
                      check yours in the
                      <ExternalLink :href="MJML_TRY_IT"
                        >MJML live editor</ExternalLink
                      >
                      first, then paste it here.
                    </p>
                    <ul class="small">
                      <li>
                        Paste only the <code>&lt;mj-section&gt;</code> elements
                        from inside <code>&lt;mj-body&gt;</code>. Freegle adds
                        its own header, footer and unsubscribe links around
                        them.
                      </li>
                      <li>
                        Members whose email shows formatted mail see this
                        version. Everyone else sees the plain text above, so
                        both must say the same thing.
                      </li>
                      <li>
                        Scripts, forms, embedded frames and unsafe links are
                        removed before it is sent. Your test email shows exactly
                        what is left.
                      </li>
                    </ul>
                    <b-form-textarea
                      id="mjml"
                      v-model="mjml"
                      rows="12"
                      class="font-monospace"
                      spellcheck="false"
                      placeholder="<mj-section>&#10;  <mj-column>&#10;    <mj-text>...</mj-text>&#10;  </mj-column>&#10;</mj-section>"
                    />
                    <div v-if="mjmlError" class="text-danger fw-bold">
                      {{ mjmlError }}
                    </div>
                    <ModAdminFooterPreview :mjml="mjml || ''" class="mt-2" />
                  </div>
                </div>
                <!-- A designed (MJML) version carries its own buttons. -->
                <template v-if="!useMjml">
                  <p>
                    You can optionally add a big button into the ADMIN, and
                    specify where it will go.
                  </p>
                  <b-form-group
                    label="Call To Action text:"
                    label-for="ctatext"
                    label-class="mb-0"
                  >
                    <b-form-input
                      id="ctatext"
                      v-model="ctatext"
                      class="mb-3"
                      placeholder="(Option) Text for a big button"
                    />
                  </b-form-group>
                  <b-form-group
                    label="Call To Action link:"
                    label-for="ctalink"
                    label-class="mb-0"
                  >
                    <b-form-input
                      id="ctalink"
                      v-model="ctalink"
                      class="mb-3"
                      placeholder="(Optional) Link for a big button"
                    />
                  </b-form-group>
                </template>
                <b-form-group
                  label="Send after (optional):"
                  label-for="sendafter"
                  label-class="mb-0"
                  description="Leave empty to send as soon as it is approved. Otherwise it is held until this time."
                  class="mb-3"
                >
                  <b-form-input
                    id="sendafter"
                    v-model="sendafter"
                    type="datetime-local"
                    style="max-width: 250px"
                  />
                </b-form-group>
              </div>
            </VeeForm>
            <NoticeMessage variant="info" class="mb-3 test-first">
              Creating the ADMIN sends nothing. It goes to the Pending tab,
              where it is approved and sent to members.
              <span v-if="useMjml">
                Because it has a designed (MJML) version, you will need to send
                yourself a test from there before it can be approved.
              </span>
            </NoticeMessage>
            <p v-if="createError" class="text-danger fw-bold">
              {{ createError }}
            </p>
            <b-button
              class="mt-2 mb-2"
              size="lg"
              :variant="groupidcreate < 0 ? 'danger' : 'primary'"
              :disabled="!canCreateForGroup || creating"
              @click="create"
            >
              <v-icon v-if="created" icon="check" />
              <v-icon v-else-if="creating" icon="sync" class="fa-spin" />
              <v-icon v-else icon="save" />
              <span v-if="groupidcreate < 0">
                Suggest to all communities (nothing is sent yet)
              </span>
              <span v-else> Save to Pending ADMINs (nothing is sent yet) </span>
            </b-button>
            <p>
              It's a good idea to have a fellow mod take a look at an ADMIN
              before it goes out, to spot typos.
            </p>
          </b-tab>
          <b-tab>
            <template #title>
              <h2 class="ms-2 me-2" @click="fetchPrevious">Previous</h2>
            </template>
            <ModGroupSelect
              v-model="groupidprevious"
              all
              modonly
              class="mb-2"
            />
            <p>
              If an ADMIN shows as queued for send, it usually takes a few
              minutes. If we are sending a lot of ADMINs it can take a few
              hours.
            </p>
            <p>
              If a suggested ADMIN doesn't show here it will be because you
              deleted it.
            </p>
            <div v-if="previous.length">
              <ModAdmin
                v-for="admin in previous"
                :id="admin.id"
                :key="'pendingadmin-' + admin.id"
                :open="previous.length === 1"
                @copy="copyAdmin($event)"
              />
            </div>
            <div v-else-if="previousDone">No previous ADMINs.</div>
            <infinite-loading
              :identifier="previousBump"
              :distance="distance"
              @infinite="loadMorePrevious"
            >
              <template #spinner><span /></template>
              <template #complete><span /></template>
              <template #no-results><span /></template>
            </infinite-loading>
          </b-tab>
        </b-tabs>
      </div>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted, useTemplateRef } from 'vue'
import { defineRule, Form as VeeForm, Field, ErrorMessage } from 'vee-validate'
import { required, email, min, max } from '@vee-validate/rules'
import { useAdminsStore } from '~/stores/admins'
import { useModGroupStore } from '@/stores/modgroup'
import { inputToSendAfter } from '~/modtools/composables/useAdminSendAfter'
import {
  textProblem,
  mjmlProblem,
  apiMessage,
  MJML_SITE,
  MJML_TRY_IT,
} from '~/modtools/composables/useAdminContent'
import ModAdminFooterPreview from '~/modtools/components/ModAdminFooterPreview.vue'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/composables/useModMe'

defineRule('required', required)
defineRule('email', email)
defineRule('min', min)
defineRule('max', max)

const adminsStore = useAdminsStore()
const modGroupStore = useModGroupStore()
const { myGroups, supportOrAdmin } = useMe()
const { checkWork } = useModMe()

// Template ref for form
const form = useTemplateRef('form')

// Reactive state (was data())
const tabIndex = ref(0)
const groupidshow = ref(null)
const groupidcreate = ref(null)
const groupidprevious = ref(null)
const subject = ref(null)
const body = ref(null)
const ctatext = ref(null)
const ctalink = ref(null)
const modguidance = ref(null)
const sendafter = ref('')
const creating = ref(false)
const created = ref(false)
const essential = ref(true)
const selectedTemplate = ref(null)
const useMjml = ref(false)
const mjml = ref('')
const createError = ref(null)
const previousCursor = ref(null)
const previousDone = ref(false)
const previousStarted = ref(false)
const previousBump = ref(0)

const PREVIOUS_PAGE = 10
const distance = 200

// Pre-designed admin-email templates, keyed by template id (see the template <select> above).
// Empty now the one-off "Little Free Shop 2026" campaign is over; the mechanism stays for future
// templated admin emails.
const templateDefaults = {}

// Computed properties
const canCreateForGroup = computed(
  () => groupidcreate.value > 0 || groupidcreate.value === -2
)

const mjmlError = computed(() =>
  useMjml.value ? mjmlProblem(mjml.value) : null
)

// Everything that changes what the email looks like - the same fields the server checks the test
// token against.
function contentParams() {
  const groupid = groupidcreate.value > 0 ? groupidcreate.value : null

  if (selectedTemplate.value) {
    return {
      groupid,
      subject: templateDefaults[selectedTemplate.value]?.subject,
      text: '(template)',
      essential: false,
      template: selectedTemplate.value,
    }
  }

  return {
    groupid,
    subject: subject.value,
    text: body.value,
    mjml: useMjml.value ? mjml.value || '' : '',
    ctatext: useMjml.value ? null : ctatext.value,
    ctalink: useMjml.value ? null : ctalink.value,
    essential: essential.value,
  }
}

const pendingcount = computed(() => {
  let count = 0

  for (const g of myGroups.value) {
    const group = modGroupStore.get(g.id)
    if (group) {
      if (
        group.type === 'Freegle' &&
        (group.role === 'Owner' || group.role === 'Moderator')
      ) {
        if (group.work && group.work.pendingadmins) {
          count += group.work.pendingadmins
        }
      }
    }
  }

  return count
})

const pending = computed(() => {
  return Object.values(adminsStore.list)
    .filter((a) => a.pending)
    .sort(function (a, b) {
      return new Date(b.created).getTime() - new Date(a.created).getTime()
    })
})

const previous = computed(() => {
  return Object.values(adminsStore.list)
    .filter((a) => !a.pending)
    .sort(function (a, b) {
      return new Date(b.created).getTime() - new Date(a.created).getTime()
    })
})

// Watchers
watch(groupidshow, (newval) => {
  fetchAdmins(newval)
})

// The chooser reports "all communities" as 0 when it first appears; that is not a change.
watch(groupidprevious, (newval, oldval) => {
  if ((newval || 0) !== (oldval || 0)) {
    resetPrevious()
  }
})

// Methods
function fetchPending() {
  fetchAdmins(groupidshow.value)
}

// The history is the whole archive (over a thousand ADMINs), so it is read a page at a time as
// the moderator scrolls, and nothing is fetched until the Previous tab is opened.
function resetPrevious() {
  adminsStore.clear()
  previousCursor.value = null
  previousDone.value = false
  previousBump.value++
}

// The first time the tab opens, the list below starts loading by itself; after that, opening it
// again starts the list over from the newest.
function fetchPrevious() {
  if (previousStarted.value) {
    resetPrevious()
  }
}

async function loadMorePrevious($state) {
  previousStarted.value = true

  try {
    const page = await adminsStore.fetch({
      groupid: groupidprevious.value,
      pending: false,
      limit: PREVIOUS_PAGE,
      before: previousCursor.value || undefined,
    })

    if (page?.length) {
      previousCursor.value = page[page.length - 1].id
    }

    if (!page || page.length < PREVIOUS_PAGE) {
      previousDone.value = true
      $state.complete()
    } else {
      $state.loaded()
    }
  } catch (e) {
    $state.error()
  }
}

// Returns a message if the content can't be sent yet, or null.
async function contentInvalid() {
  if (selectedTemplate.value) {
    return null
  }

  const validate = await form.value.validate()
  if (!validate.valid) {
    return 'Please fix the problems above first.'
  }

  if (
    !useMjml.value &&
    ((ctatext.value && !ctalink.value) || (!ctatext.value && ctalink.value))
  ) {
    return 'A big button needs both its text and its link.'
  }

  return mjmlError.value
}

async function create() {
  createError.value = await contentInvalid()
  if (createError.value) {
    return
  }

  const params = contentParams()

  if (selectedTemplate.value) {
    params.editprotected = true
  } else {
    const sendAfterIso = inputToSendAfter(sendafter.value)

    if (sendAfterIso) {
      params.sendafter = sendAfterIso
    }

    // Guidance for local mods only applies to a system-wide ADMIN, and is sent as its own field.
    if (groupidcreate.value < 0 && modguidance.value) {
      params.modguidance = modguidance.value
    }
  }

  creating.value = true
  try {
    await adminsStore.add(params)
  } catch (e) {
    createError.value = apiMessage(
      e,
      "Couldn't create the ADMIN - please try again."
    )
    return
  } finally {
    creating.value = false
  }
  created.value = true

  setTimeout(() => {
    created.value = false
  }, 2000)

  checkWork(true)
}

async function fetchAdmins(groupid) {
  await adminsStore.clear()
  await adminsStore.fetch({
    groupid,
    pending: true,
  })
}

function validateSubject(value) {
  if (!value) {
    return 'Please enter a subject.'
  }

  if (value.toLowerCase().includes('admin')) {
    return 'Do not include ADMIN in your subject.'
  }

  return true
}

function validateBody(value) {
  if (!value) {
    return 'Please add the message.'
  }
  return textProblem(value) || true
}

function copyAdmin(admin) {
  essential.value = admin.essential === 1
  groupidcreate.value = admin.groupid
  subject.value = admin.subject
  body.value = admin.text
  mjml.value = admin.mjml || ''
  useMjml.value = !!admin.mjml
  ctatext.value = admin.ctatext
  ctalink.value = admin.ctalink
  tabIndex.value = 1
}

// Lifecycle - mounted
onMounted(() => {
  fetchAdmins(groupidshow.value)
})
</script>
<style scoped>
/* The global form label style (bold, pushed down) is for field labels, not a checkbox. */
.mjml-part :deep(.form-check-label) {
  margin-top: 0;
  font-weight: normal;
}
</style>
