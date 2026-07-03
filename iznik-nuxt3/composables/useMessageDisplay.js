import { computed, watch } from 'vue'
import { useMessageStore } from '~/stores/message'
import { useAuthStore } from '~/stores/auth'
import { useUserStore } from '~/stores/user'
import { useGroupStore } from '~/stores/group'
import { useNearbyStore } from '~/stores/nearby'
import { useMe } from '~/composables/useMe'
import {
  timeagoShort,
  timeagoMedium,
  dateshortNoYear,
  timeago,
} from '~/composables/useTimeFormat'
import { milesAway } from '~/composables/useDistance'
import { buildKeywordRegex } from '~/composables/useKeywordRegex'

/**
 * Shared composable for message display logic used by both
 * MessageExpandedMobile and MessageSummaryMobile
 */
export function useMessageDisplay(messageId) {
  const messageStore = useMessageStore()
  const authStore = useAuthStore()
  const userStore = useUserStore()
  const groupStore = useGroupStore()
  const nearbyStore = useNearbyStore()
  const { me } = useMe()

  const message = computed(() =>
    messageStore?.byId(messageId.value || messageId)
  )

  // The browse feed carries a server-computed distance per post (blurred great-circle
  // miles from the viewer) which the feed also sorts and filters on. Prefer it for the
  // badge so the distance shown always matches the feed's ordering and the distance
  // slider. It lives in the nearby store keyed by id (the full message record fetched
  // here has no such field), so look it up by id; null when this message isn't a feed
  // post (search, My Posts, ModTools ...), in which case we fall back to a client calc.
  const serverDistanceMiles = computed(() => {
    const id = Number(messageId?.value ?? messageId)
    if (!Number.isFinite(id)) {
      return null
    }
    const d = nearbyStore.distanceById.get(id)
    return Number.isFinite(d) ? d : null
  })

  // Whether this post is pinned (a paid bulk-offer clearance floated to the top of the
  // feed). Like the distance, `pinned` is a feed-only flag looked up by id from the
  // nearby store; false off the feed (search, My Posts ...).
  const isPinned = computed(() => {
    const id = Number(messageId?.value ?? messageId)
    return Number.isFinite(id) && nearbyStore.pinnedIds.has(id)
  })

  // Get the group for this message (first group it's posted to)
  const messageGroup = computed(() => {
    const groupId = message.value?.groups?.[0]?.groupid
    return groupId ? groupStore.get(groupId) : null
  })

  // Fetch poster info when message changes
  watch(
    () => message.value?.fromuser,
    (userId) => {
      if (userId) {
        userStore.fetch(userId)
      }
    },
    { immediate: true }
  )

  const poster = computed(() => {
    return message.value?.fromuser
      ? userStore?.byId(message.value?.fromuser)
      : null
  })

  const posterName = computed(() => {
    if (!poster.value) return null
    // Use first name only for compact display
    const displayname = poster.value.displayname || ''
    return displayname.split(' ')[0] || displayname
  })

  const posterProfileUrl = computed(() => {
    return poster.value ? `/profile/${poster.value.id}` : null
  })

  const strippedSubject = computed(() => {
    const subject = message.value?.subject || ''
    // Strip the keyword prefix using the group's configured keywords (+ common
    // variants). Shared with the ModTools subject-colour check via buildKeywordRegex.
    const regex = buildKeywordRegex(messageGroup.value?.settings?.keywords)
    return subject.replace(regex, '')
  })

  // Parse well-formatted subjects like "Item name (Location EH17)"
  const subjectItemName = computed(() => {
    const subject = strippedSubject.value
    // Match location in parentheses at end, e.g. "(Gracemount EH17)"
    const match = subject.match(/^(.+?)\s*\([^)]+\)\s*$/)
    return match ? match[1].trim() : subject
  })

  const subjectLocation = computed(() => {
    const subject = strippedSubject.value
    // Extract location from parentheses at end
    const match = subject.match(/\(([^)]+)\)\s*$/)
    return match ? match[1].trim() : null
  })

  const fromme = computed(() => {
    return message.value?.fromuser === authStore.user?.id
  })

  const gotAttachments = computed(() => {
    return message.value?.attachments?.length > 0
  })

  const sampleImage = computed(() => {
    // Sample image from similar posts, returned by v2 API when message has no photos
    return message.value?.sampleimage || null
  })

  const attachmentCount = computed(() => {
    return message.value?.attachments?.length || 0
  })

  const timeAgo = computed(() => {
    // Use group arrival time (like MessageHistory does) for accurate autopost times
    const timestamp =
      message.value?.groups?.[0]?.arrival ||
      message.value?.arrival ||
      message.value?.date
    if (!timestamp) return ''
    return timeagoShort(timestamp)
  })

  const fullTimeAgo = computed(() => {
    // Full time description for tooltip
    const timestamp =
      message.value?.groups?.[0]?.arrival ||
      message.value?.arrival ||
      message.value?.date
    if (!timestamp) return ''
    return `Posted ${timeago(timestamp)}`
  })

  const timeAgoExpanded = computed(() => {
    // Medium time format for lg+ screens: "2 hours", "3 days"
    const timestamp =
      message.value?.groups?.[0]?.arrival ||
      message.value?.arrival ||
      message.value?.date
    if (!timestamp) return ''
    return timeagoMedium(timestamp)
  })

  const timeAgoExpandedDisplay = computed(() => {
    // Full display string: "2 hours ago", or "just now" (not "just now ago")
    const t = timeAgoExpanded.value
    return t === 'just now' ? t : t ? `${t} ago` : ''
  })

  const distanceText = computed(() => {
    const server = serverDistanceMiles.value
    if (server != null) {
      return server < 1 ? '<1mi' : `${Math.round(server)}mi`
    }
    if (!me.value?.lat || !message.value?.lat) {
      return message.value?.area || null
    }
    const miles = milesAway(
      me.value.lat,
      me.value.lng,
      message.value.lat,
      message.value.lng
    )
    if (miles < 1) {
      return '<1mi'
    }
    return `${Math.round(miles)}mi`
  })

  const distanceTextExpanded = computed(() => {
    const server = serverDistanceMiles.value
    if (server != null) {
      if (server < 1) {
        return 'less than 1 mile'
      }
      const rounded = Math.round(server)
      return rounded === 1 ? '1 mile' : `${rounded} miles`
    }
    if (!me.value?.lat || !message.value?.lat) {
      return message.value?.area || null
    }
    const miles = milesAway(
      me.value.lat,
      me.value.lng,
      message.value.lat,
      message.value.lng
    )
    if (miles < 1) {
      return 'less than 1 mile'
    }
    const rounded = Math.round(miles)
    return rounded === 1 ? '1 mile' : `${rounded} miles`
  })

  const replyCount = computed(() => {
    return message.value?.replies?.length || 0
  })

  const replyTooltip = computed(() => {
    const count = replyCount.value
    if (count === 0) return 'No replies yet'
    if (count === 1) return '1 person has replied'
    return `${count} people have replied`
  })

  const isOffer = computed(() => {
    return message.value?.type === 'Offer'
  })

  const isWanted = computed(() => {
    return message.value?.type === 'Wanted'
  })

  const formattedDeadline = computed(() => {
    if (!message.value?.deadline) return ''
    return dateshortNoYear(message.value.deadline)
  })

  const deadlineTooltip = computed(() => {
    return isOffer.value
      ? 'Only available until this date'
      : 'Only needed before this date'
  })

  const successfulText = computed(() => {
    return isOffer.value ? 'Taken' : 'Received'
  })

  const placeholderClass = computed(() => {
    return message.value?.type === 'Offer'
      ? 'offer-gradient'
      : 'wanted-gradient'
  })

  const categoryIcon = computed(() => {
    // Map item categories to icons - simplified for now
    const type = message.value?.type
    if (type === 'Offer') return 'gift'
    if (type === 'Wanted') return 'search'
    return 'gift'
  })

  return {
    message,
    strippedSubject,
    subjectItemName,
    subjectLocation,
    fromme,
    gotAttachments,
    sampleImage,
    attachmentCount,
    timeAgo,
    timeAgoExpanded,
    timeAgoExpandedDisplay,
    fullTimeAgo,
    distanceText,
    distanceTextExpanded,
    isPinned,
    replyCount,
    replyTooltip,
    isOffer,
    isWanted,
    formattedDeadline,
    deadlineTooltip,
    successfulText,
    placeholderClass,
    categoryIcon,
    poster,
    posterName,
    posterProfileUrl,
  }
}
