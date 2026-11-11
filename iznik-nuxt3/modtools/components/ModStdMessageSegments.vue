<template>
  <div ref="root" class="segmented-editor border rounded p-3">
    <!-- Red rather than muted: this way of editing is unfamiliar and
         moderators were reading straight past a grey instruction. -->
    <p class="text-danger small mb-2">
      Fill in the highlighted boxes and choose Keep or Remove for any optional
      parts. You can edit any of the rest of the wording too.
      {{ description }}
    </p>
    <div class="segmented-body">
      <template v-for="(seg, i) in segments" :key="'seg' + i">
        <!-- A text segment which is nothing but the line break(s) between two
             blocks: shown as a paragraph-spacing toggle rather than an empty
             box, so the spacing is visible and fixable. -->
        <div
          v-if="seg.type === 'text' && isGap(seg)"
          :data-seg="i"
          class="seg-gap"
        >
          <b-button
            size="sm"
            variant="link"
            class="p-0 seg-gap-toggle"
            @click="toggleGap(i)"
          >
            <v-icon :icon="isParagraphGap(seg) ? 'minus' : 'plus'" />
            {{
              isParagraphGap(seg)
                ? 'Blank line between these paragraphs (click to remove it)'
                : 'No blank line between these paragraphs (click to add one)'
            }}
          </b-button>
        </div>
        <AutoHeightTextarea
          v-else-if="seg.type === 'text'"
          v-model="seg.content"
          :data-seg="i"
          class="seg-text"
          rows="3"
          max-rows="30"
        />
        <AutoHeightTextarea
          v-else-if="seg.type === 'editthis'"
          v-model="seg.value"
          :data-seg="i"
          :class="[
            'seg-editthis',
            { 'seg-todo': !segEdited(seg), 'seg-blocked': isBlocked(i) },
          ]"
          rows="3"
          max-rows="12"
          :placeholder="seg.content"
        />
        <span v-else-if="seg.type === 'optional'" class="seg-optional">
          <!-- Undecided: the wording is editable while you decide, and you
               must pick one before the message can go. -->
          <span
            v-if="seg.removed === undefined"
            :data-seg="i"
            :class="['seg-optional-undecided', { 'seg-blocked': isBlocked(i) }]"
          >
            <AutoHeightTextarea
              v-model="seg.content"
              class="seg-text seg-optional-text"
              rows="2"
              max-rows="20"
            />
            <span class="seg-optional-buttons">
              <b-button
                size="sm"
                variant="outline-success"
                @click="decideOptional(i, false)"
              >
                Keep
              </b-button>
              <b-button
                size="sm"
                variant="outline-danger"
                class="ms-1"
                @click="decideOptional(i, true)"
              >
                Remove
              </b-button>
            </span>
          </span>
          <!-- Kept: still editable, and you can change your mind. -->
          <span v-else-if="seg.removed === false" class="seg-optional-kept">
            <AutoHeightTextarea
              v-model="seg.content"
              :data-seg="i"
              class="seg-text"
              rows="2"
              max-rows="20"
            />
            <b-button
              size="sm"
              variant="link"
              class="p-0 seg-optional-change"
              @click="decideOptional(i, true)"
            >
              remove this bit after all
            </b-button>
          </span>
          <span v-else :data-seg="i" class="seg-optional-removed">
            <s class="text-muted">{{ seg.content }}</s>
            <b-button
              size="sm"
              variant="link"
              class="p-0 ms-1"
              @click="decideOptional(i, false)"
            >
              undo
            </b-button>
          </span>
        </span>
      </template>
    </div>
  </div>
</template>
<script setup>
import { ref, nextTick } from 'vue'

// A standard message with <editthis>/<optional> directives, laid out in flow: the
// prose, an inline box per <editthis>, and a keep/remove control per <optional>.
// The segments come from parseSegments() in stdMessageDirectives.js and are edited
// in place; the modal gates the send and reassembles them.
const segments = defineModel({ type: Array, required: true })

const props = defineProps({
  // Indices of the segments holding a send up. Set by the modal when a send is
  // refused, and kept in step as they are dealt with.
  blocked: {
    type: Array,
    required: false,
    default: () => [],
  },
  // Says what this wording becomes, after the instructions.
  description: {
    type: String,
    required: false,
    default: 'This is the message the member will receive.',
  },
})

const root = ref(null)

// An <editthis> box counts as done once its text differs from the placeholder.
function segEdited(seg) {
  const v = (seg.value ?? '').trim()
  return !!v && v !== (seg.content ?? '').trim()
}

