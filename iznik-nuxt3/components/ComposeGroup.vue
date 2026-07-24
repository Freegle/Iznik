<template>
  <div class="compose-group" data-test="compose-group">
    <div v-if="groupName" class="compose-group__card">
      <b-img
        rounded
        alt="Community profile picture"
        :src="profile"
        class="compose-group__logo"
      />
      <div class="compose-group__info">
        <span class="compose-group__name">{{ groupName }}</span>
        <p v-if="tagline" class="compose-group__tagline">
          {{ tagline }}
        </p>
      </div>
    </div>
    <span v-else class="compose-group__finding text-muted">
      Finding your local community…
    </span>
  </div>
</template>
<script setup>
import { computed, onMounted, watch } from 'vue'
import { useComposeStore } from '~/stores/compose'
import { useGroupStore } from '~/stores/group'
import api from '~/api'
import { useRuntimeConfig } from '#app'

// Rippling-out (#10): the origin group is DERIVED from the poster's postcode/location (the
// containing-or-closest Freegle community) and is no longer chosen by hand. The post then
// ripples out from there, so a manual group pick is no longer meaningful. Any pre-set group
// (e.g. carried over from a repost or an earlier compose) is IGNORED - we always lock to the
// first (nearest/containing) group for the current postcode.

const composeStore = useComposeStore()
const groupStore = useGroupStore()
const runtimeConfig = useRuntimeConfig()

const postcode = computed(() => composeStore?.postcode)

// The group the post will go to: always the derived origin (nearest/containing community)
// for the current postcode. A pre-set group is deliberately ignored (see note above).
const selectedGroupId = computed(
  () => postcode.value?.groupsnear?.[0]?.id || null
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

// The postcode's groupsnear entries are deliberately trimmed (id/name only) to keep the
// compose store small, so the profile picture and tagline come from the full group record,
// which we fetch below. Name shows immediately; profile/tagline fill in once it loads.
const fullGroup = computed(() => {
  const id = selectedGroupId.value
  return id ? groupStore.get(id) : null
})

const profile = computed(() => fullGroup.value?.profile || '/icon.png')

const tagline = computed(() => fullGroup.value?.tagline || null)

// Fetch the full group whenever the derived origin changes, so the card can show its
// profile picture and tagline.
watch(
  selectedGroupId,
  (id) => {
    if (id) {
      groupStore.fetch(id)
    }
  },
  { immediate: true }
)

onMounted(async () => {
  // Refetch the postcode so its group list is fresh (groups can merge), then lock the origin
  // group to the containing-or-closest community, overriding any pre-set group.
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

  if (postcode.value?.groupsnear?.length) {
    composeStore.group = postcode.value.groupsnear[0].id
  }
})
</script>
<style scoped lang="scss">
@import 'assets/css/_color-vars.scss';

.compose-group__card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  border: 1px solid $color-gray--light;
  border-radius: 8px;
  background: $color-white;
}

.compose-group__logo {
  width: 56px;
  height: 56px;
  flex-shrink: 0;
  object-fit: cover;
  border: 2px solid $color-gray--light;
}

.compose-group__info {
  flex: 1;
  min-width: 0;
}

.compose-group__name {
  display: block;
  font-weight: 700;
  font-size: 1.05rem;
  color: $color-header;
  line-height: 1.2;
}

.compose-group__tagline {
  margin: 0.15rem 0 0;
  font-size: 0.85rem;
  color: $color-gray--darker;
  line-height: 1.3;
}
</style>
