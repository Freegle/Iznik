<template>
  <div>
    <NoticeMessage
      v-if="members.length >= limit && limit > 0"
      variant="warning"
      class="mb-2"
    >
      Showing the first {{ limit }} members. There are more.
    </NoticeMessage>

    <b-table-simple responsive striped small class="mb-3">
      <b-thead>
        <b-tr>
          <b-th>Member</b-th>
          <b-th>Email</b-th>
          <b-th>Provider</b-th>
          <b-th>Since</b-th>
          <b-th>Kinds of mail</b-th>
          <b-th class="text-end">Emails not generated</b-th>
        </b-tr>
      </b-thead>
      <b-tbody>
        <b-tr v-for="m in members" :key="'held-' + m.userid">
          <b-td>
            <nuxt-link :to="'/support/' + m.userid">
              {{ m.displayname || '#' + m.userid }}
            </nuxt-link>
          </b-td>
          <b-td class="small">{{ m.email }}</b-td>
          <b-td>{{ m.provider || '-' }}</b-td>
          <b-td>
            <span :title="m.since">{{ dateshort(m.since) }}</span>
          </b-td>
          <b-td class="small text-muted">{{ m.types || '-' }}</b-td>
          <b-td class="text-end">{{ (m.skipped || 0).toLocaleString() }}</b-td>
        </b-tr>
      </b-tbody>
    </b-table-simple>
  </div>
</template>
<script setup>
import { dateshort } from '~/composables/useTimeFormat'

const NoticeMessage = defineAsyncComponent(
  () => import('~/components/NoticeMessage')
)

// The count is deliberately headed "emails not generated" rather than "held".
// It counts the times we declined to generate something, and an immediate
// digest is generated per matching post, so an active member on several
// communities reaches thousands in days - one had 11,694 over five. Read as an
// inbox count, which is what "held" invited, the figure is nonsense and the
// whole table loses credibility. Showing the kinds of mail beside it makes a
// big number explicable instead of alarming.
defineProps({
  members: {
    type: Array,
    required: true,
  },
  limit: {
    type: Number,
    required: false,
    default: 0,
  },
})
</script>
