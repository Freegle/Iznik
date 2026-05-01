<template>
  <div>
    <client-only>
      <ScrollToTop />
      <ModHelpFeedback />
      <b-tabs v-model="tabIndex" content-class="mt-3" card>
        <b-tab active>
          <template #title>
            <h4 class="header--size4 ms-2 me-2">
              Feedback <span v-if="members.length">({{ members.length }})</span>
            </h4>
          </template>
          <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
            <ModGroupSelect
              v-model="groupid"
              modonly
              all
              remember="membersfeedback"
            />
            <b-form-select v-model="filter">
              <option value="Comments">With Comments</option>
              <option value="Happy">Happy</option>
              <option value="Unhappy">Unhappy</option>
              <option value="Fine">Fine</option>
            </b-form-select>
            <b-form-checkbox v-model="showExpired">
              Show expired
            </b-form-checkbox>
            <b-button variant="white" @click="markAll">
              Mark all as seen
            </b-button>
          </div>
          <b-card v-if="happinessData.length" variant="white" class="mt-1 happiness-chart-card">
            <b-card-text>
              <p class="text-center">
                This is what people have said over the last year<span
                  v-if="!groupid"
                >
                  across all of Freegle</span
                >.
              </p>
              <div class="d-flex flex-wrap justify-content-between">
                <GChart
                  type="PieChart"
                  :data="happinessData"
                  :options="happinessOptions"
                />
                <GChart
                  type="BarChart"
                  :data="happinessData"
                  :options="happinessOptions"
                />
              </div>
            </b-card-text>
          </b-card>

          <NoticeMessage v-if="!members.length && !busy" class="mt-2">
            There are no items to show at the moment.
          </NoticeMessage>
          <div
            v-for="item in visibleItems"
            :key="'memberlist-' + item.id"
            class="p-0 mt-2"
          >
            <ModMemberHappiness
              v-if="item.type === 'Member'"
              :id="item.object.id"
            />
          </div>
        </b-tab>

        <b-tab>
          <template #title>
            <h4 class="header--size4 ms-2 me-2">
              Thumbs Up/Down
              <span v-if="ratings.length">({{ ratings.length }})</span>
            </h4>
          </template>

          <div
            v-for="item in ratings"
            :key="'ratinglist-' + item.id"
            class="p-0 mt-2"
          >
            <ModMemberRating :ratingid="item.id" class="mt-2" />
          </div>
        </b-tab>
      </b-tabs>
      <infinite-loading
        :distance="distance"
        :identifier="bump"
        @infinite="loadMore"
      >
        <template #spinner>
          <Spinner :size="50" />
        </template>
      </infinite-loading>
    </client-only>
  </div>
</template>
<script setup>
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import dayjs from 'dayjs'
import { GChart } from 'vue-google-charts'
import { useNuxtApp } from '#app'
import { setupModMembers } from '~/composables/useModMembers'
import { useUserStore } from '~/stores/user'
import { useMemberStore } from '@/stores/member'
import { useMe } from '~/composables/useMe'

const { $api } = useNuxtApp()

const memberStore = useMemberStore()
const userStore = useUserStore()
const {
  busy,
  context,
  groupid,
  limit,
  show,
  collection,
  distance,
  members,
  filter,
  loadMore: baseLoadMore,
} = setupModMembers(true)
collection.value = 'Happiness'
limit.value = 1000 // Get everything (probably) so that the ratings and feedback are interleaved.
const { fetchMe } = useMe()

// Data
const tabIndex = ref(0)
const happinessData = ref([])
const bump = ref(0)
const showExpired = ref(true)
const happinessOptions = {
  chartArea: {
    width: '80%',
    height: '80%',
  },
  pieSliceBorderColor: 'darkgrey',
  colors: ['green', '#f8f9fa', 'orange'],
  slices2: {
    1: { offset: 0.2 },
    2: { offset: 0.2 },
    3: { offset: 0.2 },
  },
}

// Computed
const ratings = computed(() => {
  return memberStore.ratings
})

