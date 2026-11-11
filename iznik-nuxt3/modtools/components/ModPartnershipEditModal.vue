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

        <b-form-group v-if="!partnership" class="mb-3" label="Council">
          <div
            v-if="chosenAuthority"
            class="d-flex align-items-center gap-2 chosen-council"
          >
            <strong>{{ chosenAuthority.name }}</strong>
            <span v-if="chosenAuthority.area_code" class="text-muted">
              {{ chosenAuthority.area_code }}
            </span>
            <b-button variant="link" size="sm" @click="changeAuthority">
              Change
            </b-button>
          </div>
          <template v-else>
            <b-input-group>
              <b-form-input
                v-model="authoritySearch"
                placeholder="Search for a council, e.g. Essex"
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
                variant="white"
                class="me-1 mb-1 text-start"
                @click="pickAuthority(a)"
              >
                {{ a.name }}
                <span v-if="a.area_code" class="d-block small text-muted">
                  {{ a.area_code }}
                </span>
              </b-button>
            </div>
            <p v-else-if="searched" class="text-muted small mt-1">
              No councils matched that.
            </p>
          </template>
        </b-form-group>

        <div v-if="!partnership && chosenAuthority" class="mb-3">
          <div class="form-label">Communities this deal covers</div>
          <p v-if="loadingBoundary" class="text-muted small">
            Working out which communities overlap the council boundary...
          </p>
          <template v-else>
            <p class="text-muted small mb-1">
              Every community that overlaps the council boundary significantly
              is covered, including any set up later. A community that only
              touches the edge is not listed. The percentage is how much of the
              community lies inside the boundary, and the statistics count that
              share of it. Untick one to leave it out.
            </p>
            <NoticeMessage v-if="!boundaryGroups.length" variant="warning">
              No communities overlap this council's boundary significantly.
            </NoticeMessage>
            <b-form-checkbox
              v-for="g in boundaryGroups"
              :key="'bg-' + g.id"
              :model-value="!excluded.includes(g.id)"
              @update:model-value="toggleExcluded(g.id, $event)"
            >
              {{ g.namedisplay }}
              <span class="small text-muted">{{ percent(g.overlap) }}</span>
            </b-form-checkbox>
            <div v-for="g in included" :key="'ig-' + g.id">
              {{ g.namedisplay }}
              <span class="small text-muted">outside the boundary</span>
              <b-button
                variant="link"
                size="sm"
                class="text-danger p-0 ms-1"
                @click="removeIncluded(g.id)"
              >
                Remove
              </b-button>
            </div>
            <ModPartnershipGroupPicker
              class="mt-2"
              :exclude="pickerExclude"
              label="Add a community outside the boundary"
              @pick="addIncluded"
            />
          </template>
        </div>

        <b-form-group
          class="mb-3"
          label="Name shown to members"
          description="Defaults to the council's own name."
        >
          <b-form-input
            v-model="form.name"
            placeholder="e.g. Essex County Council"
          />
        </b-form-group>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group class="mb-3" label="Starts">
              <b-form-input v-model="form.startdate" type="date" />
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group class="mb-3" label="Ends">
              <b-form-input v-model="form.enddate" type="date" />
            </b-form-group>
          </b-col>
        </b-row>
        <p v-if="length" class="deal-length mb-3">
          This deal lasts {{ length }}.
        </p>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group
              class="mb-3"
              label="Value of the whole deal (£)"
              description="What they pay across all the years, after any discount."
            >
              <b-form-input v-model="form.amount" type="number" min="0" />
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group
              class="mb-3"
              label="Price before bulk discount (£)"
              description="Only if we gave a discount - so we keep track of what we told them."
            >
              <b-form-input v-model="form.fullprice" type="number" min="0" />
            </b-form-group>
          </b-col>
        </b-row>
        <p v-if="discount" class="small mb-3">
          Bulk discount: <strong>{{ discount }}</strong>
        </p>

        <b-row>
          <b-col cols="12" md="6">
            <b-form-group class="mb-3" label="Where is it up to?">
              <b-form-select
                v-model="form.status"
                :options="statusOptions"
                class="status-select"
              />
              <p class="small text-muted mt-1 mb-0">{{ statusHelp }}</p>
            </b-form-group>
          </b-col>
          <b-col cols="12" md="6">
            <b-form-group class="mb-3" label="Will they renew?">
              <b-form-select v-model="form.renewal" :options="renewalOptions" />
            </b-form-group>
          </b-col>
        </b-row>

        <OurToggle
          v-model="form.visible"
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
        />

        <NoticeMessage
          v-if="!committed || !form.visible"
          variant="info"
          class="mb-4"
        >
          {{
            !form.visible
              ? "Hidden: members won't see the council on any of the communities it covers."
              : "Members will see the council once the deal is confirmed. Until then it isn't shown."
          }}
        </NoticeMessage>

        <b-form-group
          class="mb-3"
          label="Tagline"
          description="The short line members see beside the council's name."
        >
          <b-form-input
            v-model="form.tagline"
            placeholder="e.g. Reuse and recycling in your area"
          />
        </b-form-group>

        <b-form-group class="mb-3" label="Description" description="Optional.">
          <b-form-textarea v-model="form.description" rows="3" />
        </b-form-group>

        <b-form-group class="mb-3" label="Link">
          <b-form-input
            v-model="form.linkurl"
            placeholder="https://www.example.gov.uk/recycling"
          />
        </b-form-group>

        <b-form-group class="mb-3" label="Logo">
          <div class="d-flex align-items-center gap-3">
            <OurUploadedImage
              v-if="logoUid"
              :src="logoUid"
              alt="Council logo"
              :height="60"
              class-name="logo-preview"
            />
            <img
              v-else-if="logoPreview"
              :src="logoPreview"
              alt="Council logo"
              class="logo-preview"
            />
            <span v-else class="text-muted small">No logo yet.</span>
            <b-button
              v-if="!uploadingLogo"
              variant="secondary"
              size="sm"
              @click="uploadingLogo = true"
            >
              <v-icon icon="camera" />
              {{ hasLogo ? 'Change logo' : 'Upload logo' }}
            </b-button>
            <b-button
              v-if="hasLogo"
              variant="link"
              size="sm"
              class="text-danger"
              @click="removeLogo"
            >
              Remove
            </b-button>
          </div>
          <OurUploader
            v-if="uploadingLogo"
            v-model="logoAtts"
            type="Group"
            class="mt-2"
          />
        </b-form-group>

        <div class="form-label">Council contacts</div>
        <p class="small text-muted mb-1">Everyone here gets the statistics.</p>
        <b-row
          v-for="(c, i) in contacts"
          :key="'contact-' + i"
          class="g-2 mb-2 align-items-center contact-row"
        >
          <b-col cols="12" md="4">
            <b-form-input v-model="c.name" placeholder="Name" />
          </b-col>
          <b-col cols="12" md="4">
            <b-form-input v-model="c.email" type="email" placeholder="Email" />
          </b-col>
          <b-col cols="8" md="3">
            <b-form-select v-model="c.role" :options="roleOptions" />
          </b-col>
          <b-col cols="4" md="1" class="text-end">
            <b-button
              variant="link"
              size="sm"
              class="text-danger p-0"
              title="Remove this contact"
              @click="contacts.splice(i, 1)"
            >
              <v-icon icon="trash-alt" />
            </b-button>
          </b-col>
        </b-row>
        <b-button variant="white" size="sm" class="mb-3" @click="addContact">
          <v-icon icon="plus" /> Add another contact
        </b-button>

        <b-form-group class="mb-3" label="Notes">
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
import { ref, computed, watch, onMounted } from 'vue'
import { useOurModal } from '~/composables/useOurModal'
import { usePartnershipsStore } from '~/stores/partnerships'
import {
  STATUSES,
  RENEWALS,
  CONTACT_ROLES,
  isCommitted,
  dealLength,
  bulkDiscount,
  statusInfo,
} from '~/modtools/composables/usePartnershipFormat'
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

