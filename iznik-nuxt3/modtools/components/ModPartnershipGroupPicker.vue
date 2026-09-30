<template>
  <div>
    <label v-if="label" class="small mb-0">{{ label }}</label>
    <b-form-input
      v-model="search"
      size="sm"
      placeholder="Type part of a community's name"
      @focus="load"
    />
    <div v-if="matches.length" class="mt-1">
      <b-button
        v-for="g in matches"
        :key="'pick-' + g.id"
        variant="white"
        size="sm"
        class="me-1 mb-1"
        @click="pick(g)"
      >
        + {{ g.namedisplay }}
      </b-button>
    </div>
    <p v-else-if="search.length >= 2 && loaded" class="small text-muted mt-1">
      No community matches that.
    </p>
  </div>
</template>
<script setup>
import { ref, computed } from 'vue'
import { useModGroupStore } from '~/stores/modgroup'

// Picks any Freegle community, not just those near a council - a council sometimes
// sponsors a neighbouring community.
const props = defineProps({
  label: {
    type: String,
    required: false,
    default: null,
  },
  // Group ids already on the list, which are not offered again.
  exclude: {
    type: Array,
    required: false,
    default: () => [],
  },
})

const emit = defineEmits(['pick'])

const modGroupStore = useModGroupStore()
const search = ref('')
const loaded = ref(false)

const MAX_MATCHES = 8

async function load() {
  if (!Object.keys(modGroupStore.allGroups).length) {
    await modGroupStore.listMT({ grouptype: 'Freegle' })
  }

  loaded.value = true
}

const matches = computed(() => {
  const term = search.value.trim().toLowerCase()

  if (term.length < 2) {
    return []
  }

  return Object.values(modGroupStore.allGroups)
    .filter(
      (g) =>
        g.id &&
        !props.exclude.includes(g.id) &&
        (g.namedisplay || g.nameshort || '').toLowerCase().includes(term)
    )
    .slice(0, MAX_MATCHES)
    .map((g) => ({ ...g, namedisplay: g.namedisplay || g.nameshort }))
})

function pick(g) {
  emit('pick', g)
  search.value = ''
}
</script>
