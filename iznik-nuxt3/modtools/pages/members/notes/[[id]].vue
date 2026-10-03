<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpComments />
      <ModCommentUser
        v-for="comment in visibleComments"
        :key="'commentlist-' + comment.id"
        :commentid="comment.id"
        class="p-0 mt-2"
      />
      <NoticeMessage v-if="!comments.length && !busy" class="mt-2">
        There are no comments to show at the moment.
      </NoticeMessage>

      <infinite-loading :key="bump" :distance="distance" @infinite="loadMore">
        <template #no-results>
          <span />
        </template>
        <template #no-more>
          <span />
        </template>
        <template #spinner>
          <span>
            <Spinner :size="50" />
          </span>
        </template>
      </infinite-loading>
    </client-only>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useCommentStore } from '~/stores/comment'

// Stores and composables
const commentStore = useCommentStore()

// Local state (formerly data())
const context = ref(null)
const distance = ref(1000)
const show = ref(0)
const busy = ref(false)
const complete = ref(false)
const bump = ref(1)

// Computed properties.  Moderators are national now, so there is no
// community to choose and no per-community filtering - every mod note is
// visible to every moderator.
const comments = computed(() => {
  return commentStore.sortedList
})

const visibleComments = computed(() => {
  return comments.value.slice(0, show.value)
})

// Lifecycle
onMounted(() => {
  commentStore.clear()
})

// Methods
async function loadMore($state) {
  busy.value = true

  if (show.value < comments.value.length) {
    // This means that we will gradually add the members that we have fetched from the server into the DOM.
    // Doing that means that we will complete our initial render more rapidly and thus appear faster.
    show.value++
    $state.loaded()
  } else {
    const currentCount = comments.value.length

    try {
      await commentStore.fetch({
        context: context.value,
      })

      context.value = commentStore.context

      if (currentCount === comments.value.length) {
        complete.value = true
        busy.value = false
        $state.complete()
      } else {
        $state.loaded()
        busy.value = false
        show.value++
      }
    } catch (e) {
      $state.complete()
      busy.value = false
      console.log('Complete on error', e)
    }
  }
}
</script>

<style scoped>
select {
  max-width: 300px;
}
</style>
