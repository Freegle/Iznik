<template>
  <div v-if="ideas.length" class="ux-problem-ideas">
    <p class="heading fw-bold mb-1">Ideas for this problem:</p>
    <ul class="list-unstyled mb-0">
      <li v-for="idea in ideas" :key="idea.id" class="idea mb-1">
        <span class="title">{{ idea.title }}</span>
        <span class="status badge ms-1 bg-light text-dark border">{{
          statusWord(idea.status)
        }}</span>
        <span class="score ms-1 text-muted"
          >Score {{ scoreText(idea.score) }}</span
        >
        <span v-if="idea.experiment" class="experiment ms-1">
          Experiment: {{ idea.experiment.title }},
          {{ experimentWord(idea.experiment) }}.
        </span>
      </li>
    </ul>
  </div>
</template>

<script setup>
// Drawn inside the detail of a member problem, which hosts it without knowing it: the ideas the idea
// generator proposed for that problem, with the experiment each became. It draws nothing at all, not
// even its heading, when there are none, while they load, or when the route is not there yet: a
// problem's detail has plenty to say without a note about ideas.
import { computed, onMounted, watch } from 'vue'
import { useUxIdeasStore } from '~/modtools/stores/uxIdeas'
import {
  experimentWord,
  scoreText,
  statusWord,
} from '~/modtools/composables/useUxIdeaLabels'

const props = defineProps({
  // The problem opened: { id, title }.
  problem: { type: Object, required: true },
})

const store = useUxIdeasStore()

const ideas = computed(() => {
  const slot = store.forProblem[props.problem.id]
  return slot && !slot.missing && !slot.error ? slot.data?.ideas || [] : []
})

onMounted(() => {
  store.loadForProblem(props.problem.id)
})
// The same component may be shown for another problem.
watch(
  () => props.problem.id,
  (next) => {
    store.loadForProblem(next)
  }
)
</script>

<style scoped lang="scss">
.ux-problem-ideas {
  margin-top: 0.75rem;
  overflow-wrap: anywhere;
}

.badge {
  font-size: 0.75rem;
  white-space: normal;
}
</style>
