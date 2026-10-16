<template>
  <div class="ux-problem-detail">
    <p v-if="problem.description" class="description mb-2">
      {{ problem.description }}
    </p>
    <p class="mentioned mb-1">
      {{ mentionedIn(problem.mentions, problem.answers) }}
    </p>
    <p v-if="ranked" class="ranked mb-1">{{ ranked }}</p>
    <p v-else class="not-ranked small text-muted mb-1">
      Other members have not been asked to rank it yet.
    </p>

    <div v-if="suggestions.length" class="suggestions mt-2 mb-2">
      <p class="mb-1">Members' suggestions for it:</p>
      <ul class="mb-0 ps-3">
        <li v-for="s in suggestions" :key="s.id" class="suggestion">
          {{ s.title }} ({{ s.mentions }})
        </li>
      </ul>
    </div>

    <!-- Members' own words behind a toggle, escaped: they were checked against the answers, but they
         are still member text. -->
    <ModSysAdminUxSaid
      v-if="quotes.length"
      :comments="quotes"
      :limit="5"
      :label="`What members wrote (${quotes.length})`"
    />

    <p class="about mt-2 mb-1">
      About:
      <a
        v-if="problem.flow"
        class="about-link"
        :href="hrefFor({ section: 'funnels', flow: problem.flow })"
        @click="openFunnel"
        >{{ flowTitle(problem.flow) }}</a
      >
      <span v-else class="not-tied">Not tied to one funnel</span>
    </p>

    <!-- Other parts of the tab add pieces here without this component knowing them. -->
    <component
      :is="piece"
      v-for="(piece, i) in hosted"
      :key="i"
      :problem="problem"
    />
  </div>
</template>

<script setup>
// One problem opened: what it is, how many members named it, how other members ranked it, the fixes
// members suggested for it, and the words they wrote. Every figure carries its base, so "14" is always
// "14 of the 120 written answers". A problem is not a plan: nothing here says what to build.
import { computed, inject } from 'vue'
import { hostedFor } from '~/modtools/composables/useUxSections'
import { flowTitle } from '~/modtools/composables/useUxFlows'
import { isPlainClick } from '~/modtools/composables/useUxFormat'
import { mentionedIn, rankedLine } from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'

const props = defineProps({
  // One problem of the problems route.
  problem: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// The tab gives every section a way to get the address of a change, for links that can be copied.
const hrefFor = inject('uxHrefFor', () => '#')

// Other parts of the tab add pieces here, each given the problem.
const hosted = hostedFor('problem')

const ranked = computed(() => rankedLine(props.problem))
const suggestions = computed(() => props.problem.suggestions || [])

// The quotes the batch kept, as the words only. Each was checked to be words from a real answer.
const quotes = computed(() =>
  (props.problem.quotes || [])
    .map((q) => (typeof q === 'string' ? q : q?.span))
    .filter((q) => typeof q === 'string' && q.trim() !== '')
)

// A plain click opens the funnel without a page load. A click with a modifier is the browser's.
function openFunnel(e) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('navigate', { section: 'funnels', flow: props.problem.flow })
}
</script>

<style scoped lang="scss">
.ux-problem-detail {
  margin: 0.25rem 0 0.5rem 1.25rem;
  padding: 0.5rem 0.75rem;
  border-left: 3px solid #dee2e6;
  font-size: 0.9rem;
  overflow-wrap: anywhere;
}
</style>
