<template>
  <div v-if="admin">
    <b-card no-body>
      <b-card-header class="clickme" @click.prevent="expanded = !expanded">
        <b-row>
          <b-col cols="6" md="2">
            <v-icon icon="hashtag" class="text-muted" scale="0.75" />{{
              admin.id
            }}
          </b-col>
          <b-col cols="6" md="3" class="small">
            Created {{ timeago(admin.created) }}
            <span v-if="!admin.pending">
              <span v-if="admin.complete">
                Sent {{ timeago(admin.complete) }}
              </span>
              <span v-else> Queued for send </span>
            </span>
          </b-col>
          <b-col cols="12" md="4">
            {{ admin.subject }}
            <b-badge v-if="admin.mjml" variant="info" class="ms-1">
              Designed (MJML)
            </b-badge>
          </b-col>
          <b-col cols="12" md="3">
            <span class="d-block float-end">
              <v-icon v-if="!expanded" icon="caret-down" />
              <v-icon v-else icon="caret-up" />
            </span>
            {{ groupname }}
          </b-col>
        </b-row>
      </b-card-header>
      <b-card-body v-if="expanded">
        <NoticeMessage v-if="heldError" variant="warning" class="mb-2">
          {{ heldError }}
        </NoticeMessage>
        <NoticeMessage v-if="admin.heldby" variant="warning" class="mb-2">
          Held
          <span v-if="holder"> by {{ holder.displayname }} </span>. Please check
          before releasing it.
          <span class="text-muted small">
            {{ timeago(admin.heldat) }}
          </span>
        </NoticeMessage>
        <NoticeMessage
          v-if="admin.parentid && !admin.complete && !admin.modguidance"
          variant="info"
          class="mb-2"
        >
          <p>
            This is a copy of a suggested ADMIN which you might like to send on
            your group.
          </p>
          <p>
            You can edit it and <em>Save changes</em>, then
            <em>Approve and send</em>.
          </p>
          <ul>
            <li>
              <strong>It's always good to put your names at the end.</strong>
            </li>
            <li>Any changes you make will only apply to this copy.</li>
            <li>
              Members on multiple groups will only get one copy of this kind of
              ADMIN.
            </li>
          </ul>
          <p class="topspace">
            If this isn't suitable for your group, then click <em>Delete</em>.
          </p>
          <p>
            If you think this ADMIN could be improved, or would like to suggest
            more, please comment on the Google doc
            <ExternalLink
              href="https://docs.google.com/document/d/1e5zyMNwiaAtdxHP7fFkuPy4bLu-VAGmfgJOBClmqHD8/edit"
            >
              here </ExternalLink
            >.
          </p>
        </NoticeMessage>
        <NoticeMessage
          v-if="admin.modguidance"
          variant="info"
          class="modguidance mb-3 border border-2 border-info"
        >
          <h4 class="h6">
            <v-icon icon="info-circle" /> Guidance for local moderators - NOT
            sent to members
          </h4>
          <p class="small mb-1">
            This is advice on how you might adapt this ADMIN for your community.
            It is not part of the message and will not be in the email.
          </p>
          <p class="modguidance-text mb-0">{{ admin.modguidance }}</p>
        </NoticeMessage>
        <p class="text-muted">
          <span v-if="admin.parentid"> Suggested ADMIN </span>
          <b-button
            v-if="admin.complete"
            variant="white"
            class="float-end"
            @click="copyIt"
          >
            <v-icon icon="copy" /> Copy
          </b-button>
          <span v-else-if="admin.createdby">
            Created by
            {{ admin.createdby.displayname }}
            <span class="text-muted small">
              <v-icon icon="hashtag" class="text-muted" scale="0.75" />
              {{ admin.createdby.id }}
            </span>
          </span>
        </p>
        <OurToggle
          v-if="admin.pending && !admin.template && !admin.editprotected"
          v-model="essential"
          class="mb-2 type-toggle"
          :height="30"
          :width="150"
          :font-size="14"
          :sync="true"
          :labels="{ checked: 'Essential', unchecked: 'Newsletter' }"
          variant="modgreen"
        />
        <NoticeMessage
          :variant="admin.essential ? 'warning' : 'info'"
          class="mb-2"
        >
          <span v-if="admin.essential">
            Essential - will be sent to {{ audience }}.
          </span>
          <span v-else>
            Newsletter - will be sent to {{ audience }} who haven't opted out.
          </span>
        </NoticeMessage>
        <template v-if="admin.template">
          <NoticeMessage variant="warning" class="mb-2">
            This admin uses a pre-designed template and can't be edited. You can
            approve it to send, or delete it if it's not suitable for your
            group.
          </NoticeMessage>
          <p><strong>Subject:</strong> {{ admin.subject }}</p>
        </template>
        <template v-else>
          <b-form-group
            label="Subject of ADMIN:"
            label-for="subject"
            label-class="mb-0"
          >
            <b-form-input
              id="subject"
              v-model="admin.subject"
              class="mb-3"
              placeholder="Subject (don't include ADMIN - added automatically)"
            />
          </b-form-group>
          <template v-if="useMjml">
            <NoticeMessage variant="warning" class="mb-2 both-versions">
              <p class="fw-bold mb-1">
                This ADMIN has a plain text and an HTML version. Every member
                gets one of them, so any change must be made in both.
              </p>
              <p class="mb-0">
                Members whose email shows formatted mail get the designed
                version. Everyone else gets the plain text.
              </p>
            </NoticeMessage>
            <b-tabs content-class="mt-2" class="mb-3">
              <b-tab title="Plain text version" active>
                <b-form-textarea
                  id="body"
                  v-model="admin.text"
                  placeholder="Put your message in here.  Plain-text only."
                  rows="15"
                />
              </b-tab>
              <b-tab title="Designed (MJML) version">
                <NoticeMessage variant="info" class="mb-2 mjml-help">
                  <p class="mb-1">
                    To change the wording, edit the words between
                    <code>&lt;mj-text&gt;</code> and
                    <code>&lt;/mj-text&gt;</code>, or a button's words between
                    <code>&lt;mj-button ...&gt;</code> and
                    <code>&lt;/mj-button&gt;</code>. Leave the tags themselves,
                    anything inside <code>&lt; &gt;</code>, alone.
                  </p>
                  <p class="mb-0">
                    To see how it looks, use the copy button under the box and
                    paste into the
                    <ExternalLink :href="MJML_TRY_IT"
                      >MJML live editor</ExternalLink
                    >. You can edit it there and paste your sections back. Your
                    test email shows exactly what members will get.
                  </p>
                </NoticeMessage>
                <b-form-textarea
                  id="mjml"
                  v-model="admin.mjml"
                  class="font-monospace"
                  spellcheck="false"
                  rows="15"
                  placeholder="<mj-section>...</mj-section>"
                />
                <ModAdminFooterPreview :mjml="admin.mjml || ''" class="mt-2" />
                <b-button
                  variant="link"
                  size="sm"
                  class="remove-mjml p-0 mt-1"
                  @click="useMjml = false"
                >
                  Remove the designed version, and send the plain text only
                </b-button>
              </b-tab>
            </b-tabs>
          </template>
          <template v-else>
            <b-form-group
              label="Body of ADMIN:"
              label-for="body"
              label-class="mb-0"
            >
              <b-form-textarea
                id="body"
                v-model="admin.text"
                class="mb-3"
                placeholder="Put your message in here.  Plain-text only."
                rows="15"
              />
            </b-form-group>
            <div class="mjml-part mb-3">
              <b-form-checkbox v-model="useMjml">
                Add a designed version using MJML (experts only)
              </b-form-checkbox>
            </div>
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
          <!-- A designed (MJML) version carries its own buttons. -->
          <template v-if="!useMjml">
            <b-form-group
              label="Call To Action text:"
              label-for="ctatext"
              label-class="mb-0"
            >
              <b-form-input
                id="ctatext"
                v-model="admin.ctatext"
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
                v-model="admin.ctalink"
                class="mb-3"
                placeholder="(Optional) Link for a big button"
              />
            </b-form-group>
          </template>
        </template>
        <template v-if="admin.pending && hasMjml">
          <NoticeMessage
            v-if="needsTest && !tested"
            variant="danger"
            class="mb-2 test-first"
          >
            This ADMIN has a designed (MJML) version, so you need to send a test
            of it before you can approve it and send it to members.
          </NoticeMessage>
          <ModAdminTestSend
            v-model:email="testEmail"
            title="Send a test"
            :input-id="'testemail-' + admin.id"
            :testing="testing"
            :tested="tested"
            :tested-to="testedTo"
            :stale="!!testedKey && !tested"
            :error="testError"
            @send="sendTest"
          >
            See this ADMIN exactly as a member will, with your changes. The test
            goes only to the address below, not to any members.
            <span v-if="!needsTest">
              This is the suggested ADMIN unchanged, so a test is optional.
            </span>
          </ModAdminTestSend>
        </template>
      </b-card-body>
      <b-card-footer v-if="expanded && admin.pending">
        <p v-if="saveError" class="text-danger fw-bold">{{ saveError }}</p>
        <NoticeMessage v-if="oneSided" variant="warning" class="mb-2 one-sided">
          You've changed the {{ oneSided }} version but not the other one. Every
          member gets one of the two, so they should normally say the same.
          <b-button variant="white" size="sm" class="ms-2" @click="saveAnyway">
            Save anyway
          </b-button>
        </NoticeMessage>
        <b-button v-if="!admin.heldby" variant="warning" @click="deleteIt">
          <v-icon icon="trash-alt" /> Delete
        </b-button>
        <b-button
          v-if="
            !admin.editprotected &&
            !admin.template &&
            (!admin.heldby || admin.heldby === myid)
          "
          variant="white"
          @click="save"
        >
          <v-icon v-if="saving" icon="sync" class="text-success fa-spin" />
          <v-icon v-else-if="saved" icon="check" class="text-success" />
          <v-icon v-else icon="save" />
          Save changes
        </b-button>
        <b-button v-if="!admin.heldby" variant="white" @click="hold">
          <v-icon icon="pause" /> Hold
        </b-button>
        <b-button v-else variant="secondary" @click="release">
          <v-icon icon="play" /> Release
        </b-button>
        <b-button
          v-if="!admin.heldby"
          variant="primary"
          :disabled="needsTest && !tested"
          @click="approve"
        >
          <v-icon icon="check" />
          <span v-if="admin.essential">
            Approve and send to {{ audienceShort }}
          </span>
          <span v-else>
            Approve and send to {{ audienceShort }} who haven't opted out
          </span>
        </b-button>
      </b-card-footer>
    </b-card>
    <ConfirmModal
      v-if="showConfirmModal"
      :title="'Delete: ' + admin.subject"
      @confirm="deleteConfirmed"
      @hidden="showConfirmModal = false"
    />
  </div>
