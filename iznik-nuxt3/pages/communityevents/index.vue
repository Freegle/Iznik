<template>
  <client-only>
    <div class="events-page">
      <b-row class="m-0">
        <b-col cols="12" lg="6" class="p-0" offset-lg="3">
          <ScrollGrid
            :items="allOfEm"
            key-field="id"
            empty-icon="calendar-times"
            empty-text="No events at the moment."
          >
            <template #header>
              <div class="page-header">
                <p class="page-description">
                  Local events posted by freeglers like you.
                </p>
                <GlobalMessage />
                <div class="filter-actions">
                  <b-button
                    v-if="me"
                    variant="primary"
                    size="sm"
                    class="add-btn"
                    @click="openEventModal"
                  >
                    <v-icon icon="plus" /> Add event
                  </b-button>
                  <NoticeMessage v-else variant="info" class="sign-in-notice">
                    Please sign in to add an event.
                  </NoticeMessage>
                </div>
              </div>
              <h2 class="visually-hidden">List of community events</h2>
            </template>

            <template #item="{ item: id }">
              <CommunityEvent :id="id" :summary="false" />
            </template>

            <template #empty>
              <v-icon icon="calendar-times" class="scroll-grid__empty-icon" />
              <p>No events at the moment.</p>
              <b-button
                v-if="me"
                variant="primary"
                size="sm"
                @click="openEventModal"
              >
                <v-icon icon="plus" /> Add the first event
              </b-button>
            </template>

            <template #footer>
              <CommunityEventModal
                v-if="showEventModal"
                :start-edit="true"
                @hidden="showEventModal = false"
              />
            </template>
          </ScrollGrid>
        </b-col>
      </b-row>
    </div>
  </client-only>
</template>
<script setup>
import { defineAsyncComponent } from 'vue'
import { buildHead } from '~/composables/useBuildHead'
import { useCommunityEventStore } from '~/stores/communityevent'
import { useAuthStore } from '~/stores/auth'
import { useMe } from '~/composables/useMe'
import NoticeMessage from '~/components/NoticeMessage'
import GlobalMessage from '~/components/GlobalMessage'
import { ref, computed } from '#imports'
import CommunityEvent from '~/components/CommunityEvent.vue'
import ScrollGrid from '~/components/ScrollGrid'

const CommunityEventModal = defineAsyncComponent(
  () => import('~/components/CommunityEventModal')
)

const runtimeConfig = useRuntimeConfig()
const communityEventStore = useCommunityEventStore()
const authStore = useAuthStore()
const { me } = useMe()

const route = useRoute()

if (authStore.user) {
  await communityEventStore.fetchList()
}

const name = 'Community Events'
const image = null

useHead(
  buildHead(
    route,
    runtimeConfig,
    name,
    'These are local events, posted by other freeglers like you.',
    image,
    {
      class: 'overflow-y-scroll',
    }
  )
)

const allOfEm = computed(() => {
  return communityEventStore.forUser
})

watch(
  allOfEm,
  (newVal) => {
    if (newVal?.length) {
      const max = newVal.reduce((a, b) => Math.max(a, b), -Infinity)

      const settings = me.value?.settings || {}

      settings.lastCommunityEvent = max
      authStore.saveAndGet({
        settings,
      })
    }
  },
  { immediate: true }
)

const showEventModal = ref(false)

function openEventModal() {
  showEventModal.value = true
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';
@import 'assets/css/navbar.scss';

.events-page {
  background: $color-gray--lighter;
  min-height: 100vh;
  padding-bottom: $page-bottom-padding;
}

.page-header {
  background: white;
  padding: 1rem;
  margin-bottom: 0.75rem;
  box-shadow: var(--shadow-sm);
}

.page-description {
  font-size: 0.9rem;
  color: var(--color-gray-600);
  margin: 0 0 0.75rem 0;
}

.filter-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;

  .add-btn {
    flex-shrink: 0;
  }

  .sign-in-notice {
    width: 100%;
  }
}
</style>
