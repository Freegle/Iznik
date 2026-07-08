<template>
  <client-only>
    <b-container class="py-3">
      <b-row>
        <b-col cols="12" lg="8" offset-lg="2">
          <div v-if="loading" class="text-center my-5" data-testid="bulkupdate-loading">
            <b-spinner variant="success" />
            <p class="text-muted mt-2">Loading the items…</p>
          </div>

          <NoticeMessage
            v-else-if="notFound"
            variant="warning"
            data-testid="bulkupdate-notfound"
          >
            <h4 class="mb-1">This link isn't valid</h4>
            <p class="mb-0">
              The update link may be wrong or may have been withdrawn. Please
              check the link, or ask whoever sent it to you for a fresh one.
            </p>
          </NoticeMessage>

          <template v-else>
            <h1 class="bulkupdate__title" data-testid="bulkupdate-title">
              Update what's still available
            </h1>
            <p class="text-muted">
              These are the items in
              <strong>{{ offer.subject }}</strong
              >. If you've given some away elsewhere, switch them to
              <em>taken</em> or change the number left, so Freeglers only ask for
              what's still going. Your changes save as you make them - no login
              needed.
            </p>

            <div
              v-if="savedFlash"
              class="bulkupdate__saved small text-success mb-2"
              data-testid="bulkupdate-saved"
            >
              <v-icon icon="check" /> Saved
            </div>
            <NoticeMessage
              v-if="saveError"
              variant="danger"
              data-testid="bulkupdate-error"
            >
              Sorry, that didn't save - please try again.
            </NoticeMessage>

            <BulkOfferUpdateItem
              v-for="(item, idx) in offer.items"
              :key="item.id"
              :item="item"
              :index="idx"
              :saving="savingId === item.id"
              @update="onUpdate"
            />

            <p class="text-muted small mt-3">
              Thanks for keeping this up to date - it saves everyone a wasted
              journey.
            </p>
          </template>
        </b-col>
      </b-row>
    </b-container>
  </client-only>
</template>

<script setup>
import { onMounted } from 'vue'
import { useRoute } from '#imports'
import NoticeMessage from '~/components/NoticeMessage'
import BulkOfferUpdateItem from '~/components/BulkOfferUpdateItem'
import { useBulkOfferUpdate } from '~/composables/useBulkOfferUpdate'

// Public, logged-out page: no login layout, no forceLogin. The secret token in
// the URL is the sole credential and only authorises availability/count edits to
// this one offer. All the logic lives in useBulkOfferUpdate (unit-tested).
definePageMeta({
  layout: 'default',
})

const route = useRoute()
const {
  loading,
  notFound,
  offer,
  savingId,
  savedFlash,
  saveError,
  load,
  onUpdate,
} = useBulkOfferUpdate(route.params.token)

onMounted(load)
</script>

<style scoped lang="scss">
.bulkupdate__title {
  font-size: 1.5rem;
  font-weight: 700;
}
</style>
