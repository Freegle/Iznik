<template>
  <client-only>
    <b-row class="m-0">
      <b-col cols="0" lg="3" class="d-none d-lg-block" />
      <b-col cols="12" lg="6" class="p-0">
        <div>
          <h1>Shortlinks</h1>
          <h5>
            Shortlinks let people find things on Freegle quickly. On this page
            you can see all the shortlinks we have, and view statistics about
            them.
          </h5>
          <b-card no-body>
            <b-card-body>
              <p>
                You can also create your own shortlink. This is particularly
                useful if you want to promote a page in a particular way, and
                then see how effective that promotion was. Keep them short
                (less typing) and memorable (less forgetting).
              </p>
              <NoticeMessage v-if="error" variant="danger" class="mb-2">
                {{ error }}
              </NoticeMessage>
              <div class="d-flex justify-content-between flex-wrap">
                <b-form-input
                  v-model="url"
                  placeholder="Destination URL"
                  class="select"
                />
                <div class="d-flex">
                  <span class="mt-2 fw-bold">freegle.in/</span>
                  <b-form-input
                    v-model="name"
                    placeholder="Enter your shortlink name"
                    maxlength="30"
                  />
                </div>
                <SpinButton
                  variant="primary"
                  icon-name="save"
                  label="Create"
                  @handle="create"
                />
                <div v-if="id" class="w-100 mt-3 m-0">
                  <ShortLink :id="id" nostats />
                </div>
              </div>
            </b-card-body>
          </b-card>
        </div>
        <b-row class="mt-2 bg-white m-0 fw-bold">
          <b-col cols="3"> Destination </b-col>
          <b-col cols="7"> Shortlink </b-col>
          <b-col cols="2" />
        </b-row>
        <ShortLinks :shortlinks="sortedLinks" />
      </b-col>
      <b-col cols="0" lg="3" class="d-none d-lg-block" />
    </b-row>
  </client-only>
</template>
<script setup>
import { ref, computed } from 'vue'
import ShortLinks from '~/components/ShortLinks'
import NoticeMessage from '~/components/NoticeMessage'
import { useShortlinkStore } from '~/stores/shortlinks'
import ShortLink from '~/components/ShortLink'
import SpinButton from '~/components/SpinButton'

definePageMeta({
  layout: 'login',
})

const shortlinkStore = useShortlinkStore()
await shortlinkStore.fetch()

// State
const url = ref(null)
const name = ref(null)
const error = ref(null)
const id = ref(null)

// Computed properties
const shortlinks = computed(() => shortlinkStore?.list)

const sortedLinks = computed(() => {
  if (shortlinks.value) {
    return Object.values(shortlinks.value)
      .concat()
      .sort((a, b) => a.name.toLowerCase().localeCompare(b.name.toLowerCase()))
  }

  return null
})

// Methods
const create = async (callback) => {
  if (name.value && url.value) {
    try {
      id.value = await shortlinkStore.add(name.value, url.value)

      if (!id.value) {
        error.value =
          'Failed to create. Please make sure the link name is unique.'
      }
    } catch (e) {
      if (e?.response?.data) {
        // Duplicate
        error.value = e.response.data.status
      }

      console.log('Failed to create shortlink', e.response)
    }
  }
  callback()
}
</script>
<style scoped>
.select {
  max-width: 300px;
}
</style>
