<template>
  <div class="ux-pages">
    <p class="text-muted">
      Figures that are not about one funnel. Open a heading to see them.
    </p>
    <ModSysAdminUxFriction
      :open="view.open === 'friction'"
      @toggle="toggle('friction')"
    />
    <ModSysAdminUxAdoption
      :open="view.open === 'adoption'"
      @toggle="toggle('adoption')"
    />
    <ModSysAdminUxRegret
      :open="view.open === 'regret'"
      @toggle="toggle('regret')"
    />
    <ModSysAdminUxSearch
      :open="view.open === 'search'"
      @toggle="toggle('search')"
    />
  </div>
</template>

<script setup>
import ModSysAdminUxFriction from '~/modtools/components/ModSysAdminUxFriction.vue'
import ModSysAdminUxAdoption from '~/modtools/components/ModSysAdminUxAdoption.vue'
import ModSysAdminUxRegret from '~/modtools/components/ModSysAdminUxRegret.vue'
import ModSysAdminUxSearch from '~/modtools/components/ModSysAdminUxSearch.vue'

const props = defineProps({
  // The view from useUxView.
  view: { type: Object, required: true },
})
const emit = defineEmits(['navigate'])

// One panel is open at a time. Opening the open one closes it.
function toggle(name) {
  emit('navigate', { open: props.view.open === name ? undefined : name })
}
</script>
