<template>
  <div>
    <div class="d-flex justify-content-around">
      <iframe
        title="Facebook post share"
        :src="
          'https://www.facebook.com/plugins/post.php?href=https%3A%2F%2Fwww.facebook.com%2FFreegle%2Fposts%2F' +
          postId +
          '&show_text=true'
        "
        width="552"
        height="576"
        style="border: none; overflow: hidden"
        scrolling="no"
        frameborder="0"
        allowfullscreen="true"
        allow="
          autoplay;
          clipboard-write;
          encrypted-media;
          picture-in-picture;
          web-share;
        "
      />
    </div>
    <div class="d-flex flex-wrap justify-content-around mt-3">
      <b-button variant="secondary" size="lg" @click="skip">
        Skip this one
      </b-button>
      <ShareLink
        network="facebook"
        :url="'https://www.facebook.com/Freegle/posts/' + postId"
        title="Sharing Freegle post'"
        hashtags="freegle,free,reuse"
        @close="close"
      >
        <b-button variant="secondary" size="lg" class="facebook">
          <v-icon :icon="['fab', 'facebook']" /> Share on Facebook
        </b-button>
      </ShareLink>
    </div>
  </div>
</template>
<script setup>
import { computed } from 'vue'
import { useMicroVolunteeringStore } from '~/stores/microvolunteering'

const props = defineProps({
  id: {
    type: String,
    required: true,
  },
})

const emit = defineEmits(['next'])

const microVolunteeringStore = useMicroVolunteeringStore()

const postId = computed(() => {
  return props.id.substring(props.id.indexOf('_') + 1)
})

async function skip() {
  await microVolunteeringStore.respond({
    facebook: props.id,
    response: 'Reject',
  })

  considerNext()
}

async function done() {
  await microVolunteeringStore.respond({
    facebook: props.id,
    response: 'Approve',
  })

  considerNext()
}

function considerNext() {
  emit('next')
}

function close() {
  // Called when the social sharing dialog is closed
  done()
}
</script>