</template>
<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAdminsStore } from '~/stores/admins'
import { useUserStore } from '~/stores/user'
import { useGroupStore } from '~/stores/group'
import { useMe } from '~/composables/useMe'
import { useModMe } from '~/composables/useModMe'
import { useHeldNotice } from '~/composables/useHeldNotice'
import {
  sendAfterToInput,
  inputToSendAfter,
} from '~/modtools/composables/useAdminSendAfter'
import {
  textProblem,
  mjmlProblem,
  apiMessage,
  MJML_TRY_IT,
} from '~/modtools/composables/useAdminContent'
import { useAdminTestSend } from '~/modtools/composables/useAdminTestSend'
import ModAdminTestSend from '~/modtools/components/ModAdminTestSend.vue'
import ModAdminFooterPreview from '~/modtools/components/ModAdminFooterPreview.vue'

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
  open: {
    type: Boolean,
    required: false,
  },
})

const emit = defineEmits(['copy'])

const adminsStore = useAdminsStore()
const groupStore = useGroupStore()
const userStore = useUserStore()
const { myid } = useMe()
const { checkWork } = useModMe()
const { heldError, guardHold } = useHeldNotice()

const expanded = ref(false)
const saving = ref(false)
const saved = ref(false)
const saveError = ref(null)
const showConfirmModal = ref(false)

