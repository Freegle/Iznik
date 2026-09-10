<template>
  <div class="nearby-screen" data-testid="nearby-screen">
    <ShellHeader
      :title="term ? 'Search' : 'Nearby'"
      :subtitle="subtitle"
      avatar="/icon.png"
      back="/"
    />
    <div class="nearby-tools">
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
          :aria-selected="filter === f.value"
          :data-testid="'nearby-filter-' + f.value"
          @click="setFilter(f.value)"
        >
          {{ f.label }}
        </button>
      </div>
    </div>

    <div v-if="!at" class="nearby-where">
      <p class="nearby-note">
        To see what's near you, say roughly where you are. Only the area is ever
        shown.
      </p>
      <PostcodeInput @selected="onPostcode" />
    </div>

    <div v-else ref="scroller" class="nearby-scroll" data-testid="nearby-list">
      <PostCard
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
        More
      </button>
    </div>
  </div>
</template>
<script setup>
// What is nearby, as a screen of its own: a search box, a filter, and as many cards
// as they want to scroll. The chat shows three and sends people here for the rest.
import { computed, ref, watch, onMounted, useRouter } from '#imports'
import ShellHeader from '~/components/chatshell/ShellHeader.vue'
import PostCard from '~/components/chatshell/PostCard.vue'
import PostcodeInput from '~/components/chatshell/PostcodeInput.vue'
import { useMessageStore } from '~/stores/message'
import { useAssistantStore } from '~/stores/assistant'
import { useHostActions } from '~/composables/useHostActions'
import { milesAway } from '~/composables/useDistance'

const props = defineProps({
  term: { type: String, required: false, default: '' },
})

const PAGE = 10
const BOX = 0.15 // degrees, roughly ten miles

const messageStore = useMessageStore()
const assistant = useAssistantStore()
const host = useHostActions()
const router = useRouter()

const q = ref(props.term)
const filter = ref('all')
const filters = [
  { value: 'all', label: 'All' },
  { value: 'Offer', label: 'Offers' },
  { value: 'Wanted', label: 'Wanted' },
]
const all = ref([]) // lean rows from the feed, nearest first
const ids = ref([]) // ids whose full records are loaded, in order
const loading = ref(false)
const expandedId = ref(null)

const at = computed(() => host.myLatLng())
const subtitle = computed(() => {
  if (!at.value) return 'Tell me where you are'
  return props.term ? `Matches for "${props.term}"` : 'Nearest first'
})
const filtered = computed(() =>
  filter.value === 'all'
    ? all.value
    : all.value.filter((m) => m.type === filter.value)
)
const shown = computed(() =>
  ids.value.filter((id) => filtered.value.some((m) => m.id === id))
)
const more = computed(() => ids.value.length < all.value.length)

function milesFor(id) {
  const m = messageStore.byId(id)
  if (!at.value || !m?.lat) return null
  return milesAway(at.value.lat, at.value.lng, m.lat, m.lng)
}

async function load() {
  if (!at.value) return
  loading.value = true
  ids.value = []
  try {
    let list
    if (props.term) {
      list =
        (await messageStore.search({
          search: props.term,
          swlat: at.value.lat - 0.3,
          swlng: at.value.lng - 0.3,
          nelat: at.value.lat + 0.3,
          nelng: at.value.lng + 0.3,
        })) || []
    } else {
      list =
        (await messageStore.fetchInBounds(
          at.value.lat - BOX,
          at.value.lng - BOX,
          at.value.lat + BOX,
          at.value.lng + BOX,
          null,
          200,
          true
        )) || []
    }
    all.value = [...list].sort(
      (a, b) =>
        (milesAway(at.value.lat, at.value.lng, a.lat, a.lng) || 0) -
        (milesAway(at.value.lat, at.value.lng, b.lat, b.lng) || 0)
    )
  } catch (e) {
    all.value = []
  }
  await loadMore()
  loading.value = false
}

async function loadMore() {
  const next = all.value.slice(ids.value.length, ids.value.length + PAGE)
  await Promise.all(next.map((m) => messageStore.fetch(m.id).catch(() => null)))
  ids.value = [...ids.value, ...next.map((m) => m.id)]
}

function setFilter(value) {
  filter.value = value
}

function go() {
  const term = q.value.trim()
  router.push(term ? '/browse/' + encodeURIComponent(term) : '/browse')
}

async function onPostcode(pc) {
  if (!pc?.lat || !pc?.lng) return
  // Remembered on this device so the chat and this screen agree where "nearby" is.
  assistant.visitorLocation = { lat: pc.lat, lng: pc.lng, name: pc.name || '' }
  await load()
}

function reply(id) {
  host.replyTo(id)
}

function expand(id) {
  expandedId.value = expandedId.value === id ? null : id
}

watch(() => props.term, load)
onMounted(load)
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';

.nearby-screen {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: #efeae2;
}

.nearby-tools {
  background: #fff;
  padding: 0.5rem 0.75rem;
  border-bottom: 1px solid #e6e6e6;
}

.nearby-input {
  width: 100%;
  border: 1px solid #d9d9d9;
  border-radius: 999px;
  padding: 0.45rem 0.9rem;
  font-size: 1rem;
  background: #f4f4f4;
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
  padding: 1rem 0.75rem;
}

.nearby-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 0.75rem;
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
}

.nearby-note {
  color: #4a4a4a;
  font-size: 0.95rem;
  margin: 0.5rem 0.25rem;
}

.nearby-more {
  align-self: center;
  border: 1px solid #1f5f3b;
  background: #fff;
  color: #1f5f3b;
  border-radius: 999px;
  padding: 0.4rem 1.4rem;
  margin: 0.5rem 0 1rem;
}
</style>
