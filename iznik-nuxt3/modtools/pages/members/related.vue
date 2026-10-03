<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpRelated />
      <div
        v-for="member in members"
        :key="'memberlist-' + member.id"
        class="p-0 mt-2"
      >
        <ModRelatedMember :memberid="member.id" />
      </div>
      <NoticeMessage v-if="!members.length" variant="info">
        No possible duplicate accounts to review right now.
      </NoticeMessage>
    </client-only>
  </div>
</template>
<script setup>
// Self-moderating rework: this page used to be a per-community list of
// possible-duplicate-account pairs (ModGroupSelect + groupid), populated via
// useModMembers' shared loadMore/collection mechanism against a "Related"
// collection. That mechanism was dead on both sides even before this
// rework, not just made obsolete by it: loadMore only ever sent
// filter/q/since (no collection param), and /modtools/members never
// returned a `collection` field, so the old client-side filter
// (`m.collection === 'Related'`) always matched nothing - this page
// silently showed "no related members" as a permanent false all-clear.
//
// The feature is real and national: `users_related` pairs exist, and
// session.go's moderator work-badge already counts them nationally (no
// groupid) - see its "Related members" block. askMerge/ignoreMerge (via
// api().merge -> PUT/DELETE /merge, called from ModRelatedMember.vue via
// the member store) are already correct, national and groupid-free.
//
// The "Member tools contract" in briefs/modtools-rework.md (added
// 2026-09-26) now specifies `GET /modtools/members?filter=related`
// returning the pairs as {id, user1, user2, reason} (with display names),
// newest first - this page fetches exactly that via the normal member
// store (fetchMembers -> memberStore.list), the same path every other
// /modtools/members filter already uses. `members` here is a live view of
// the store, so askMerge/ignoreMerge deleting an entry (member.js) removes
// its card with no extra wiring.
//
// Guard: as of 2026-09-26 the Go handler doesn't recognise filter=related
// yet and silently falls back to its default "new members" filter instead
// of erroring (member/member.go's ListMembers, default case) - those rows
// have `displayname`/`added` but no `user1`/`user2`. hasPair() below is a
// defensive shape check so that fallback can never be mistaken for a real
// pair and rendered as one via ModRelatedMember (which expects user1/
// user2). It costs nothing once the real endpoint ships - every genuine
// pair has both fields - and avoids a plausible-wrong-answer failure mode
// in the meantime (a "no error, no warning" trap; see
// .claude/rules/conventions.md).
import { computed, onMounted } from 'vue'
import { useMemberStore } from '~/modtools/stores/member'

const memberStore = useMemberStore()

function hasPair(member) {
  return member && member.user1 != null && member.user2 != null
}

const members = computed(() =>
  Object.values(memberStore.list).filter(hasPair)
)

onMounted(async () => {
  await memberStore.fetchMembers({ filter: 'related' })
})
</script>
