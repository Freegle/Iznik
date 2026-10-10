<template>
  <div>
    <b-modal id="stdmsgmodal" ref="modal" :title="title" size="lg" no-stacking>
      <template #default>
        <label :for="`${formId}-title`">Title</label>
        <b-form-input :id="`${formId}-title`" v-model="stdmsg.title" />
        <label :for="`${formId}-action`">Action</label>
        <b-form-select
          :id="`${formId}-action`"
          v-model="stdmsg.action"
          :options="options"
        />
        <label :for="`${formId}-edit-text`">Edit Text</label>
        <b-form-select
          v-if="stdmsg.action === 'Edit'"
          :id="`${formId}-edit-text`"
          v-model="stdmsg.edittext"
        >
          <option value="Unchanged">Unchanged</option>
          <option value="Correct Case">Correct Case</option>
        </b-form-select>
        <label :for="`${formId}-autosend`">Autosend?</label>
        <b-form-select :id="`${formId}-autosend`" v-model="stdmsg.autosend">
          <option :value="0">Edit before send</option>
          <option :value="1">Send Immediately</option>
        </b-form-select>
        <label :for="`${formId}-how-often-do-you-use-thi`"
          >How often do you use this?</label
        >
        <b-form-select
          :id="`${formId}-how-often-do-you-use-thi`"
          v-model="stdmsg.rarelyused"
        >
          <option :value="0">Frequently</option>
          <option :value="1">Rarely</option>
        </b-form-select>
        <label :for="`${formId}-change-moderation-status`"
          >Change Moderation Status *</label
        >
        <b-form-select
          :id="`${formId}-change-moderation-status`"
          v-model="stdmsg.newmodstatus"
        >
          <option value="UNCHANGED">Unchanged</option>
          <option value="MODERATED">Moderated</option>
          <option value="DEFAULT">Group Settings</option>
          <option value="PROHIBITED">Can't Post</option>
          <option value="UNMODERATED">Unmoderated</option>
        </b-form-select>
        <label :for="`${formId}-change-delivery-settings`"
          >Change Delivery Settings *</label
        >
        <b-form-select
          :id="`${formId}-change-delivery-settings`"
          v-model="stdmsg.newdelstatus"
        >
          <option value="UNCHANGED">Unchanged</option>
          <option value="DIGEST">Daily Digest</option>
          <option value="NONE">Web Only</option>
          <option value="SINGLE">Individual Emails</option>
          <option value="ANNOUNCEMENT">Special Notices</option>
        </b-form-select>
        <label :for="`${formId}-subject-prefix`">Subject Prefix</label>
        <b-form-input
          :id="`${formId}-subject-prefix`"
          v-model="stdmsg.subjpref"
        />
        <label :for="`${formId}-subject-suffix`">Subject Suffix</label>
        <b-form-input
          :id="`${formId}-subject-suffix`"
          v-model="stdmsg.subjsuff"
        />
        <label :for="`${formId}-insert-text`">Insert Text</label>
        <b-form-select :id="`${formId}-insert-text`" v-model="stdmsg.insert">
          <option value="Top">Top</option>
          <option value="Bottom">Bottom</option>
        </b-form-select>
        <label :for="`${formId}-message-body`">Message Body</label>
        <b-form-textarea
          :id="`${formId}-message-body`"
          v-model="stdmsg.body"
          rows="10"
        />
      </template>
      <template #footer>
        <div class="d-flex justify-content-between flex-wrap w-100">
          <div>
            <b-button v-if="id && !locked" variant="danger" @click="deleteIt">
              Delete
            </b-button>
          </div>
          <div>
            <b-button variant="white" class="me-2" @click="hide">
              Cancel
            </b-button>
            <b-button
              v-if="!locked"
              variant="primary"
              :disabled="!stdmsg.title"
              @click="save"
            >
              <span v-if="id">Save</span>
              <span v-else>Add</span>
            </b-button>
          </div>
        </div>
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { reactive, computed, useId } from 'vue'
import { useModConfigStore } from '~/stores/modconfig'
import { useStdmsgStore } from '~/stores/stdmsg'
import { useOurModal } from '~/composables/useOurModal'
import { useMe } from '~/composables/useMe'

// Unique per instance so label/for pairs never clash when the component renders twice.
const formId = useId()

const props = defineProps({
  id: {
    type: Number,
    required: false,
    default: null,
  },
  types: {
    type: Array,
    required: false,
    default: null,
  },
})

defineEmits(['hide'])

const modConfigStore = useModConfigStore()
const stdmsgStore = useStdmsgStore()
const { modal, hide } = useOurModal()
const { myid } = useMe()

const newmsg = reactive([])

const allOptions = [
  { value: null, text: '-- Pending Messages -- ' },
  { value: 'Approve', text: 'Approve' },
  { value: 'Reject', text: 'Reject' },
  { value: 'Leave', text: 'Reply' },
  { value: 'Edit', text: 'Edit' },
  { value: 'Hold Message', text: 'Reply and Hold' },
  { value: null, text: '-- Approved Messages -- ' },
  { value: 'Delete Approved Message', text: 'Delete' },
  { value: 'Leave Approved Message', text: 'Reply' },
  { value: null, text: '-- Approved Members --' },
  { value: 'Delete Approved Member', text: 'Remove' },
  { value: 'Leave Approved Member', text: 'Reply' },
]

const options = computed(() => {
  if (props.types) {
    return allOptions.filter((o) => props.types.includes(o.value))
  }
  return allOptions
})

const config = computed(() => {
  return modConfigStore.current
})

const locked = computed(() => {
  // A protected config with a null createdby has no real lock owner, so it must
  // not read as locked - otherwise parseInt(null) is NaN, NaN !== myid is always
  // true, and every user (including the creator) loses the Save/Add and Delete
  // buttons. Guard createdby truthy first, matching ModSettingsModConfig.vue.
  return Boolean(
    config.value &&
    config.value.protected &&
    config.value.createdby &&
    parseInt(config.value.createdby) !== myid.value
  )
})

const stdmsg = computed(() => {
  if (!props.id) {
    // Creating.
    return newmsg
  } else {
    // Existing - find it in the config.
    return config.value
      ? config.value.stdmsgs.find((s) => {
          return s.id === props.id
        })
      : null
  }
})

const title = computed(() => {
  if (!props.id) {
    return 'Create a standard message'
  } else if (locked.value) {
    return 'View ' + stdmsg.value.title
  } else {
    return 'Edit ' + stdmsg.value.title
  }
})

async function show() {
  // Fetch the current value, if any, before opening the modal.
  if (props.id) {
    await stdmsgStore.fetch(props.id)
  }
  modal.value.show()
}

async function save() {
  if (!props.id) {
    await stdmsgStore.add({
      ...stdmsg.value,
      configid: config.value.id,
    })
  } else {
    await stdmsgStore.update({
      ...stdmsg.value,
    })
  }

  hide()
}

async function deleteIt() {
  await stdmsgStore.delete({
    id: stdmsg.value.id,
    configid: config.value.id,
  })

  hide()
}

defineExpose({ show })
</script>
<style scoped>
label {
  font-weight: bold;
  margin-top: 1rem;
}
</style>
