<template>
  <section v-if="word" class="ux-exp-channel mb-2">
    <h6 class="ux-exp-h">{{ word }} experiment</h6>
    <p class="funnel mb-1">Funnel: {{ funnel }}.</p>
    <ul v-if="copy.length" class="copy list-unstyled mb-1">
      <li v-for="line in copy" :key="line">{{ line }}</li>
    </ul>
    <p v-if="serving" class="serving mb-1">{{ serving }}</p>
    <template v-if="exposure">
      <p v-if="exposure.exposed" class="exposed mb-0">
        {{ exposure.exposed }}
      </p>
      <p v-if="exposure.counted" class="counted mb-0">
        {{ exposure.counted }}
      </p>
      <p class="gloss text-muted mt-1 mb-0">{{ gloss }}</p>
    </template>
  </section>
</template>

<script setup>
// What an experiment decided on the server (mail, push, server code) adds to its details: the text each
// version shows, how long members are reached and when it is decided, and how many have been exposed and
// counted. It draws nothing for an experiment that changes the site, which has none of this. The words
// are worked out in useUxExperimentRead; this only draws them.
import { computed } from 'vue'
import { flowTitle } from '~/modtools/composables/useUxFlows'
import {
  channelWord,
  copyLines,
  exposureLines,
  servingLine,
  windowWord,
} from '~/modtools/composables/useUxExperimentRead'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

const word = computed(() => channelWord(props.experiment))
const funnel = computed(() => flowTitle(props.experiment.flow))
const copy = computed(() => copyLines(props.experiment))
const serving = computed(() => servingLine(props.experiment))
const exposure = computed(() => exposureLines(props.experiment))

// Why there are two numbers. A member is counted only once they have had the whole window to act. The words fit
// mail, push and server code alike, so they say "reached" and not "sent".
const gloss = computed(() => {
  const span = windowWord(props.experiment.design?.window_hours)
  return `Exposed: members it has reached so far. Counted: members it first reached ${span ? `at least ${span} ago` : 'long enough ago'}, so they have had time to act.`
})
</script>

<style scoped lang="scss">
// A sub-heading in the style of the headings of the details.
.ux-exp-h {
  margin-bottom: 0.15rem;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: none;
  color: #495057;
}

// The text of a version is shown as it is sent, so a long line breaks where it must on a phone.
.copy li {
  overflow-wrap: anywhere;
}
</style>
