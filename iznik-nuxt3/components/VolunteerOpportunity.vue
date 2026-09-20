<template>
  <div v-if="volunteering" class="volop-card">
    <component :is="titleTag" class="volop-card__header">
      <nuxt-link
        :to="'/volunteering/' + volunteering.id"
        class="volop-card__title"
        no-prefetch
      >
        {{ volunteering.title }}
      </nuxt-link>
      <span v-if="!summary" class="volop-card__id">#{{ volunteering.id }}</span>
    </component>

    <div class="volop-card__body">
      <div v-if="mine && !summary" class="volop-card__owner-actions">
        <notice-message v-if="justRenewed" variant="primary">
          Thanks, we've confirmed this opportunity is still active. We'll check
          with you again on {{ nextCheckDate }}.
        </notice-message>
        <notice-message v-else-if="volunteering.expired" variant="warning">
          We've stopped showing this opportunity, but you can reactivate it.
        </notice-message>
        <notice-message v-else-if="confirmDue" variant="warning">
          We'll stop showing this opportunity on {{ expiryDate }} unless you
          tell us it's still active. Please click to let us know.
        </notice-message>
        <notice-message v-else-if="dateless">
          You created this opportunity, and it's active. We'll check with you on
          {{ nextCheckDate }} that it's still needed.
        </notice-message>
        <notice-message v-else> You created this opportunity. </notice-message>
        <div class="volop-card__owner-buttons">
          <b-button v-if="canConfirm" variant="primary" @click="renew">
            <v-icon icon="check" /> Yes, it's still active
          </b-button>
          <b-button variant="secondary" @click="expire">
            <v-icon icon="trash-alt" /> No, please remove it
          </b-button>
        </div>
      </div>

      <div v-if="summary" class="volop-card__summary">
        <p v-if="description" class="volop-card__desc">
          {{ description }}
        </p>
        <div class="volop-card__meta">
          <div v-if="volunteering.earliestDate" class="volop-card__meta-item">
            <v-icon icon="clock" />
            <span
              >{{ volunteering.earliestDate.string.start }} -
              {{ volunteering.earliestDate.string.end }}</span
            >
          </div>
          <div v-if="volunteering.location" class="volop-card__meta-item">
            <v-icon icon="map-marker-alt" />
            <span>{{ volunteering.location }}</span>
          </div>
        </div>
        <div class="volop-card__actions">
          <b-button
            variant="secondary"
            size="sm"
            :aria-label="
              'More info about ' +
              volunteering.title +
              ' volunteering opportunity'
            "
            @click="showOpportunityModal"
          >
            <v-icon icon="info-circle" /> More info
          </b-button>
        </div>
        <div
          v-if="volunteering.image"
          class="volop-card__image volop-card__image--full"
        >
          <OurUploadedImage
            v-if="volunteering?.image?.ouruid"
            :src="volunteering.image.ouruid"
            :modifiers="volunteering.image.externalmods"
            alt="Volunteering Opportunity Photo"
          />
          <b-img v-else lazy :src="volunteering.image.path" />
        </div>
      </div>

      <div v-else class="volop-card__detail">
        <div class="volop-card__content">
          <div class="volop-card__meta">
            <div v-if="volunteering.earliestDate" class="volop-card__meta-item">
              <v-icon icon="clock" />
              <span
                >{{ volunteering.earliestDate.string.start }} -
                {{ volunteering.earliestDate.string.end }}</span
              >
            </div>
            <div v-if="volunteering.location" class="volop-card__meta-item">
              <v-icon icon="map-marker-alt" />
              <span>{{ volunteering.location }}</span>
            </div>
          </div>
          <read-more
            v-if="description"
            :text="description"
            :max-chars="300"
            class="volop-card__description"
          />
          <div class="volop-card__actions">
            <b-button
              variant="secondary"
              :aria-label="
                'More info about ' +
                volunteering.title +
                ' volunteering opportunity'
              "
              @click="showOpportunityModal"
            >
              <v-icon icon="info-circle" /> More info
            </b-button>
          </div>
        </div>
        <div v-if="volunteering.image" class="volop-card__image">
          <OurUploadedImage
            v-if="volunteering?.image?.ouruid"
            :src="volunteering.image.ouruid"
            :modifiers="volunteering.image.externalmods"
            alt="Volunteering Opportunity Photo"
          />
          <b-img v-else lazy :src="volunteering.image.path" />
        </div>
      </div>
    </div>

    <VolunteerOpportunityModal
      v-if="showModal"
      :id="id"
      @hidden="showModal = false"
    />
  </div>
</template>
<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'
import NoticeMessage from './NoticeMessage'
import { useVolunteeringStore } from '~/stores/volunteering'
import { useUserStore } from '~/stores/user'
import { useGroupStore } from '~/stores/group'
import { useAuthStore } from '~/stores/auth'
import ReadMore from '~/components/ReadMore'
import { twem } from '~/composables/useTwem'
import { dateonly } from '~/composables/useTimeFormat'

const VolunteerOpportunityModal = defineAsyncComponent(
  () => import('./VolunteerOpportunityModal')
)

const props = defineProps({
  summary: {
    type: Boolean,
    required: true,
  },
  item: {
    // MT
    type: Object,
    required: false,
    default: null,
  },
  id: {
    type: Number,
    required: true,
  },
  filterGroup: {
    type: Number,
    required: false,
    default: null,
  },
  titleTag: {
    type: String,
    required: false,
    default: 'h3',
  },
})

