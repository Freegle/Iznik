<template>
  <div>
    <client-only>
      <div class="d-flex justify-content-between flex-wrap">
        <ModMemberSearchbox :search="search" @search="startsearch" />
      </div>
      <div v-if="directId">
        <ModMember :membershipid="directId" />
      </div>
      <div v-else-if="search">
        <ModMembers />
        <infinite-loading
          direction="top"
          :distance="distance"
          :identifier="bump"
          @infinite="loadMore"
        >
          <template #spinner>
            <Spinner :size="50" />
          </template>
          <template #complete>
            <notice-message v-if="!members?.length">
              There are no members to show at the moment.
            </notice-message>
          </template>
        </infinite-loading>
      </div>
      <NoticeMessage v-else variant="info" class="mt-2">
        Search for a member by name, email or id.
      </NoticeMessage>
    </client-only>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from '#imports'
import { useMemberStore } from '~/modtools/stores/member'
import { setupModMembers } from '@/composables/useModMembers'

// Self-moderating rework: this used to be a per-community membership browser
// (ModGroupSelect + groupid, Add/Ban-to-group modals). Moderators are
// national now, so there is no group to choose and no "add/ban to this
// community" action - ban/unban lives on the member card (ModMember.vue) via
// the national /modtools/members/:id/ban endpoint. The route flattens from
// [[id]]/[[term]] (groupid/searchterm) to a single [[term]]: a numeric term
// is a direct id lookup (the links other components use to jump to a
// member), anything else is a national name/email search via the
// filter=search contract in modtools-rework.md's "Member tools contract".
const memberStore = useMemberStore()

const modMembers = setupModMembers(true)
modMembers.filter.value = 'search'

const route = useRoute()
const router = useRouter()

const { bump, search, distance, members, loadMore } = modMembers

const directId = ref(0)

function applyTerm(term) {
  const trimmed = (term || '').toString().trim()

  if (trimmed && /^\d+$/.test(trimmed)) {
    directId.value = parseInt(trimmed)
    search.value = ''
    memberStore.fetch(directId.value)
  } else {
    directId.value = 0
    search.value = trimmed
    memberStore.clear()
    bump.value++
  }
}

onMounted(() => {
  let termInit = ''
  if (route?.params && 'term' in route.params && route.params.term) {
    termInit = route.params.term
  }
  applyTerm(termInit)
})

function startsearch(searchTerm) {
  searchTerm = (searchTerm || '').trim()
  const newpath = searchTerm
    ? '/members/approved/' + searchTerm
    : '/members/approved/'

  if (newpath !== router.currentRoute.value.path) {
    router.push(newpath)
  }

  applyTerm(searchTerm)
}

defineExpose({ startsearch })
</script>
