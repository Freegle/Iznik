<template>
  <div>
    <div v-if="busy" class="mt-2">
      <p>Loading communities...</p>
    </div>
    <div v-else-if="fetched && groups && groups.length" class="mt-2">
      <p>
        Here you can see info about all Freegle groups. Click on the column
        headings to sort. Use the filters to narrow the list.
      </p>
      <p>Groups with the ID in light blue are caretaker groups.</p>
      <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <b-form-input
          v-model="search"
          class="search-box"
          type="search"
          placeholder="Filter by ID, name or region"
        />
        <label
          v-for="f in flagFilters"
          :key="f.key"
          :for="'flag-filter-' + f.key"
          class="d-flex align-items-center gap-1 mb-0"
        >
          {{ f.label }}
          <b-form-select
            :id="'flag-filter-' + f.key"
            v-model="flags[f.key]"
            :options="flagOptions"
            size="sm"
            class="flag-filter"
          />
        </label>
        <span class="text-muted small">
          {{ filteredGroups.length }} of {{ groups.length }} groups
        </span>
      </div>
      <b-table
        :items="filteredGroups"
        :fields="fields"
        :per-page="perPage"
        :current-page="currentPage"
        :sort-by="[{ key: 'nameshort', order: 'asc' }]"
        sticky-header="70vh"
        striped
        small
        responsive
        class="bg-white support-groups-table"
      />
      <b-pagination
        v-if="filteredGroups.length > perPage"
        v-model="currentPage"
        :total-rows="filteredGroups.length"
        :per-page="perPage"
        class="mt-2"
      />
    </div>
  </div>
</template>
<script setup>
import { ref, computed, watch } from 'vue'
import dayjs from 'dayjs'
import { useModGroupStore } from '@/stores/modgroup'

const modGroupStore = useModGroupStore()

const busy = ref(false)
const fetched = ref(false)
const search = ref('')
const currentPage = ref(1)
// Around 450 communities: paging keeps the table responsive.
const perPage = 100

const flagFilters = [
  { key: 'publish', label: 'Publish?' },
  { key: 'onhere', label: 'FD?' },
  { key: 'ontn', label: 'TN?' },
  { key: 'onlovejunk', label: 'LJ?' },
  { key: 'mentored', label: 'Caretaker?' },
]
const flagOptions = [
  { value: '', text: 'Any' },
  { value: 'Y', text: 'Y' },
  { value: 'N', text: 'N' },
]
const flags = ref({
  publish: '',
  onhere: '',
  ontn: '',
  onlovejunk: '',
  mentored: '',
})

const groups = computed(() => {
  const ret = Object.values(modGroupStore.allGroups)
  ret.sort((a, b) => {
    return a.nameshort.toLowerCase().localeCompare(b.nameshort.toLowerCase())
  })
  return ret
})

function isYes(key, value) {
  return key === 'mentored' ? !!value : Number(value) === 1
}

const filteredGroups = computed(() => {
  const term = search.value.trim().toLowerCase()

  return groups.value.filter((g) => {
    if (term) {
      const hay = [g.id, g.nameshort, g.namedisplay, g.region]
        .map((v) => String(v ?? '').toLowerCase())
        .join(' ')

      if (!hay.includes(term)) {
        return false
      }
    }

    return flagFilters.every((f) => {
      const want = flags.value[f.key]
      return !want || isYes(f.key, g[f.key]) === (want === 'Y')
    })
  })
})

// A new filter can leave the current page past the end.
watch(
  [search, flags],
  () => {
    currentPage.value = 1
  },
  { deep: true }
)

function dateFormatter(value) {
  return value ? dayjs(value).format('YYYY-MM-DD') : '-'
}

function autoApprovesFormatter(value) {
  const auto = parseInt(value)
  return isNaN(auto) ? '-' : Math.abs(auto) + '%'
}

function moderatedPctFormatter(value) {
  return Math.round(parseFloat(value) || 0) + '%'
}

function latlngFormatter(value) {
  return Math.round(parseFloat(value) * 100) / 100
}

function boolFormatter(value) {
  return Number(value) === 1 ? 'Y' : 'N'
}

function caretakerFormatter(value) {
  return value ? 'Y' : 'N'
}

function idCellClass(_value, _key, item) {
  return item && item.mentored ? 'text-center bg-info-subtle' : 'text-center'
}

