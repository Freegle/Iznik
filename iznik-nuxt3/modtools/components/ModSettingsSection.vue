<template>
  <b-card no-body class="mb-2">
    <b-card-header>
      <b-button block href="#" variant="secondary" @click.prevent="toggle">
        {{ title }}
      </b-button>
    </b-card-header>
    <b-collapse :id="id" v-model="open" role="tabpanel">
      <slot name="prebody" />
      <b-card-body>
        <slot />
      </b-card-body>
    </b-collapse>
  </b-card>
</template>

<script setup>
import { computed, inject } from 'vue'

const props = defineProps({
  id: {
    type: String,
    required: true,
  },
  title: {
    type: String,
    required: true,
  },
})

// The parent owns which section is open, so that only one is expanded at a
// time and so settings search can open a section directly.
const openSection = inject('settingsOpenSection')

const open = computed({
  get: () => openSection.value === props.id,
  set: (value) => {
    openSection.value = value ? props.id : null
  },
})

function toggle() {
  open.value = !open.value
}
</script>
