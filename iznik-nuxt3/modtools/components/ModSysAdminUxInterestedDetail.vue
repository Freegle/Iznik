<template>
  <section v-if="mine" class="ux-interested mb-2" data-testid="ux-interested">
    <h6 class="ux-exp-h">I'm interested</h6>
    <p class="what mb-1">
      Some members get an "I'm interested" button beside Reply. A mark is a
      quick sign of interest before a reply, and a chase-up email goes the next
      day if the post is still there.
    </p>
    <p v-if="!detail" class="none text-muted mb-0">No marks counted yet.</p>
    <b-table
      v-else
      :items="items"
      :fields="fields"
      small
      class="figures mb-1"
    />
  </section>
</template>

<script setup>
// What is particular to the experiment interested-button, in the details of the Experiments section: how many
// members marked a post, and how many marks went on to a reply, before and after the chase-up email. The figures
// are the experiment's own last read (result.detail.interested, written by InterestedReplySource), so this needs no
// store and no call, and it draws nothing for any other experiment. Before the first read the key is absent.
import { computed } from 'vue'

const props = defineProps({
  // One experiment of the experiments response.
  experiment: { type: Object, required: true },
})

const mine = computed(() => props.experiment?.name === 'interested-button')

const detail = computed(() => {
  const d = props.experiment?.result?.detail?.interested
  return d && typeof d === 'object' && !Array.isArray(d) ? d : null
})

const num = (n) => (n || 0).toLocaleString()

const fields = [
  { key: 'measure', label: '' },
  { key: 'figure', label: '' },
]

const items = computed(() => {
  const d = detail.value || {}
  const marks = d.marks || 0
  const replied = d.replied || 0
  const pct = marks ? ' (' + Math.round((replied / marks) * 100) + '%)' : ''

  return [
    { measure: 'Members who marked', figure: num(d.members) },
    { measure: 'Posts marked', figure: num(marks) },
    { measure: 'Marks that led to a reply', figure: num(replied) + pct },
    {
      measure: 'Replied before the chase-up',
      figure: num(d.replied_before_chase),
    },
    {
      measure: 'Replied after the chase-up',
      figure: num(d.replied_after_chase),
    },
    { measure: 'Chase-ups sent', figure: num(d.chased) },
  ]
})
</script>

<style scoped lang="scss">
// A sub-heading in the style of the headings of the details, as the Channel block draws its own.
.ux-exp-h {
  margin-bottom: 0.15rem;
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: none;
  color: #495057;
}

.ux-interested :deep(td),
.ux-interested :deep(th) {
  overflow-wrap: anywhere;
  font-size: 0.75rem;
  line-height: 1.25;
  padding: 0.2rem 0.3rem;
}
</style>