const sortedItems = computed(() => {
  const objs = []

  members.value.forEach((m) => {
    // Pre-filter: only include items matching the current filter so that
    // show.value counts visible items rather than all members. This prevents
    // invisible empty rows from accumulating and causing the scroll to jump
    // two rows at a time.
    if (filterMatch(m)) {
      objs.push({
        type: 'Member',
        object: m,
        timestamp: m.timestamp,
        id: 'member-' + m.id,
      })
    }
  })

  objs.sort(function (a, b) {
    return new Date(b.timestamp).getTime() - new Date(a.timestamp).getTime()
  })

  return objs
})

const visibleItems = computed(() => {
  return sortedItems.value.slice(0, show.value)
})

// Custom loadMore: use filtered item count as completion threshold so the
// infinite loader doesn't cycle through non-matching items between visible ones.
async function loadMore($state) {
  if (members.value.length === 0) {
    // Initial fetch not done yet — delegate to the generic loader which
    // triggers the API call and sets show.value to 1.
    await baseLoadMore($state)
  } else if (show.value < sortedItems.value.length) {
    show.value++
    $state.loaded()
  } else {
    // All currently-filtered items are visible. Try fetching the next API
    // page (handles the rare case where there are >1000 happiness items).
    // baseLoadMore will call $state.complete() if no new members arrive.
    await baseLoadMore($state)
  }
}

// Watchers
watch(filter, () => {
  context.value = null
  show.value = 0
  memberStore.clear()
  bump.value++
})

watch(groupid, () => {
  getHappiness()
  bump.value++
})

watch(tabIndex, () => {
  console.log('tabIndex changed', show.value)
  bump.value++
})

watch(showExpired, () => {
  show.value = 0
  bump.value++
})

// Methods
async function getHappiness() {
  const start = dayjs().subtract(1, 'year').toDate().toISOString()
  const ret = await $api.dashboard.fetch({
    components: ['Happiness'],
    start,
    end: new Date().toISOString(),
    allgroups: !groupid.value,
    group: groupid.value > 0 ? groupid.value : null,
    systemwide: groupid.value < 0,
  })

  if (ret.Happiness) {
    happinessData.value = [['Feedback', 'Count']]
    ret.Happiness.forEach((h) => {
      happinessData.value.push([h.happiness, h.count])
    })
  }
}

function filterMatch(member) {
  // Optionally hide posts with non-successful outcomes (expired, withdrawn, etc.)
  if (!showExpired.value) {
    const outcome = member.outcome
    if (outcome && outcome !== 'Taken' && outcome !== 'Received') {
      return false
    }
  }

  const val = member.happiness

  if (!filter.value || filter.value === '0') {
    return true
  }

  if (filter.value === 'Comments') {
    const comment = member.comments
      ? ('' + member.comments).replace(/[\n\r]+/g, '').trim()
      : ''

    if (comment.length) {
      return true
    }
  } else {
    if (filter.value === val) {
      return true
    }

    if (filter.value === 'Fine' && !val) {
      return true
    }
  }

  return false
}

async function markAll() {
  await memberStore.clear()

  const params = {
    groupid: groupid.value,
    collection: collection.value,
    modtools: true,
    summary: false,
    context: null,
    limit: 1000,
  }
  console.log('markAll', params)

  await memberStore.fetchMembers(params)
  console.log('markAll received')

  nextTick(() => {
    members.value.forEach(async (member) => {
      if (!member.reviewed) {
        const reviewParams = {
          userid: member.fromuser,
          groupid: member.groupid,
          happinessid: member.id,
        }
        await memberStore.happinessReviewed(reviewParams)
      }
    })
    ratings.value.forEach(async (rating) => {
      if (rating.reviewrequired) {
        await userStore.ratingReviewed({
          id: rating.id,
        })
      }
    })
  })
  fetchMe(true)
}

// Lifecycle
onMounted(async () => {
  filter.value = 'Comments'
  await getHappiness()
})
</script>
<style scoped>
select {
  max-width: 300px;
}

.happiness-chart-card {
  /* Layout containment prevents layout shift when filtering expired posts changes
     the items list height below. The browser optimizes reflow to just this card. */
  contain: layout;
}
</style>
