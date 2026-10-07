<template>
  <div data-testid="lockdown-what-happens">
    <ul>
      <li v-for="area in LOCKDOWN_AREAS" :key="area.key">
        <strong>{{ area.label }}:</strong> {{ area.description }}
      </li>
    </ul>
    <p>
      Nothing lifts on its own. Someone with Support tools lifts each area here,
      and every hour it is on delays genuine members. geeks@ is emailed now and
      every hour until it is closed.
    </p>
  </div>
</template>

<script setup>
import { LOCKDOWN_AREAS } from '~/modtools/utils/lockdownAreas'

// plans/active/2026-09-27-lockdown-switch.md section 11.11: what pressing
// does, in the same words on the page and in the confirm dialog.
</script>
