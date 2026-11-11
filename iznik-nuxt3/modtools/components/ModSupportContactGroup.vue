<template>
  <div>
    <SpinButton
      v-if="!gotallgroups"
      variant="primary"
      icon-name="refresh"
      label="Fetch communities"
      spinclass="text-white"
      @handle="loadallgroups"
    />
    <div v-if="gotallgroups">
      <label :for="`${formId}-from`">From:</label>
      <b-form-select :id="`${formId}-from`" v-model="from">
        <option value="null">-- Please choose --</option>
        <option value="info">info@...</option>
        <option value="support">support@...</option>
        <option value="councils">partnerships@...</option>
        <option value="mentors">mentors@...</option>
        <option value="newgroups">newgroups@...</option>
        <option value="geeks">geeks@...</option>
        <option value="board">board@...</option>
        <option value="chair">chair@...</option>
        <option value="ro">returningofficer@...</option>
        <option value="volunteers">volunteers@...</option>
        <option value="centralmods">volunteersupport@...</option>
      </b-form-select>
      <div class="field-caption">To:</div>
      <ModGroupSelect v-model="groupid" systemwide listall />
      <NoticeMessage v-if="groupid < 0" variant="danger" class="mt-2 mb-2">
        This will go to all groups.
      </NoticeMessage>
      <label :for="`${formId}-try-hard`"> Try hard? </label>
      <b-form-select :id="`${formId}-try-hard`" v-model="tryhard">
        <option :value="false">Just mail primary email</option>
        <option :value="true">Mail all email addresses we know</option>
      </b-form-select>
      <label :for="`${formId}-confirm-receipt`"> Confirm receipt </label>
      <b-form-select :id="`${formId}-confirm-receipt`" v-model="confirm">
        <option :value="false">Don't ask to click</option>
        <option :value="true">Ask them to click to confirm receipt</option>
      </b-form-select>
      <label :for="`${formId}-subject`">Subject</label>
      <b-form-input
        :id="`${formId}-subject`"
        v-model="subject"
        placeholder="Brief subject of this message"
      />
      <label :for="`${formId}-text-version`">Text version</label>
      <b-form-textarea :id="`${formId}-text-version`" v-model="text" rows="6" />
      <div class="field-caption">HTML version (optional)</div>
      <client-only>
        <div class="bg-white">
          <QuillEditor
            v-model:content="html"
            :modules="quillModules"
            theme="snow"
            :toolbar="toolbarOptions"
            content-type="html"
            class="bg-white"
          />
        </div>
      </client-only>
      <div v-if="groupid > 0" class="mt-3 single-group-extras">
        <label :for="`${formId}-quoted`">
          Member's original email (optional)
        </label>
        <div class="small text-muted">
          Paste the whole email. The volunteers get it quoted in full under your
          message.
        </div>
        <b-form-textarea
          :id="`${formId}-quoted`"
          v-model="quoted"
          rows="6"
          placeholder="Paste the member's email here"
        />
        <label :for="`${formId}-files`" class="mt-2">
          Attachments (optional)
        </label>
        <div class="small text-muted">
          Up to 5 pictures or PDFs, 2.5MB in total. They are sent with the
          email.
        </div>
        <input
          :id="`${formId}-files`"
          type="file"
          multiple
          accept="image/png,image/jpeg,image/gif,image/webp,application/pdf"
          class="form-control"
          @change="chooseFiles"
        />
        <NoticeMessage v-if="fileError" variant="danger" class="mt-2">
          {{ fileError }}
        </NoticeMessage>
        <div class="form-check mt-3">
          <input
            :id="`${formId}-require-reply`"
            v-model="requireReply"
            type="checkbox"
            class="form-check-input"
          />
          <label
            :for="`${formId}-require-reply`"
            class="form-check-label fw-normal"
          >
            Make the volunteers reply
          </label>
          <div class="small text-muted">
            Each moderator of this community will see this in ModTools, on every
            page, until they reply to you. They can sleep it once for 30
            minutes. It stops after 14 days, or when you stop it in the history
            below.
          </div>
        </div>
      </div>
      <NoticeMessage v-if="groupid < 0" variant="danger" class="mt-2 mb-2">
        This will go to all groups.
      </NoticeMessage>
      <SpinButton
        v-if="groupid < 0"
        variant="danger"
        label="Send to all groups"
        icon-name="envelope"
        spinclass="text-white"
        :disabled="!valid"
        class="mt-2 mb-2"
        size="lg"
        @handle="send"
      />
      <SpinButton
        v-else
        variant="primary"
        label="Send"
        icon-name="envelope"
        spinclass="text-white"
        :disabled="!valid"
        class="mt-2 mb-2"
        size="lg"
        @handle="send"
      />
      <div v-if="alerts && alerts.length">
        <b-row class="fw-bold">
          <b-col cols="6" lg="2"> Created </b-col>
          <b-col cols="6" lg="2"> Complete </b-col>
          <b-col cols="6" lg="2"> To </b-col>
          <b-col cols="6" lg="4"> Subject </b-col>
          <b-col cols="6" lg="2" />
        </b-row>
        <ModAlertHistory
          v-for="alert in alerts"
          :key="'alert-' + alert.id"
          :alertid="alert.id"
        />
      </div>
      <Spinner v-else-if="busy" :size="50" class="d-block" />
      <b-button
        v-else
        variant="white"
        size="lg"
        class="mt-2 mb-2 d-block"
        @click="fetch"
      >
        Show history
      </b-button>
    </div>
  </div>
