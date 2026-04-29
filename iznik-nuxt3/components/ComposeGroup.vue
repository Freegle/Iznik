<template>
  <b-form-select
    v-model="group"
    :style="(width ? 'width: ' + width + 'px' : '') + '; max-width: 300px;'"
    :options="groupOptions"
  />
</template>
<script setup>
import { computed, nextTick, onMounted } from 'vue'
import { useComposeStore } from '~/stores/compose'
import { useAuthStore } from '~/stores/auth'
import api from '~/api'
import { useRuntimeConfig } from '#app'

defineProps({
  width: {
    type: Number,
    required: false,
    default: null,
  },
})

const composeStore = useComposeStore()
const authStore = useAuthStore()
const runtimeConfig = useRuntimeConfig()

const postcode = computed(() => {
  return composeStore?.postcode
})

const myGroups = computed(() => {
  console.log('Compute myGroups', authStore.groups)
  return authStore.groups || []
})

const group = computed({
  get() {
    let ret = composeStore?.group

    if (!ret) {
      if (postcode.value?.groupsnear) {
        ret = postcode.value.groupsnear[0].id
      }
    }

    return ret
  },
  set(newVal) {
    composeStore.group = newVal
  },
})

const groupOptions = computed(() => {
  const ret = []
  const ids = {}

  if (postcode.value && postcode.value.groupsnear) {
    for (const group of postcode.value.groupsnear) {
      if (!ids[group.id]) {
        ret.push({
          value: group.id,
          text: group.namedisplay ? group.namedisplay : group.nameshort,
        })

        ids[group.id] = true
      }
    }
  }

  // Add any other groups we are a member of and might want to select.
  for (const group of myGroups.value) {
    if (!ids[group.groupid]) {
      ret.push({
        value: group.groupid,
        text: group.namedisplay ? group.namedisplay : group.nameshort,
      })

      ids[group.groupid] = true
    }
  }

  return ret
})

onMounted(async () => {
  // The postcode we have contains a list of groups. That list might contain groups which are no longer valid,
  // for example if they have been merged. So we want to refetch the postcode so that our store gets updated.
  // Preserve the currently selected group across the refetch so we don't overwrite a user's choice.

  // Save the intended group at the very top, before ANY async work.
  // This captures the pre-selected group (e.g. from a repost flow).
  const savedGroup = composeStore.group

  if (postcode.value) {
    let location
    try {
      location = await api(runtimeConfig).location.typeahead(postcode.value.name)
    } catch (e) {
      console.error('Failed to fetch postcode', e)
    }

    if (location) {
      // Snapshot the group AFTER the async wait but BEFORE setPostcode.
      // If the user changed the group during the typeahead, this captures their intent.
      const groupAfterTypeahead = composeStore.group

      composeStore.setPostcode(location[0])

      // b-form-select may auto-update composeStore.group when its options change
      // (because the current value is no longer in the new options list).
      // Wait for Vue's reactive cycle to settle, then restore the intended group.
      await nextTick()

      if (!composeStore.group || composeStore.group !== groupAfterTypeahead) {
        // Reactive cascade cleared or replaced the group — restore the right value:
        // user's choice if they changed it during typeahead, otherwise savedGroup.
        composeStore.group = groupAfterTypeahead || savedGroup
      }
    } else if (savedGroup && !composeStore.group) {
      composeStore.group = savedGroup
    }
  }

  await authStore.fetchUser()

  // Final guard: b-form-select may have reset composeStore.group during the
  // async fetchUser wait (options re-evaluated while the saved group wasn't in
  // groupsnear yet). Restore savedGroup if it is still valid — i.e. present in
  // groupsnear or among the user's group memberships.
  if (savedGroup && composeStore.group !== savedGroup) {
    const groupsNear = postcode.value?.groupsnear || []
    const savedGroupValid =
      groupsNear.some((g) => parseInt(g.id) === parseInt(savedGroup)) ||
      myGroups.value.some((g) => parseInt(g.groupid) === parseInt(savedGroup))

    if (savedGroupValid) {
      composeStore.group = savedGroup
    }
  }

  // If we have a postcode with groups but no group selected, auto-select the first one.
  if (postcode.value?.groupsnear?.length && !composeStore.group) {
    composeStore.group = postcode.value.groupsnear[0].id
  }
})
</script>
