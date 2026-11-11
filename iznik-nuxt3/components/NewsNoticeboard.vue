<template>
  <div>
    <NewsUserIntro
      v-if="userid"
      :userid="userid"
      :newsfeed="newsfeed"
      append="put up a poster"
      :append-bold="info.title"
    />
    <p>
      To help Freegle, <strong>{{ newsfeed.displayname }}</strong> printed a
      Freegle poster and put it up where people can see it.
    </p>
    <notice-message v-if="info.description || info.name" class="prewrap">
      <strong v-if="info.name">"{{ info.name.trim() }}"</strong>
      <br v-if="info.name && info.description" />
      <em v-if="info.description">"{{ info.description.trim() }}"</em>
    </notice-message>
    <p class="mt-1">
      <strong>We need your help to get more people freegling</strong>. Could you
      put one up too?
    </p>
    <l-map
      ref="map"
      :zoom="14"
      :max-zoom="maxZoom"
      :center="[info.lat, info.lng]"
      :style="'width: 100%; height: 200px'"
    >
      <l-tile-layer :url="mapTile" :attribution="mapAttribution" />
      <l-marker :lat-lng="[info.lat, info.lng]" :interactive="false" />
    </l-map>
    <div class="mt-2 d-flex flex-wrap justify-content-between">
      <NewsLoveComment
        :newsfeed="newsfeed"
        @focus-comment="emit('focus-comment')"
      />
      <nuxt-link no-prefetch to="/promote">
        <b-button variant="secondary" size="sm" class="d-inline-block">
          <v-icon icon="bullhorn" /> Put up a poster
        </b-button>
      </nuxt-link>
    </div>
    <NewsShareModal
      v-if="showNewsShareModal"
      :newsfeed="newsfeed"
      @hidden="showNewsShareModal = false"
    />
  </div>
</template>
<script setup>
import { defineAsyncComponent, ref, computed } from 'vue'
import { useNewsfeedStore } from '~/stores/newsfeed'
import { twem } from '~/composables/useTwem'
import NewsUserIntro from '~/components/NewsUserIntro'
import NewsLoveComment from '~/components/NewsLoveComment'
import NoticeMessage from '~/components/NoticeMessage'
import { attribution, osmtile } from '~/composables/useMap'
import { MAX_MAP_ZOOM } from '~/constants'

const NewsShareModal = defineAsyncComponent(
  () => import('~/components/NewsShareModal')
)

const props = defineProps({
  id: {
    type: Number,
    required: true,
  },
})

const emit = defineEmits(['focus-comment'])

const newsfeedStore = useNewsfeedStore()

// Data properties
const showNewsShareModal = ref(false)
const map = ref(null)

// Leaflet instance
if (import.meta.client) {
  await import('leaflet/dist/leaflet-src.esm')
}

// Map properties
const maxZoom = computed(() => MAX_MAP_ZOOM)
const mapTile = osmtile()
const mapAttribution = attribution()

// Computed properties
const newsfeed = computed(() => {
  return newsfeedStore.byId(props.id)
})

const userid = computed(() => {
  return newsfeed.value?.userid
})

const info = computed(() => {
  let infoData = {}
  try {
    infoData = JSON.parse(newsfeed.value?.message)

    if (infoData?.description) {
      const desc = twem(infoData.description)
      infoData.description = desc
    }
  } catch (e) {
    console.log('Invalid noticeboard', newsfeed.value)
  }

  return infoData
})
</script>
