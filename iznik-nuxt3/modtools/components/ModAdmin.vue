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
          v-if="admin.parentid && !admin.complete"
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
            This is advice from Support on how you might adapt this ADMIN for
            your community. It is not part of the message and will not be in the
            email.
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
        <NoticeMessage
          :variant="admin.essential ? 'warning' : 'info'"
          class="mb-2"
        >
          <span v-if="admin.essential">
            Essential - will be sent to all members.
          </span>
          <span v-else>
            Newsletter - will not be sent to members who have opted out.
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
          <b-form-group
            v-if="admin.mjml !== null && admin.mjml !== undefined"
            label="Designed (MJML) version of ADMIN:"
            label-for="mjml"
            label-class="mb-0"
          >
            <NoticeMessage variant="warning" class="mb-2">
              Members whose email shows formatted mail see this version, not the
              plain text above. If you change the text, make the same change
              here, or remove this version by emptying the box. Only edit it if
              you know
              <ExternalLink :href="MJML_SITE">MJML</ExternalLink>.
            </NoticeMessage>
            <b-form-textarea
              id="mjml"
              v-model="admin.mjml"
              class="mb-3 font-monospace"
              spellcheck="false"
              rows="12"
            />
          </b-form-group>
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
          <template v-if="!admin.mjml">
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
      </b-card-body>
      <b-card-footer v-if="expanded && admin.pending">
        <p v-if="saveError" class="text-danger fw-bold">{{ saveError }}</p>
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
        <b-button v-if="!admin.heldby" variant="primary" @click="approve">
          <v-icon icon="check" /> Approve and Send
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
  MJML_SITE,
} from '~/modtools/composables/useAdminContent'

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

// Returns whether the changes were saved.
async function save() {
  saveError.value =
    textProblem(admin.value.text) ||
    mjmlProblem(admin.value.mjml) ||
    (!admin.value.mjml && !admin.value.ctatext !== !admin.value.ctalink
      ? 'A big button needs both its text and its link.'
      : null)
  if (saveError.value) {
    return false
  }

  const params = {
    id: admin.value.id,
    subject: admin.value.subject,
    text: admin.value.text,
    ctatext: admin.value.ctatext ?? '',
    ctalink: admin.value.ctalink ?? '',
    sendafter: admin.value.sendafter ?? null,
    pending: true,
  }
  if (admin.value.mjml !== null && admin.value.mjml !== undefined) {
    params.mjml = admin.value.mjml
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

  await guardHold(() => adminsStore.approve({ id: admin.value.id }))

  checkWork(true)
}
</script>

<style scoped>
.modguidance-text {
  white-space: pre-wrap;
}
</style>
