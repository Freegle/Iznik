<template>
  <b-row v-if="user" class="mb-2">
    <b-col cols="4">
      <ProfileImage
        :image="user.profile?.turl || user.profile?.paththumb"
        :name="user.displayname"
        class="me-2"
        size="lg"
      />
    </b-col>
    <b-col cols="6">
      <strong>{{ user.displayname || '#' + userid }}</strong>
      <span class="text-faded small">
        <v-icon icon="hashtag" class="text-muted" scale="0.75" />{{ userid }}
      </span>
    </b-col>
    <b-col>
      <b-button v-if="supportOrAdmin" size="sm" variant="white" @click="remove">
        <v-icon icon="trash-alt" />
      </b-button>
    </b-col>
  </b-row>
</template>
<script setup>
import { computed, watch } from 'vue'
import { useTeamStore } from '@/stores/team'
import { useUserStore } from '~/stores/user'
import { useMe } from '~/composables/useMe'

const props = defineProps({
  teamid: {
    type: Number,
    required: true,
  },
  userid: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['removed'])

const { supportOrAdmin } = useMe()

const userStore = useUserStore()
const user = computed(() => userStore.byId(props.userid))

watch(
  () => props.userid,
  (uid) => {
    if (uid && !userStore.byId(uid)) userStore.fetch(uid)
  },
  { immediate: true }
)

async function remove() {
  const teamStore = useTeamStore()
  try {
    await teamStore.remove({
      id: props.teamid,
      userid: props.userid,
    })
  } catch (e) {
    console.error('ModTeamMember remove failed:', e)
  }
  // Without the catch a refused remove reaches Nuxt's default Vue error
  // handling, which replaces the whole ModTools page with the app error
  // page. Emit anyway so the page refetches the team and carries on.
  emit('removed')
}
</script>
<style scoped>
.max {
  max-width: 145px;
}
</style>
