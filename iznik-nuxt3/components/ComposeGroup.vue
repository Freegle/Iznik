<template>
  <div class="compose-group" data-test="compose-group">
    <span v-if="groupName" class="compose-group__name">{{ groupName }}</span>
    <span v-else class="compose-group__finding text-muted">
      Finding your local community…
    </span>
  </div>
</template>
<script setup>
import { computed, onMounted } from 'vue'
import { useComposeStore } from '~/stores/compose'
import { useGroupStore } from '~/stores/group'
import api from '~/api'
import { useRuntimeConfig } from '#app'

// Rippling-out (#10): the origin group is DERIVED from the poster's postcode/location (the
// containing-or-closest Freegle community) and is no longer chosen by hand. The post then
// ripples out from there, so a manual group pick is no longer meaningful. A pre-set group
// (e.g. a repost) is preserved; otherwise we lock to the first (nearest/containing) group.

const composeStore = useComposeStore()
const groupStore = useGroupStore()
const runtimeConfig = useRuntimeConfig()

const postcode = computed(() => composeStore?.postcode)

// The group the post will go to: a pre-set group (repost) wins, else the derived origin.
const selectedGroupId = computed(
  () => composeStore?.group || postcode.value?.groupsnear?.[0]?.id || null
)

const groupName = computed(() => {
  const id = selectedGroupId.value
  if (!id) {
    return null
  }
  const near = (postcode.value?.groupsnear || []).find(
    (g) => parseInt(g.id) === parseInt(id)
  )
  const cached = groupStore.get(id)
  return (
    near?.namedisplay ||
    near?.nameshort ||
    cached?.namedisplay ||
    cached?.nameshort ||
    null
  )
})

onMounted(async () => {
  // Refetch the postcode so its group list is fresh (groups can merge), then lock the origin
  // group to the containing-or-closest community unless one was already chosen (repost).
  if (postcode.value) {
    try {
      const location = await api(runtimeConfig).location.typeahead(
        postcode.value.name
      )
      if (location?.[0]) {
        composeStore.setPostcode(location[0])
      }
    } catch (e) {
      console.error('Failed to refetch postcode', e)
    }
  }

  if (!composeStore.group && postcode.value?.groupsnear?.length) {
    composeStore.group = postcode.value.groupsnear[0].id
  }
})
</script>
<style scoped>
.compose-group__name {
  font-weight: 600;
}
</style>
