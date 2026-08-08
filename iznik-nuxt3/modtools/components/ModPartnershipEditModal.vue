<template>
  <div>
    <b-modal ref="modal" size="lg" no-stacking @hidden="onHide">
      <template #title>
        {{ partnership ? 'Edit partnership' : 'New partnership' }}
      </template>
      <template #default>
        <NoticeMessage v-if="error" variant="danger" class="mb-2">
          {{ error }}
        </NoticeMessage>

        <b-form-group v-if="!partnership" label="Council">
          <b-input-group>
            <b-form-input
              v-model="authoritySearch"
              placeholder="Search for a council, e.g. Northshire"
              @keyup.enter="searchAuthorities"
            />
            <SpinButton
              variant="secondary"
              icon-name="search"
              label="Search"
              @handle="searchAuthorities"
            />
          </b-input-group>
          <div v-if="authorityResults.length" class="mt-1">
            <b-button
              v-for="a in authorityResults"
              :key="'authority-' + a.id"
              :variant="form.authorityid === a.id ? 'primary' : 'white'"
              class="me-1 mb-1"
              @click="pickAuthority(a)"
            >
              {{ a.name }}
              <span v-if="a.area_code" class="small text-muted">
                ({{ a.area_code }})
              </span>
            </b-button>
          </div>
          <p v-else-if="searched" class="text-muted small mt-1">
            No councils matched that.
          </p>
        </b-form-group>

        <b-form-group
          label="Name shown to members"
          description="Defaults to the council's own name."
        >
          <b-form-input
            v-model="form.name"
            placeholder="e.g. Northshire Council"
          />
        </b-form-group>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group label="Starts">
              <b-form-input v-model="form.startdate" type="date" />
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group label="Ends">
              <b-form-input v-model="form.enddate" type="date" />
            </b-form-group>
          </b-col>
        </b-row>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group
              label="Value of the whole deal (£)"
              description="For a multi-year deal, the total across all the years."
            >
              <b-form-input v-model="form.amount" type="number" min="0" />
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group label="Agreed on">
              <b-form-input v-model="form.agreeddate" type="date" />
            </b-form-group>
          </b-col>
        </b-row>

        <OurToggle
          :value="form.agreed"
          class="mb-2"
          :height="30"
          :width="250"
          :font-size="14"
          :sync="true"
          :labels="{ checked: 'Agreed', unchecked: 'Not agreed yet' }"
          variant="modgreen"
          @change="form.agreed = !form.agreed"
        />

        <OurToggle
          :value="form.visible"
          class="mb-3"
          :height="30"
          :width="250"
          :font-size="14"
          :sync="true"
          :labels="{
            checked: 'Show to members',
            unchecked: 'Hide from members',
          }"
          variant="modgreen"
          @change="form.visible = !form.visible"
        />

        <NoticeMessage v-if="!form.agreed" variant="info" class="mb-3">
          Until the deal is marked as agreed, members won't see the council on
          any of the communities it covers.
        </NoticeMessage>

        <b-form-group
          label="Tagline"
          description="The short line members see beside the council's name."
        >
          <b-form-input
            v-model="form.tagline"
            placeholder="e.g. Reuse and recycling in your area"
          />
        </b-form-group>

        <b-form-group label="Description">
          <b-form-textarea v-model="form.description" rows="3" />
        </b-form-group>

        <b-form-group label="Link">
          <b-form-input
            v-model="form.linkurl"
            placeholder="https://www.example.gov.uk/recycling"
          />
        </b-form-group>

        <b-form-group label="Logo image URL">
          <b-form-input v-model="form.imageurl" />
        </b-form-group>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group label="Council contact name">
              <b-form-input v-model="form.contactname" />
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group label="Council contact email">
              <b-form-input v-model="form.contactemail" type="email" />
            </b-form-group>
          </b-col>
        </b-row>

        <b-form-group label="Notes">
          <b-form-textarea v-model="form.notes" rows="3" />
        </b-form-group>
      </template>
      <template #footer>
        <b-button variant="white" @click="hide">Cancel</b-button>
        <SpinButton
          variant="primary"
          icon-name="save"
          label="Save"
          spinclass="text-white"
          @handle="save"
        />
      </template>
    </b-modal>
  </div>
</template>
<script setup>
import { ref } from 'vue'
import { useOurModal } from '~/composables/useOurModal'
import { usePartnershipsStore } from '~/stores/partnerships'
import api from '~/api'

const props = defineProps({
  partnership: {
    type: Object,
    required: false,
    default: null,
  },
})

const emit = defineEmits(['hidden', 'saved'])

const { modal, hide } = useOurModal()
const partnershipsStore = usePartnershipsStore()
const runtimeConfig = useRuntimeConfig()

const error = ref(null)
const authoritySearch = ref('')
const authorityResults = ref([])
const searched = ref(false)

const form = ref({
  authorityid: props.partnership?.authorityid ?? null,
  name: props.partnership?.name ?? '',
  startdate: props.partnership?.startdate ?? '',
  enddate: props.partnership?.enddate ?? '',
  amount: props.partnership?.amount ?? 0,
  agreed: props.partnership?.agreed ?? false,
  agreeddate: props.partnership?.agreeddate ?? '',
  visible: props.partnership?.visible ?? true,
  tagline: props.partnership?.tagline ?? '',
  description: props.partnership?.description ?? '',
  linkurl: props.partnership?.linkurl ?? '',
  imageurl: props.partnership?.imageurl ?? '',
  contactname: props.partnership?.contactname ?? '',
  contactemail: props.partnership?.contactemail ?? '',
  notes: props.partnership?.notes ?? '',
})

async function searchAuthorities(callback) {
  searched.value = true

  if (authoritySearch.value) {
    authorityResults.value = await api(runtimeConfig).authority.search(
      authoritySearch.value
    )
  }

  if (callback) {
    callback()
  }
}

function pickAuthority(a) {
  form.value.authorityid = a.id

  // A new deal is normally branded with the council's own name.
  if (!form.value.name) {
    form.value.name = a.name
  }
}

function onHide() {
  emit('hidden')
}

async function save(callback) {
  error.value = null

  if (!props.partnership && !form.value.authorityid) {
    error.value = 'Please choose a council.'
    callback?.()
    return
  }

  if (!form.value.startdate || !form.value.enddate) {
    error.value = 'Please give both a start and an end date.'
    callback?.()
    return
  }

  if (form.value.enddate < form.value.startdate) {
    error.value = "The end date can't be before the start date."
    callback?.()
    return
  }

  const params = { ...form.value, amount: parseFloat(form.value.amount) || 0 }

  try {
    if (props.partnership) {
      await partnershipsStore.edit(props.partnership.id, params)
    } else {
      await partnershipsStore.add(params)
    }

    emit('saved')
    hide()
  } catch (e) {
    error.value = e.message || 'Could not save that.'
  }

  callback?.()
}
</script>