function decideOptional(index, removed) {
  const seg = segments.value[index]
  if (seg && seg.type === 'optional') seg.removed = removed
}

// A text segment which is nothing but the line break(s) separating two blocks.
// Rendered as an empty box it looked like a field you'd forgotten to fill in,
// and gave no clue that it held the paragraph spacing - so a template written
// with single line breaks produced a message with its paragraphs run together
// and no obvious way to fix it. Shown as a toggle instead.
// Needs a line break in it: a separator of only spaces is genuinely inline
// wording, not paragraph spacing, and turning it into a paragraph control would
// be wrong.
function isGap(seg) {
  return (
    seg.type === 'text' &&
    !!seg.content &&
    /^\s*$/.test(seg.content) &&
    seg.content.includes('\n')
  )
}

function isParagraphGap(seg) {
  return (seg.content?.match(/\n/g) || []).length > 1
}

function toggleGap(index) {
  const seg = segments.value[index]
  if (seg && isGap(seg)) seg.content = isParagraphGap(seg) ? '\n' : '\n\n'
}

function isBlocked(index) {
  return props.blocked.includes(index)
}

// Bring a segment into view, so a refused send points at the thing to do.
async function scrollToSegment(index) {
  await nextTick()
  root.value
    ?.querySelector?.(`[data-seg="${index}"]`)
    ?.scrollIntoView?.({ block: 'center' })
}

defineExpose({ scrollToSegment })
</script>

<style scoped>
/* Read the message in flow, fixed prose plus inline boxes. Plain CSS (no SCSS
   vars) so a missing variable can't break page load. */
.segmented-editor {
  background-color: #fff;
}
.segmented-body {
  white-space: pre-wrap;
  line-height: 1.7;
  font-size: 1rem;
}
/* Fixed prose is editable too (mods often tweak the wording), so render it as an
   auto-sizing textarea rather than a static span. Styled to read as part of the
   message — a light border that strengthens on hover/focus signals it's editable,
   while staying visually quieter than the amber "must fill in" editthis boxes. */
.seg-text {
  display: block;
  width: 100%;
  margin: 4px 0;
  padding: 4px 8px;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  background-color: #fff;
  font: inherit;
  line-height: 1.4;
}
.seg-text:hover {
  border-color: #adb5bd;
}
.seg-text:focus,
.seg-text:focus-visible {
  outline: none;
  border-color: #0d6efd;
  box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}
/* editthis boxes sit on their own full-width line so each editable bit reads as
   a clear fillable field. A placeholder box is highlighted (amber) so outstanding
   ones stand out, fading to plain once filled — so with several of them the
   differences "disappear" as you go. */
.seg-editthis {
  display: block;
  width: 100%;
  margin: 4px 0;
  padding: 4px 8px;
  /* A clear, consistent box outline so each editable bit reads as a fillable
     field rather than plain prose. Subtle once filled (so it blends back into
     the message), prominent while still a placeholder. */
  border: 1px solid #adb5bd;
  border-radius: 6px;
  box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.06);
  font: inherit;
  line-height: 1.4;
}
.seg-editthis:hover {
  border-color: #6c757d;
}
.seg-editthis:focus,
.seg-editthis:focus-visible {
  outline: none;
  border-color: #0d6efd;
  box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}
.seg-editthis.seg-todo {
  background-color: #fff3cd; /* amber: still needs personalising */
  /* dashed amber outline = "fill me in"; becomes a plain solid box once edited */
  border: 1px dashed #e0a800;
}
/* An optional section: its wording is editable while you decide (and after you
   keep it), because mods want to reword these as well as take them or leave
   them. Removing is reversible in both directions - Keep used to be final. */
.seg-optional-undecided {
  display: block;
  background-color: #e2e3e5;
  border-radius: 4px;
  padding: 4px;
  margin: 4px 0;
}
.seg-optional-text {
  font-style: italic;
}
.seg-optional-kept {
  display: block;
}
.seg-optional-change,
.seg-gap-toggle {
  font-size: 0.8125rem;
}
.seg-optional-removed {
  white-space: normal;
}
/* Paragraph spacing between two blocks, shown as a toggle rather than an empty
   textarea nobody could interpret. */
.seg-gap {
  margin: 2px 0;
}
/* What's stopping the send. Set only when a send is refused, cleared as each one
   is dealt with. Wins over the focus styling above, or clicking into the box
   would hide the very thing we're pointing at. */
.seg-blocked,
.seg-blocked:focus,
.seg-blocked:focus-visible {
  border: 1px solid #dc3545;
  box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.4);
}
</style>
