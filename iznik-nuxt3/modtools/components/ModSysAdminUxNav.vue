<template>
  <nav ref="row" class="ux-nav mb-3" aria-label="UX sections">
    <ul ref="links" class="list-unstyled d-flex mb-0">
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
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { isPlainClick } from '~/modtools/composables/useUxFormat'

const props = defineProps({
  // [{ key, label, badge? }] in the order to draw them.
  sections: { type: Array, required: true },
  current: { type: String, default: '' },
  // (patch) => the address that patch would give.
  hrefFor: { type: Function, required: true },
})
const emit = defineEmits(['go'])

const row = ref(null)
const links = ref(null)

// On a phone the links are wider than the room, and the row scrolls sideways. The open section is
// brought into it by as much as it is cut off, at the right or at the left, so its name is always in
// view. Only the row moves: scrollIntoView could move the page as well.
function showOpen() {
  const box = row.value
  const link = box?.querySelector('a[aria-current="page"]')
  if (!box || !link) return
  const room = box.getBoundingClientRect()
  const at = link.getBoundingClientRect()
  // Rounded outwards: scrollLeft keeps whole pixels, and a row as wide as a fractional column would
  // otherwise leave the last pixel of the link cut off.
  if (at.left < room.left) box.scrollLeft += Math.floor(at.left - room.left)
  else if (at.right > room.right)
    box.scrollLeft += Math.ceil(at.right - room.right)
}

// A badge that loads after the row is drawn (the count of running experiments) widens the links
// and can push the open one back out of view, so the row is checked again whenever they change size.
let resized = null
onMounted(() => {
  showOpen()
  if (typeof ResizeObserver !== 'undefined' && links.value) {
    resized = new ResizeObserver(() => showOpen())
    // Each link, not the list: the list keeps the width of the row whatever its links need.
    for (const li of links.value.children) resized.observe(li)
  }
})
onBeforeUnmount(() => resized?.disconnect())
// After the links are drawn again, so that the one now open is the one found.
watch(
  () => props.current,
  () => nextTick(showOpen)
)

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
  /* A scroll container still offers its content's full width to the boxes around it, so a flex
     ancestor grew to fit the whole row and the tab ran off a narrow window. Contained, the row takes
     the width it is given and scrolls inside it. */
  contain: inline-size;
}

.ux-nav ul {
  gap: 1.25rem;
  min-width: max-content;
  /* The furthest a row can scroll is a whole number of pixels, and the row is as wide as its
     column, which can be a fraction. Room after the last link keeps it wholly in view at the end. */
  padding-inline-end: 0.25rem;
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