const admin = computed(() => adminsStore.get(props.id))

// Copies of a central ADMIN go only to members active recently; a community's own ADMIN goes to
// everyone (iznik-batch SendAdminCommand, CopyAdminsCommand).
const audience = computed(() =>
  admin.value?.activeonly
    ? 'members active in the last six months'
    : 'all members'
)
const audienceShort = computed(() =>
  admin.value?.activeonly ? 'recently active members' : 'all members'
)

// Essential (an ADMIN everyone gets) or Newsletter (members can opt out). Saved with the rest.
const essential = computed({
  get: () => !!admin.value?.essential,
  set: (val) => {
    if (admin.value) {
      admin.value.essential = val
    }
  },
})

// Whether this ADMIN has a designed (MJML) version, which a moderator can add or remove.
const useMjml = ref(!!admin.value?.mjml)

const groupname = computed(() => {
  if (!admin.value) return null
  const group = groupStore.get(admin.value.groupid)

  if (group) {
    return group.namedisplay
  }

  return null
})

// The send-after time is edited as a local datetime-local string and saved as ISO.
const sendafter = computed({
  get: () => sendAfterToInput(admin.value?.sendafter),
  set: (val) => {
    if (admin.value) {
      admin.value.sendafter = inputToSendAfter(val)
    }
  },
})

const holder = computed(() => {
  return admin.value?.heldby ? userStore.byId(admin.value.heldby) : null
})

onMounted(() => {
  expanded.value = props.open

  if (admin.value?.heldby) {
    // Get them in store so we can display their name.
    userStore.fetch(admin.value.heldby)
  }
})

