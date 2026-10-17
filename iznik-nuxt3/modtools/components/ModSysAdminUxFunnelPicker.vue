<template>
  <div class="ux-picker mb-3" role="group" aria-label="Funnel">
    <!-- Links, so a funnel can be copied or opened in a new tab. A plain click is handled here. -->
    <a
      v-for="f in flows"
      :key="f.flow"
      :href="hrefFor({ section: 'funnels', flow: f.flow })"
      role="button"
      class="ux-pick"
      :class="{ current: f.flow === current }"
      :aria-pressed="f.flow === current ? 'true' : 'false'"
      @click="(e) => go(e, f.flow)"
      @keydown.space.prevent="go(null, f.flow)"
    >
      <span class="name">{{ f.short }}</span>
      <span v-if="f.started !== null" class="count small text-muted">
        {{
          f.started ? `${f.started.toLocaleString()} started` : 'Nobody started'
        }}
      </span>
    </a>
  </div>
</template>

<script setup>
import { isPlainClick } from '~/modtools/composables/useUxFormat'

defineProps({
  // [{ flow, short, started }] in the order to draw them. `started` is null when it is not known.
  flows: { type: Array, required: true },
  current: { type: String, default: '' },
  // (patch) => the address that patch would give.
  hrefFor: { type: Function, required: true },
})
const emit = defineEmits(['navigate'])

function go(e, flow) {
  if (e) {
    if (!isPlainClick(e)) return
    e.preventDefault()
  }
  emit('navigate', { flow })
}
</script>

<style scoped lang="scss">
.ux-picker {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.ux-pick {
  display: flex;
  flex-direction: column;
  min-width: 7.5rem;
  padding: 0.35rem 0.75rem;
  border: 1px solid #ced4da;
  border-radius: 0.5rem;
  background: #fff;
  color: #212529;
  text-decoration: none;
  line-height: 1.25;

  &:hover {
    background: #f8f9fa;
  }

  &.current {
    border-color: #61ae24;
    box-shadow: 0 0 0 2px rgba(97, 174, 36, 0.35);
  }

  .name {
    font-weight: 600;
  }
}
</style>
