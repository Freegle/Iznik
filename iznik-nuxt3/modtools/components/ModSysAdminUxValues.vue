<template>
  <div class="ux-values">
    <p v-if="store.values.missing" class="not-switched-on text-muted">
      {{ NOT_SWITCHED_ON }}
    </p>
    <NoticeMessage v-else-if="store.values.error" variant="danger" class="mb-3">
      {{ store.values.error }}
    </NoticeMessage>
    <div v-else-if="!store.values.data" class="loading text-center py-3">
      <b-spinner small class="me-2" />
      Loading...
    </div>
    <p v-else-if="!values.length" class="none text-muted">
      No answers about what works yet. They appear after members answer the
      question about what has worked well and the answers have been analysed.
    </p>

    <template v-else>
      <p class="lead-in small text-muted mb-2">
        Each count is out of the written answers to the question it came from.
      </p>
      <ul class="values list-unstyled mb-0">
        <li v-for="v in values" :key="v.id" class="value mb-3">
          <strong class="title">{{ v.title }}</strong>
          <span class="count text-muted">: {{ countText(v) }}</span>
          <!-- Members' own words behind a toggle, escaped. They were checked against the answers, but
               they are still member text. -->
          <ModSysAdminUxSaid
            v-if="quotesOf(v).length"
            :comments="quotesOf(v)"
            :limit="5"
            :label="`What members wrote (${quotesOf(v).length})`"
          />
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup>
// What members said works well for them, the most mentioned first. Every count is out of the written
// answers to the question it came from, and differs between questions.
import { computed } from 'vue'
import { useUxSurveysStore } from '~/modtools/stores/uxSurveys'
import { NOT_SWITCHED_ON } from '~/modtools/composables/useUxSlot'
import { mentionCount } from '~/modtools/composables/useUxAnswers'
import ModSysAdminUxSaid from '~/modtools/components/ModSysAdminUxSaid.vue'

const store = useUxSurveysStore()

const values = computed(() => store.values.data?.values || [])

// "30 of 100 (30%)" from 100 answers, "14 of 45" below. A value that did not come from a question
// has no base to be out of, and is a count of members.
const countText = (v) => mentionCount(v.mentions, v.answers)

// The quotes the batch kept: each was checked to be words from a real answer.
const quotesOf = (v) =>
  (v.quotes || [])
    .map((q) => (typeof q === 'string' ? q : q?.span))
    .filter((q) => typeof q === 'string' && q.trim() !== '')
</script>