function deleteIt() {
  showConfirmModal.value = true
}

function copyIt() {
  emit('copy', admin.value)
}

function deleteConfirmed() {
  adminsStore.delete({ id: props.id })
  checkWork(true)
}

// What the email will be made from, as currently edited - the same fields the server checks a
// test against. With a designed (MJML) version there is no big button: the MJML carries its own.
function contentParams() {
  // The card can be set up, or re-render, while its ADMIN is briefly not in the store (around an
  // approve or a refetch). It renders nothing then, so empty content is fine; reading null is not.
  const a = admin.value || {}
  return {
    groupid: a.groupid,
    subject: a.subject,
    text: a.text,
    mjml: useMjml.value ? a.mjml || '' : '',
    ctatext: useMjml.value ? '' : (a.ctatext ?? ''),
    ctalink: useMjml.value ? '' : (a.ctalink ?? ''),
    essential: !!a.essential,
    template: a.template || '',
  }
}

// Returns a message if the content can't be saved, or null.
function contentProblem() {
  const c = contentParams()
  if (useMjml.value && !c.mjml.trim()) {
    return 'Please add the MJML, or untick the designed version.'
  }
  return (
    textProblem(c.text) ||
    mjmlProblem(c.mjml) ||
    (!c.ctatext !== !c.ctalink
      ? 'A big button needs both its text and its link.'
      : null)
  )
}

// The content as loaded, to tell what has been edited since.
const loaded = ref(JSON.stringify(contentParams()))
const loadedText = ref(admin.value?.text)
const loadedMjml = ref(admin.value?.mjml || '')

// Whether what would be sent has a designed (MJML) version.
const hasMjml = computed(
  () => useMjml.value && !!(admin.value?.mjml || '').trim()
)

// A designed version needs a test before approving, unless this is a suggested ADMIN nobody has
// changed. Text-only ADMINs never need one.
const needsTest = computed(
  () =>
    hasMjml.value &&
    !(admin.value?.unedited && JSON.stringify(contentParams()) === loaded.value)
)

const {
  testEmail,
  testing,
  testError,
  testtoken,
  testedKey,
  testedTo,
  tested,
  sendTest,
} = useAdminTestSend(contentParams, () => contentProblem())

// Which version was changed when the other was not, while both exist, or null.
const oneSided = ref(null)
const oneSidedOk = ref(false)

function oneSidedChange() {
  if (!useMjml.value || !loadedMjml.value) {
    return null
  }
  const textChanged = admin.value.text !== loadedText.value
  const mjmlChanged = (admin.value.mjml || '') !== loadedMjml.value
  if (textChanged && !mjmlChanged) {
    return 'plain text'
  }
  if (mjmlChanged && !textChanged) {
    return 'designed (MJML)'
  }
  return null
}

function saveAnyway() {
  oneSidedOk.value = true
  save()
}

// Returns whether the changes were saved.
async function save() {
  saveError.value = contentProblem()
  if (saveError.value) {
    return false
  }

  oneSided.value = oneSidedOk.value ? null : oneSidedChange()
  if (oneSided.value) {
    return false
  }

  const content = contentParams()
  const params = {
    id: admin.value.id,
    essential: content.essential,
    subject: content.subject,
    text: content.text,
    mjml: content.mjml,
    ctatext: content.ctatext,
    ctalink: content.ctalink,
    sendafter: admin.value.sendafter ?? null,
    pending: true,
  }

  saving.value = true
  try {
    await guardHold(() => adminsStore.edit(params))
    if (heldError.value) {
      return false
    }
  } catch (e) {
    saveError.value = apiMessage(e, "Couldn't save - please try again.")
    return false
  } finally {
    saving.value = false
  }

  // What is saved now is the starting point for spotting a one-sided change.
  loadedText.value = admin.value.text
  loadedMjml.value = useMjml.value ? admin.value.mjml || '' : ''
  oneSidedOk.value = false

  saved.value = true
  setTimeout(() => {
    saved.value = false
  }, 2000)
  return true
}

function hold() {
  guardHold(() => adminsStore.hold({ id: admin.value.id }))
  checkWork(true)
}

function release() {
  adminsStore.release({ id: admin.value.id })
  checkWork(true)
}

async function approve() {
  if (!admin.value.editprotected && !admin.value.template && !(await save())) {
    return
  }

  if (needsTest.value && !tested.value) {
    return
  }

  await guardHold(() =>
    adminsStore.approve({ id: admin.value.id, testtoken: testtoken.value })
  )

  checkWork(true)
}
</script>

<style scoped>
.modguidance-text {
  white-space: pre-wrap;
}
</style>