const volunteeringStore = useVolunteeringStore()
const userStore = useUserStore()
const groupStore = useGroupStore()
const authStore = useAuthStore()
const myid = computed(() => authStore.user?.id)

// Mirrors App\Services\VolunteeringMaintenanceService in iznik-batch, which emails the
// owner to ask at ASK_AGE days and expires the opportunity at EXPIRE_AGE days. Keep in
// step with those constants, or we'll promise the owner a date we don't honour.
const ASK_AGE_DAYS = 24
const EXPIRE_AGE_DAYS = 31
const DAY_MS = 24 * 60 * 60 * 1000

const justRenewed = ref(false)
const showModal = ref(false)

// Initialize data from props
if (props.id) {
  const v = await volunteeringStore.fetch(props.id)
  if (v && v.userid) {
    // MT
    await userStore.fetch(v.userid)

    v.groups?.forEach(async (id) => {
      await groupStore.fetch(id)
    })
  }
}

// Computed properties
const volunteering = computed(() => {
  if (props.item) return props.item // MT
  const v = volunteeringStore?.byId(props.id)

  if (v) {
    if (!props.filterGroup) {
      return v
    }

    if (v.groups.includes(props.filterGroup)) {
      return v
    }
  }

  return null
})

const user = computed(() => {
  return userStore?.byId(volunteering.value?.userid)
})

const description = computed(() => {
  let desc = volunteering.value?.description
  desc = desc ? twem(desc) : ''
  desc = desc.trim()
  return desc
})

// Only dateless opportunities run on the renewal clock. Ones with dates expire when
// their last date passes, so asking their owner to confirm would be meaningless.
const dateless = computed(() => !volunteering.value?.dates?.length)

// The clock restarts on each renewal, and runs from creation until the first one.
const renewalClockStart = computed(() => {
  const when = new Date(
    volunteering.value?.renewed || volunteering.value?.added
  ).getTime()

  return isNaN(when) ? null : when
})

const confirmDue = computed(() => {
  if (!dateless.value || renewalClockStart.value === null) {
    return false
  }

  return Date.now() - renewalClockStart.value >= ASK_AGE_DAYS * DAY_MS
})

const nextCheckDate = computed(() =>
  renewalClockStart.value === null
    ? null
    : dateonly(renewalClockStart.value + ASK_AGE_DAYS * DAY_MS)
)

const expiryDate = computed(() =>
  renewalClockStart.value === null
    ? null
    : dateonly(renewalClockStart.value + EXPIRE_AGE_DAYS * DAY_MS)
)

// Nothing to confirm unless we're actually asking - offering the button when the
// opportunity is already current is what made renewing look like it did nothing.
const canConfirm = computed(
  () => !justRenewed.value && (confirmDue.value || volunteering.value?.expired)
)

const mine = computed(() => {
  return user.value?.id === myid.value
})

// Methods
function showOpportunityModal() {
  showModal.value = true
}

async function renew() {
  await volunteeringStore.renew(volunteering.value.id)
  justRenewed.value = true
}

async function expire() {
  justRenewed.value = false
  await volunteeringStore.expire(volunteering.value.id)
}
</script>
<style scoped lang="scss">
@import 'bootstrap/scss/functions';
@import 'bootstrap/scss/variables';
@import 'bootstrap/scss/mixins/_breakpoints';
@import 'assets/css/_color-vars.scss';

.volop-card {
  background: white;
  box-shadow: var(--shadow-sm);
  height: 100%;
  display: flex;
  flex-direction: column;
}

.volop-card__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  margin: 0;
  border-bottom: 1px solid $gray-200;
  font-size: 1.1rem;
  font-weight: 600;
}

.volop-card__title {
  flex: 1;
  min-width: 0;
  color: var(--color-link);
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.volop-card__id {
  color: $gray-500;
  font-size: 0.85rem;
  font-weight: normal;
  flex-shrink: 0;
  margin-left: 0.5rem;
}

.volop-card__body {
  padding: 1rem;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.volop-card__owner-actions {
  margin-bottom: 1rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid $gray-200;
}

.volop-card__owner-buttons {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  margin-top: 0.75rem;
}

.volop-card__desc {
  font-size: 0.9375rem;
  color: $gray-700;
  margin: 0 0 0.75rem 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.volop-card__meta {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.volop-card__meta-item {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  font-size: 0.9rem;
  color: $gray-600;

  svg {
    flex-shrink: 0;
    margin-top: 0.2rem;
    color: $gray-500;
  }
}

.volop-card__description {
  font-size: 0.9375rem;
  line-height: 1.6;
  color: $gray-700;
  margin-bottom: 1rem;
  flex: 1;

  :deep(.read-more-text) {
    display: -webkit-box;
    -webkit-line-clamp: 5;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
}

.volop-card__detail {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  flex: 1;

  @include media-breakpoint-up(md) {
    flex-direction: row;
  }
}

.volop-card__content {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.volop-card__actions {
  margin-top: auto;
  margin-bottom: 0;
  padding-top: 0.5rem;
}

.volop-card__image {
  flex-shrink: 0;

  :deep(img) {
    object-fit: cover;
    width: 100%;
    max-width: 200px;
  }

  &--full {
    :deep(img) {
      width: 100%;
      max-width: none;
    }
  }
}

.volop-card__summary {
  .volop-card__actions {
    text-align: center;
  }
}
</style>