// A partnership is done with a council, not a ward or a constituency.
const COUNCIL_TYPES = [
  'County Council',
  'District Council',
  'Unitary Authority',
  'Metropolitan District',
  'London Borough',
  'Greater London Authority',
  'Waste Authority',
]

const error = ref(null)
const authoritySearch = ref('')
const authorityResults = ref([])
const searched = ref(false)
const chosenAuthority = ref(null)

const loadingBoundary = ref(false)
const boundaryGroups = ref([])
const excluded = ref([])
const included = ref([])

const uploadingLogo = ref(false)
const logoAtts = ref([])
// A newly uploaded logo is shown from its upload id; one already saved from its URL.
const logoImageId = ref(null)
const logoUid = ref(null)
const logoPreview = ref(props.partnership?.imageurl || null)
const hasLogo = computed(() => Boolean(logoUid.value || logoPreview.value))

const form = ref({
  authorityid: props.partnership?.authorityid ?? null,
  name: props.partnership?.name ?? '',
  startdate: props.partnership?.startdate ?? '',
  enddate: props.partnership?.enddate ?? '',
  amount: props.partnership?.amount ?? 0,
  fullprice: props.partnership?.fullprice ?? null,
  status: props.partnership?.status ?? 'Quoted',
  renewal: props.partnership?.renewal ?? null,
  visible: props.partnership?.visible ?? true,
  tagline: props.partnership?.tagline ?? '',
  description: props.partnership?.description ?? '',
  linkurl: props.partnership?.linkurl ?? '',
  imageurl: props.partnership?.imageurl ?? '',
  notes: props.partnership?.notes ?? '',
})

