<template>
  <nav class="ux-nav mb-3" aria-label="UX sections">
    <ul class="list-unstyled d-flex mb-0">
      <li v-for="s in sections" :key="s.key">
        <!-- A real link, so copy link and open in a new tab work. A plain click is handled here. -->
        <a
          :href="hrefFor({ section: s.key })"
          class="ux-nav-link"
          :class="{ active: s.key === current }"
          :aria-current="s.key === current ? 'page' : undefined"
          @click="(e) => click(e, s.key)"
        >
          {{ s.label }}
          <component :is="s.badge" v-if="s.badge" />
        </a>
      </li>
    </ul>
  </nav>
</template>

<script setup>
import { isPlainClick } from '~/modtools/composables/useUxFormat'

defineProps({
  // [{ key, label, badge? }] in the order to draw them.
  sections: { type: Array, required: true },
  current: { type: String, default: '' },
  // (patch) => the address that patch would give.
  hrefFor: { type: Function, required: true },
})
const emit = defineEmits(['go'])

function click(e, key) {
  if (!isPlainClick(e)) return
  e.preventDefault()
  emit('go', key)
}
</script>

<style scoped lang="scss">
// A row of links with a line under the open one. Not pills: the SysAdmin page already uses green
// pills for the two levels above this one.
.ux-nav {
  overflow-x: auto;
  border-bottom: 1px solid #dee2e6;
}

.ux-nav ul {
  gap: 1.25rem;
  min-width: max-content;
}

.ux-nav-link {
  display: block;
  padding: 0.4rem 0.1rem;
  color: #495057;
  font-weight: 600;
  text-decoration: none;
  border-bottom: 3px solid transparent;
  white-space: nowrap;

  &:hover {
    color: #212529;
    border-bottom-color: #ced4da;
  }

  &.active {
    color: #212529;
    border-bottom-color: #61ae24;
  }
}
</style>
