<template>
  <div>
    <NoticeMessage variant="info" class="mb-3">
      <p class="mb-0">
        <strong>Providers refusing our mail.</strong> When a provider stops
        accepting mail from our sending servers, we pause generating email for
        everyone at that provider rather than pile up mail that can't be
        delivered. This is our sending reputation, not a problem with anyone's
        address.
      </p>
    </NoticeMessage>

    <div v-if="loading" class="text-center p-4">
      <v-icon icon="sync" class="fa-spin" /> Loading...
    </div>

    <NoticeMessage v-else-if="error" variant="danger">
      {{ error }}
    </NoticeMessage>

    <template v-else>
      <NoticeMessage v-if="!suppressions.length" variant="success" class="mb-3">
        Nothing is being deferred. Every provider is accepting our mail.
      </NoticeMessage>

      <template v-else>
        <h3 class="mb-2">Currently suppressed</h3>
        <b-table-simple responsive striped class="mb-4">
          <b-thead>
            <b-tr>
              <b-th>Provider</b-th>
              <b-th>Relay or address</b-th>
              <b-th>Delayed since</b-th>
              <b-th class="text-end">Queued</b-th>
              <b-th>Why</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="s in suppressions" :key="'sup-' + s.id">
              <b-td>{{ s.provider || 'Unknown' }}</b-td>
              <b-td>
                <code>{{ s.value }}</code>
                <b-badge
                  v-if="s.scope === 'address'"
                  variant="secondary"
                  class="ms-1"
                >
                  single mailbox
                </b-badge>
              </b-td>
              <b-td>
                <span :title="s.deferredsince">{{
                  dateshort(s.deferredsince)
                }}</span>
              </b-td>
              <b-td class="text-end">{{ s.messagecount }}</b-td>
              <b-td class="small text-muted">{{ s.reason }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </template>

      <h3 class="mb-2">
        Members with mail held
        <b-badge v-if="members.length" variant="info">{{
          members.length
        }}</b-badge>
      </h3>

      <p v-if="!members.length" class="text-muted">
        No mail has been held back yet. A suppression starts holding mail from
        the moment it's created, so this fills up as each member's next email
        comes due.
      </p>

      <template v-else>
        <NoticeMessage
          v-if="members.length >= memberLimit && memberLimit > 0"
          variant="warning"
          class="mb-2"
        >
          Showing the first {{ memberLimit }} members. There are more.
        </NoticeMessage>

        <b-table-simple responsive striped small>
          <b-thead>
            <b-tr>
              <b-th>Member</b-th>
              <b-th>Email</b-th>
              <b-th>Provider</b-th>
              <b-th>Delayed since</b-th>
              <b-th class="text-end">Held</b-th>
            </b-tr>
          </b-thead>
          <b-tbody>
            <b-tr v-for="m in members" :key="'mem-' + m.userid">
              <b-td>
                <nuxt-link :to="'/support/' + m.userid">
                  {{ m.displayname || '#' + m.userid }}
                </nuxt-link>
              </b-td>
              <b-td class="small">{{ m.email }}</b-td>
              <b-td>{{ m.provider || 'Unknown' }}</b-td>
              <b-td>
                <span :title="m.since">{{ dateshort(m.since) }}</span>
              </b-td>
              <b-td class="text-end">{{ m.heldmessages }}</b-td>
            </b-tr>
          </b-tbody>
        </b-table-simple>
      </template>
    </template>
  </div>
</template>
<script setup>
import { computed, onMounted } from 'vue'
import { useEmailTrackingStore } from '~/modtools/stores/emailtracking'
import { dateshort } from '~/composables/useTimeFormat'

const store = useEmailTrackingStore()

const loading = computed(() => store.deferralsLoading)
const error = computed(() => store.deferralsError)
const suppressions = computed(() => store.deferralSuppressions)
const members = computed(() => store.deferralMembers)
const memberLimit = computed(() => store.deferralMemberLimit)

onMounted(() => {
  store.fetchDeferrals()
})
</script>