const contacts = ref([{ name: '', email: '', role: 'Waste' }])

const statusOptions = STATUSES.map((s) => ({ value: s.value, text: s.text }))
const renewalOptions = RENEWALS.map((r) => ({ value: r.value, text: r.text }))
const roleOptions = CONTACT_ROLES

const statusHelp = computed(() => statusInfo(form.value.status).help)
const committed = computed(() => isCommitted(form.value.status))
const length = computed(() =>
  dealLength(form.value.startdate, form.value.enddate)
)
const discount = computed(() =>
  bulkDiscount(form.value.fullprice, form.value.amount)
)

// The picker should not offer a community that is already on the list.
const pickerExclude = computed(() => [
  ...boundaryGroups.value.map((g) => g.id),
  ...included.value.map((g) => g.id),
])

onMounted(async () => {
  // The list row carries no contacts; the detail does.
  if (props.partnership) {
    const detail = await partnershipsStore.fetchOne(props.partnership.id)

    if (detail?.contacts?.length) {
      contacts.value = detail.contacts.map((c) => ({
        name: c.name || '',
        email: c.email || '',
        role: c.role,
      }))
    }
  }
})

watch(
  logoAtts,
  (atts) => {
    if (atts?.length) {
      const att = atts[atts.length - 1]
      logoImageId.value = att.id
      logoUid.value = att.ouruid
      uploadingLogo.value = false
    }
  },
  { deep: true }
)

function removeLogo() {
  logoImageId.value = null
  logoUid.value = null
  logoPreview.value = null
  form.value.imageurl = ''
}

function percent(overlap) {
  return Math.round((overlap || 0) * 100) + '%'
}

async function searchAuthorities(callback) {
  searched.value = true

  if (authoritySearch.value) {
    const results = await api(runtimeConfig).authority.search(
      authoritySearch.value
    )
    authorityResults.value = (results || []).filter((a) =>
      COUNCIL_TYPES.includes(a.area_code)
    )
  }

  if (callback) {
    callback()
  }
}

async function pickAuthority(a) {
  chosenAuthority.value = a
  form.value.authorityid = a.id
  excluded.value = []

  // A new deal is normally branded with the council's own name.
  if (!form.value.name) {
    form.value.name = a.name
  }

  loadingBoundary.value = true
  try {
    const authority = await api(runtimeConfig).authority.fetch(a.id)
    // The authority stats page counts every community that touches the council; a deal only
    // lists the ones that overlap it significantly (the API says which).
    boundaryGroups.value = (authority?.groups || [])
      .filter((g) => g.significant !== false)
      .sort((x, y) => (y.overlap || 0) - (x.overlap || 0))
  } finally {
    loadingBoundary.value = false
  }
}

function changeAuthority() {
  chosenAuthority.value = null
  form.value.authorityid = null
  boundaryGroups.value = []
  excluded.value = []
}

function toggleExcluded(id, covered) {
  excluded.value = covered
    ? excluded.value.filter((e) => e !== id)
    : [...excluded.value, id]
}

function addIncluded(group) {
  included.value.push(group)
}

function removeIncluded(id) {
  included.value = included.value.filter((g) => g.id !== id)
}

function addContact() {
  contacts.value.push({ name: '', email: '', role: 'Other' })
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

  const params = {
    ...form.value,
    amount: parseFloat(form.value.amount) || 0,
    fullprice: parseFloat(form.value.fullprice) || 0,
    renewal: form.value.renewal || '',
    contacts: contacts.value.filter((c) => c.name || c.email),
  }

  if (logoImageId.value) {
    params.imageid = logoImageId.value
  }

  if (!props.partnership) {
    params.excludegroupids = excluded.value
    params.includegroupids = included.value.map((g) => g.id)
  }

  try {
    let id

    if (props.partnership) {
      id = props.partnership.id
      await partnershipsStore.edit(id, params)
    } else {
      id = await partnershipsStore.add(params)
    }

    emit('saved', id)
    hide()
  } catch (e) {
    error.value = e.message || 'Could not save that.'
  }

  callback?.()
}
</script>
<style scoped lang="scss">
.chosen-council {
  padding: 0.5rem 0.75rem;
  border: 1px solid $color-gray--light;
  border-radius: 0.25rem;
}

.deal-length {
  font-weight: bold;
}

.logo-preview {
  max-height: 60px;
  max-width: 160px;
}
</style>
