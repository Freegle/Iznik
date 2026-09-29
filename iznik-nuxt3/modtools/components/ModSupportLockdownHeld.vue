<template>
  <div data-testid="lockdown-held">
    <p class="text-muted">
      Everything the lockdown is still holding, newest first. To deal with a
      sender, open them in Support tools and mark them as a spammer there: their
      held items are then dropped when their area is lifted.
    </p>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <b-form-select
        v-model="kind"
        :options="kindOptions"
        class="w-auto"
        data-testid="lockdown-held-kind"
        @update:model-value="search"
      />
      <b-form-input
        v-model="q"
        class="w-auto flex-grow-1"
        placeholder="Search the text, or the sender's name or email"
        data-testid="lockdown-held-search"
        @keyup.enter="search"
      />
      <b-button
        variant="primary"
        data-testid="lockdown-held-search-button"
        :disabled="loading"
        @click="search"
      >
        Search
      </b-button>
    </div>

    <p
      v-if="!loading && !items.length"
      class="text-muted"
      data-testid="lockdown-held-empty"
    >
      Nothing held{{ q.trim() ? ' matches that search' : '' }}.
    </p>

    <table v-else class="table table-sm">
      <thead>
        <tr>
          <th>When</th>
          <th>From</th>
          <th v-if="kind === 'chat'">To</th>
          <th>Text</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="item in items"
          :key="item.id"
          data-testid="lockdown-held-row"
        >
          <td class="text-nowrap">{{ timeago(item.created, true) }}</td>
          <td>
            <nuxt-link
              :to="'/support/' + item.userid"
              :data-testid="'lockdown-held-sender-' + item.userid"
            >
              {{ item.name || '#' + item.userid }}
            </nuxt-link>
            <div class="small text-muted">{{ item.email }}</div>
          </td>
          <td v-if="kind === 'chat'">
            <nuxt-link
              v-if="item.recipientid"
              :to="'/support/' + item.recipientid"
            >
              {{ item.recipientname || '#' + item.recipientid }}
            </nuxt-link>
          </td>
          <td class="held-text">{{ item.text }}</td>
        </tr>
      </tbody>
    </table>

    <b-button
      v-if="next"
      variant="secondary"
      :disabled="loading"
      data-testid="lockdown-held-more"
      @click="more"
    >
      Show more
    </b-button>
    <b-spinner v-if="loading" small class="ms-2" />
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { useLockdownStore } from '~/stores/lockdown'
import { timeago } from '~/composables/useTimeFormat'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: browse and search
// what is held, one kind at a time, 50 a page. No actions here - dealing with
// a sender happens in Support tools, through the existing member search.
const store = useLockdownStore()

const kindOptions = [
  { value: 'chat', text: 'Chat messages' },
  { value: 'post', text: 'Posts' },
  { value: 'chitchat', text: 'ChitChat posts' },
]

const kind = ref('chat')
const q = ref('')
const items = ref([])
const next = ref(null)
const loading = ref(false)

// Changing the kind or searching again while a fetch is in flight starts
// another; only the latest one's answer is shown, so a slow earlier answer
// can't put posts under the chat heading.
let latest = 0

async function load(before) {
  const mine = ++latest
  loading.value = true
  try {
    const params = { kind: kind.value }
    const term = q.value.trim()
    if (term) params.q = term
    if (before) params.before = before
    const page = await store.fetchHeld(params)
    if (mine !== latest) return
    items.value = before ? [...items.value, ...page.items] : page.items
    next.value = page.next
  } finally {
    if (mine === latest) loading.value = false
  }
}

function search() {
  return load(null)
}

function more() {
  return load(next.value)
}

onMounted(search)

defineExpose({ search, more })
</script>

<style scoped>
.held-text {
  white-space: pre-wrap;
  word-break: break-word;
}
</style>
