<template>
  <ShellSheet
    testid="nearby-sheet"
    :title="term ? 'Search' : 'Nearby'"
    height="85%"
    @close="$emit('close')"
  >
    <template #subtitle>{{ subtitle }}</template>
    <template #tools>
      <form class="nearby-search" @submit.prevent="go">
        <label class="visually-hidden" for="nearby-search">Search nearby</label>
        <input
          id="nearby-search"
          v-model="q"
          type="search"
          class="nearby-input"
          placeholder="Search what's nearby"
          data-testid="nearby-search"
          autocomplete="off"
        />
      </form>
      <div class="nearby-filters" role="tablist" aria-label="Show">
        <button
          v-for="f in filters"
          :key="f.value"
          type="button"
          class="filter-btn"
          :class="{ active: filter === f.value }"
          role="tab"
          :aria-selected="filter === f.value ? 'true' : 'false'"
          :data-testid="'nearby-filter-' + f.value"
          @click="filter = f.value"
        >
          {{ f.label }}
        </button>
      </div>
    </template>

    <div v-if="!at && !term" class="nearby-where">
      <p class="nearby-note">
        To see what's near you, say roughly where you are. Only the area is ever
        shown.
      </p>
      <PostcodeInput @selected="onPostcode" />
    </div>
    <div v-else class="nearby-list" data-testid="nearby-list">
      <PostRow
        v-for="id in shown"
        :id="id"
        :key="'nearby-' + id"
        :miles="milesFor(id)"
        :expanded="expandedId === id"
        @reply="reply"
        @expand="expand"
      />
      <p v-if="loading" class="nearby-note">Looking around you.</p>
      <p
        v-else-if="!shown.length"
        class="nearby-note"
        data-testid="nearby-empty"
      >
        {{
          term
            ? 'Nothing nearby matches that just now. An Ask lets people who have one find you.'
            : 'Nothing on offer near you just now. Check back soon, or Ask for what you need.'
        }}
      </p>
      <button
        v-else-if="more"
        type="button"
        class="nearby-more"
        data-testid="nearby-more"
        @click="loadMore"
      >
        Show more
      </button>
    </div>
  </ShellSheet>
</template>
<script setup>
// What is nearby, as a sheet over the chat: a search box, a filter, and one line per
// item that scrolls inside the sheet, ten more at a time. The chat underneath never
// grows. Tapping a line opens it in place; Reply closes the sheet and carries on in
// the chat.
import { computed, ref, watch, onMounted } from '#imports'
import ShellSheet from '~/components/chatshell/ShellSheet.vue'
import PostRow from '~/components/chatshell/PostRow.vue'
import PostcodeInput from '~/components/chatshell/PostcodeInput.vue'
import { useMessageStore } from '~/stores/message'
import { useAssistantStore } from '~/stores/assistant'
import { useHostActions } from '~/composables/useHostActions'

const props = defineProps({
  term: { type: String, required: false, default: '' },
  filter: { type: String, required: false, default: 'all' },
})
const emit = defineEmits(['close'])

const PAGE = 10
const FRESH = 5 * 60 * 1000

const messageStore = useMessageStore()
const assistant = useAssistantStore()
const host = useHostActions()

const q = ref(props.term)
// The term the rows on show are for; q is what is being typed.
const term = ref(props.term)
const filter = ref(
  ['offers', 'wanted'].includes(props.filter) ? props.filter : 'all'
)
const filters = [
  { value: 'all', label: 'All' },
  { value: 'offers', label: 'Offers' },
  { value: 'wanted', label: 'Wanted' },
]
const ids = ref([]) // ids whose full records are loaded, in order
const loading = ref(false)
const expandedId = ref(null)

const at = computed(() => host.myLatLng())
const rows = computed(() => assistant.nearby?.rows || [])
const filtered = computed(() => host.filterRows(rows.value, filter.value))
const shown = computed(() =>
  ids.value.filter((id) => filtered.value.some((m) => m.id === id))
)
const more = computed(() =>
  filtered.value.some((m) => !ids.value.includes(m.id))
)
const subtitle = computed(() => {
  if (!at.value && !term.value) return 'Tell me where you are'
  if (term.value) return `Matches for "${term.value}"`
  return 'Nearest first'
})

function milesFor(id) {
  return rows.value.find((r) => r.id === id)?.miles ?? null
}

// The rows the chat fetched a moment ago, for the same place and term, serve again.
function fresh() {
  const n = assistant.nearby
  if (!n) return false
  if ((n.term || '') !== (term.value || '')) return false
  const a = at.value
  if (!!a !== !!n.at) return false
  if (a && (a.lat !== n.at.lat || a.lng !== n.at.lng)) return false
  return Date.now() - (n.fetched || 0) < FRESH
}

async function load() {
  loading.value = true
  ids.value = []
  expandedId.value = null
  try {
    if (!fresh()) await host.fetchNearby({ term: term.value, at: at.value })
    await loadMore()
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  const next = filtered.value
    .filter((m) => !ids.value.includes(m.id))
    .slice(0, PAGE)
  await Promise.all(next.map((m) => messageStore.fetch(m.id).catch(() => null)))
  ids.value = [...ids.value, ...next.map((m) => m.id)]
}

function go() {
  term.value = q.value.trim()
  load()
}

async function onPostcode(pc) {
  if (!pc?.lat || !pc?.lng) return
  // Remembered on this device so the chat and the sheet agree where "nearby" is.
  assistant.visitorLocation = { lat: pc.lat, lng: pc.lng, name: pc.name || '' }
  await load()
}

function reply(id) {
  emit('close')
  host.replyTo(id)
}

function expand(id) {
  expandedId.value = expandedId.value === id ? null : id
}

// A narrower filter may leave fewer than a page on show: fill it from the rows.
watch(filter, () => {
  if (more.value && shown.value.length < PAGE) loadMore()
})
onMounted(() => {
  if (at.value || term.value) load()
})
</script>
<style scoped lang="scss">
.nearby-input {
  width: 100%;
  border: 1px solid #d9d9d9;
  border-radius: 999px;
  padding: 0.45rem 0.9rem;
  font-size: 1rem;
  background: #fff;
}

.nearby-filters {
  display: flex;
  gap: 0.4rem;
  margin-top: 0.5rem;
}

.filter-btn {
  border: 1px solid #cfd8d3;
  background: #fff;
  color: #1f5f3b;
  border-radius: 999px;
  padding: 0.25rem 0.8rem;
  font-size: 0.9rem;

  &.active {
    background: #e3f1e8;
    border-color: #1f5f3b;
    font-weight: 600;
  }
}

.nearby-where {
  padding: 0.5rem 0.9rem 1rem;
}

.nearby-list {
  display: flex;
  flex-direction: column;
}

.nearby-note {
  color: #4a4a4a;
  font-size: 0.95rem;
  margin: 0.6rem 0.9rem;
}

.nearby-more {
  align-self: center;
  border: 1px solid #1f5f3b;
  background: #fff;
  color: #1f5f3b;
  border-radius: 999px;
  padding: 0.4rem 1.4rem;
  margin: 0.6rem 0 1rem;
}
</style>