</template>
<script setup>
import { ref, computed, useId } from 'vue'
import { QuillEditor } from '@vueup/vue-quill'
import '@vueup/vue-quill/dist/vue-quill.snow.css'
import htmlEditButton from 'quill-html-edit-button'

import ModAlertHistory from './ModAlertHistory'
import { useAlertStore } from '~/stores/alert'
import { useModGroupStore } from '~/stores/modgroup'

// Unique per instance so label/for pairs never clash when the component renders twice.
const formId = useId()

const alertStore = useAlertStore()
const modGroupStore = useModGroupStore()

const quillModules = {
  name: 'htmlEditButton',
  module: htmlEditButton,
  options: {},
}

const busy = ref(false)
const from = ref(null)
const groupid = ref(null)
const tryhard = ref(false)
const confirm = ref(false)
const subject = ref(null)
const text = ref(null)
const html = ref(null)
const quoted = ref('')
const requireReply = ref(false)
const files = ref([])
const fileError = ref(null)

// The API takes the files as base64 inside a 4MB request, so 2.5MB of files in all.
const MAX_FILES = 5
const MAX_BYTES = 2500000
const FILE_TYPES = [
  'image/png',
  'image/jpeg',
  'image/gif',
  'image/webp',
  'application/pdf',
]

const toolbarOptions = [
  [{ header: [1, 2, 3, 4, 5, 6, false] }],
  ['bold', 'italic', 'underline', 'strike'],
  ['blockquote', 'code-block'],
  [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],
  [{ indent: '-1' }, { indent: '+1' }],
  [{ color: [] }, { background: [] }],
  ['link', 'image', 'video'],
  ['clean'],
]

const gotallgroups = computed(() => {
  return Object.values(modGroupStore.allGroups).length > 0
})

const valid = computed(() => {
  return from.value && subject.value && text.value && groupid.value
})

const alerts = computed(() => {
  const alertList = Object.values(alertStore.list)
  alertList.sort(function (a, b) {
    return new Date(b.created).getTime() - new Date(a.created).getTime()
  })
  return alertList
})

async function loadallgroups(callback) {
  await modGroupStore.listMT({ grouptype: 'Freegle' })
  callback()
}

function readAsBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result).split(',')[1] || '')
    reader.onerror = reject
    reader.readAsDataURL(file)
  })
}

async function chooseFiles(e) {
  fileError.value = null
  files.value = []
  const chosen = Array.from(e.target.files || [])

  if (chosen.length > MAX_FILES) {
    fileError.value = 'Please choose at most 5 files.'
    return
  }

  if (chosen.some((f) => !FILE_TYPES.includes(f.type))) {
    fileError.value = 'Only pictures and PDFs can be attached.'
    return
  }

  if (chosen.reduce((total, f) => total + f.size, 0) > MAX_BYTES) {
    fileError.value = 'Those files are too big. 2.5MB in total, please.'
    return
  }

  files.value = await Promise.all(
    chosen.map(async (f) => ({
      name: f.name,
      mime: f.type,
      data: await readAsBase64(f),
    }))
  )
}

async function send(callback) {
  const data = {
    from: from.value,
    subject: subject.value,
    text: text.value,
    html: html.value,
    askclick: confirm.value ? 1 : 0,
    tryhard: tryhard.value ? 1 : 0,
  }

  if (groupid.value > 0) {
    data.groupid = groupid.value

    if (quoted.value && quoted.value.trim()) {
      data.quoted = quoted.value
    }

    if (files.value.length) {
      data.attachments = files.value
    }

    if (requireReply.value) {
      data.requirereply = 1
    }
  } else {
    data.groupid = 'AllFreegle'
  }

  await alertStore.add(data)
  quoted.value = ''
  requireReply.value = false
  files.value = []
  callback()
}

async function fetch() {
  busy.value = true

  await alertStore.fetch()

  busy.value = false
}
</script>
<style scoped>
label,
.field-caption {
  font-weight: bold;
}

select {
  max-width: 400px;
  display: block;
}
</style>