function autoApprovesCellClass(value, _key, item) {
  return item && item.publish && parseInt(value) >= 50
    ? 'text-center bg-warning'
    : 'text-center'
}

// Under 10% moderated may mean auto-approve fully covers the community, or that
// moderation is very low; 80% or more is heavily moderated.
function moderatedPctCellClass(value) {
  const pct = Math.round(parseFloat(value) || 0)

  if (pct < 10) {
    return 'text-center bg-success-subtle'
  } else if (pct >= 80) {
    return 'text-center bg-warning-subtle'
  }

  return 'text-center'
}

const centre = { class: 'text-center', thClass: 'text-center' }

const fields = [
  { key: 'id', label: 'ID', sortable: true, tdClass: idCellClass },
  { key: 'nameshort', label: 'Short Name', sortable: true, stickyColumn: true },
  { key: 'namedisplay', label: 'Display Name', sortable: true },
  {
    key: 'lastautoapprove',
    label: 'Last Auto-Approve',
    sortable: true,
    formatter: dateFormatter,
    ...centre,
  },
  {
    key: 'recentautoapprovespercent',
    label: 'Auto-Approve %',
    sortable: true,
    formatter: autoApprovesFormatter,
    tdClass: autoApprovesCellClass,
  },
  {
    key: 'recentautoapproves',
    label: 'Auto-Approves',
    sortable: true,
    ...centre,
  },
  {
    key: 'recentmoderated',
    label: 'Moderated (30d)',
    sortable: true,
    ...centre,
  },
  {
    key: 'recentmoderatedpercent',
    label: '% Moderated (30d)',
    sortable: true,
    formatter: moderatedPctFormatter,
    tdClass: moderatedPctCellClass,
  },
  {
    key: 'activeownercount',
    label: 'Active-owner',
    sortable: true,
    ...centre,
  },
  { key: 'activemodcount', label: 'Active Mods', sortable: true, ...centre },
  {
    key: 'lastmoderated',
    label: 'Last Moderated',
    sortable: true,
    formatter: dateFormatter,
    ...centre,
  },
  {
    key: 'publish',
    label: 'Publish?',
    sortable: true,
    formatter: boolFormatter,
    ...centre,
  },
  {
    key: 'onhere',
    label: 'FD?',
    sortable: true,
    formatter: boolFormatter,
    ...centre,
  },
  {
    key: 'ontn',
    label: 'TN?',
    sortable: true,
    formatter: boolFormatter,
    ...centre,
  },
  {
    key: 'onlovejunk',
    label: 'LJ?',
    sortable: true,
    formatter: boolFormatter,
    ...centre,
  },
  { key: 'region', label: 'Region', sortable: true },
  {
    key: 'lat',
    label: 'Lat',
    sortable: true,
    formatter: latlngFormatter,
    ...centre,
  },
  {
    key: 'lng',
    label: 'Lng',
    sortable: true,
    formatter: latlngFormatter,
    ...centre,
  },
  { key: 'founded', label: 'Founded', sortable: true },
  {
    key: 'affiliationconfirmed',
    label: 'Affiliation Confirmed',
    sortable: true,
    formatter: dateFormatter,
    ...centre,
  },
  {
    key: 'backupownersactive',
    label: 'Backup Owners Active',
    sortable: true,
    ...centre,
  },
  {
    key: 'backupmodsactive',
    label: 'Backup Mods Active',
    sortable: true,
    ...centre,
  },
  {
    key: 'mentored',
    label: 'Caretaker?',
    sortable: true,
    formatter: caretakerFormatter,
    ...centre,
  },
]

async function fetchCommunities(callback) {
  if (fetched.value) {
    if (callback) callback()
    return
  }

  busy.value = true
  await modGroupStore.listMT({
    grouptype: 'Freegle',
    support: true,
  })

  // This prevents us rendering partial data that happens to be in store.
  fetched.value = true
  busy.value = false
  if (callback) callback()
}

defineExpose({ fetchCommunities })
</script>
<style scoped>
.search-box {
  max-width: 300px;
}

.flag-filter {
  width: 5.5rem;
}

.support-groups-table :deep(th),
.support-groups-table :deep(td) {
  white-space: pre-line;
  max-width: 150px;
}
</style>
