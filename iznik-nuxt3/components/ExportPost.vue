<template>
  <div>
    <b-row>
      <b-col cols="2"> #{{ post.id }} </b-col>
      <b-col cols="2">
        {{ dateonly(post.arrival) }}
      </b-col>
      <b-col cols="2">
        <span v-if="post.groups && post.groups.length">
          <span v-for="(g, idx) in post.groups" :key="g.groupid">
            {{ g.namedisplay }}<span v-if="idx < post.groups.length - 1">, </span>
          </span>
        </span>
      </b-col>
      <b-col cols="4">
        {{ post.subject }}
      </b-col>
      <b-col cols="2">
        <b-button variant="link" @click="showJSON = true"> Details </b-button>
      </b-col>
    </b-row>
    <vue-json-pretty v-if="showJSON" :data="post" class="bg-white" />
  </div>
</template>
<script setup>
import { ref } from 'vue'
import VueJsonPretty from 'vue-json-pretty'
import { dateonly } from '~/composables/useTimeFormat'

defineProps({
  post: {
    type: Object,
    required: true,
  },
})

const showJSON = ref(false)
</script>
