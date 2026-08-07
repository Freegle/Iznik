<template>
  <nuxt-link
    v-if="message"
    no-prefetch
    :to="'/mypost/' + id"
    class="promptpost"
    :data-testid="'chat-prompt-post-' + id"
  >
    <div class="promptpost__photo" :class="placeholderClass">
      <OurUploadedImage
        v-if="message.attachments?.[0]?.ouruid"
        :src="message.attachments[0].ouruid"
        :modifiers="message.attachments[0].externalmods"
        alt=""
        class="promptpost__img"
        :width="80"
        :height="80"
      />
      <NuxtPicture
        v-else-if="message.attachments?.[0]?.externaluid"
        format="webp"
        provider="uploadcare"
        :src="message.attachments[0].externaluid"
        :modifiers="message.attachments[0].externalmods"
        alt=""
        class="promptpost__img"
        :width="80"
        :height="80"
      />
      <ProxyImage
        v-else-if="message.attachments?.[0]?.path"
        class-name="promptpost__img"
        alt=""
        :src="message.attachments[0].path"
        :width="80"
        :height="80"
        fit="cover"
      />
      <v-icon v-else :icon="categoryIcon" class="promptpost__nophoto" />
    </div>
    <span class="promptpost__subject">{{ strippedSubject }}</span>
  </nuxt-link>
</template>
<script setup>
import { onMounted, toRef } from 'vue'
import { useMessageDisplay } from '~/composables/useMessageDisplay'
import { useMessageStore } from '~/stores/message'

// One of a member's posts, listed inside a Freegle prompt.
//
// Deliberately a small row rather than the full ChatMessageCard. A prompt talks
// about everything a member has outstanding, so a house clearance would
// otherwise stack five full-bleed photo tiles inside one message and bury the
// question underneath them. This is modelled on the bulk freegling item list,
// which has the same job: show which things, compactly, however many there are.
//
// It exists at all because the wording must not name an item. Interpolating a
// subject into prose gives you "your pending bookshelf", and nothing can tell a
// sensible item name from a silly one - so the text names none of them and this
// says exactly which.

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const messageStore = useMessageStore()

const { message, strippedSubject, placeholderClass, categoryIcon } =
  useMessageDisplay(toRef(props, 'id'))

// useMessageDisplay only reads the store, so somebody has to put the post in it.
// Done in onMounted rather than as a top-level await: an async setup makes this
// a Suspense boundary, and a prompt listing five posts would then hold the whole
// message back until the slowest of them resolved.
onMounted(async () => {
  try {
    await messageStore.fetch(props.id)
  } catch (e) {
    // A post that has since been deleted simply does not appear. The question
    // still reads correctly without it, and the counts came from the server.
    console.log('Prompt post fetch failed', props.id, e)
  }
})
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'assets/css/_color-vars.scss';

.promptpost {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
  color: inherit;
  text-decoration: none;

  &:hover .promptpost__subject {
    text-decoration: underline;
  }
}

.promptpost__photo {
  flex: 0 0 auto;
  width: 40px;
  height: 40px;
  border-radius: var(--radius-sm, 0.375rem);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: $color-gray-3;
}

.promptpost__img {
  width: 40px;
  height: 40px;
  object-fit: cover;
}

.promptpost__nophoto {
  color: $color-gray--dark;
}

.promptpost__subject {
  /* Long subjects must not push the row wide - the question is the point. */
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-weight: 500;
}
</style>
