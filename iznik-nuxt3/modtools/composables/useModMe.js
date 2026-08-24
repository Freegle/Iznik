import { useAuthStore } from '~/stores/auth'
import { useChatStore } from '@/stores/chat'
import { useMiscStore } from '@/stores/misc'
import { useModGroupStore } from '@/stores/modgroup'
import { useMe } from '~/composables/useMe'
import { useMobileStore } from '~/stores/mobile'

// Skip beep on the first checkWork() call after page load. The first call
// establishes the baseline work count; beeping at that point would interrupt
// background audio on iOS (podcasts, BBC Sounds) simply because the user opened
// the app. Beeps fire normally for any increase detected on subsequent calls.
let isFirstCheckWork = true

async function makebeep() {
  const sound = new Audio('/alert.wav')
  try {
    // Some browsers prevent us using play unless in response to a
    // user gesture, so catch any exception.
    await sound.play()
  } catch (e) {
    console.log('Failed to play beep', e.message)
  }
}

export function useModMe() {
  function hasPermission(perm) {
    const { me } = useMe()
    const perms = me.value ? me.value.permissions : null
    return perms && perms.includes(perm)
  }

  // Permissions. We have these as individual computed properties so they can be cached.
  const hasPermissionNewsletter = computed(() => {
    return hasPermission('Newsletter')
  })
  const hasPermissionSpamAdmin = computed(() => {
    return hasPermission('SpamAdmin')
  })
  const hasPermissionGiftAid = computed(() => {
    return hasPermission('GiftAid')
  })
  const hasPermissionClearance = computed(() => {
    return hasPermission('Clearance')
  })
  // Some pages are gated on team membership rather than a permission flag. Support and
  // Admin get in regardless, matching what the API allows.
  function onTeam(name) {
    const { me, supportOrAdmin } = useMe()
    if (supportOrAdmin.value) {
      return true
    }
    const teams = me.value ? me.value.teams : null
    return Boolean(teams && teams.includes(name))
  }
  const onPartnershipsTeam = computed(() => {
    return onTeam('Partnerships')
  })
  // Needed for some modtoolstasks but /mixins/me.js/myGroups() OK for most mod tasks as it is a copy of modgroup.list
  const myModGroups = computed(() => {
    // But do we need to do other stuff in myGroups() eg sorting?
    const modGroupStore = useModGroupStore()
    return Object.values(modGroupStore.list)
  })
  function myModGroup(groupid) {
    // console.log("modme.js myModGroup",groupid)
    const modGroupStore = useModGroupStore()
    return modGroupStore.get(groupid)
  }
  function amAModOn(groupid) {
    const authStore = useAuthStore()
    const member = authStore.member(groupid)
    return member === 'Moderator' || member === 'Owner'
  }
  // SEE WORK EXPLANATION IN useModMessages.js
  /* function deferCheckWork() {
    const miscStore = useMiscStore()
    if (miscStore.workTimer) {
      clearTimeout(miscStore.workTimer)
    }
    miscStore.workTimer = setTimeout(checkWork, 30000)
  } */
  function checkWorkDeferGetMessages() {
    const miscStore = useMiscStore()
    miscStore.deferGetMessages = true
    checkWork()
  }
  async function checkWork(force) {
    const now = new Date()
    // console.log('CHECKWORK modme',force, now.toISOString().substring(11))
    const authStore = useAuthStore()
    const chatStore = useChatStore()
    const miscStore = useMiscStore()
    const { fetchMe } = useMe()
    if (miscStore.workTimer) {
      clearTimeout(miscStore.workTimer)
    }

    // Skip refresh while modtools editing is in progress; beep is also skipped
    // when any modal is open (body overflow:hidden) to avoid iOS audio interruption.
    const bodyoverflow = document.body.style.overflow
    const oktocheck = !miscStore.modtoolsediting
    if (force || oktocheck) {
      // console.log('========================================')
      console.log(
        'CHECKWORK modme',
        force ?? '',
        now.toISOString().substring(11)
      )
      // const groupStore = useGroupStore()
      const modGroupStore = useModGroupStore()
      // console.log('CHECKWORK auth.groups',authStore.groups?.length,
      //  'groupStore.list',Object.keys(groupStore.list).length,
      //  'modGroupStore.list', Object.keys(modGroupStore.list).length)

      let currentTotal = 0
      if (authStore.work) currentTotal += authStore.work.total
      if (chatStore) currentTotal += Math.min(99, chatStore.unreadCount)
      // Refresh the work counts (the source of the blue/red pending-message badges).
      // Use forceServer so the result reflects state as of NOW: a mod action (Hold, then
      // Release moments later) must not piggyback on an earlier still-in-flight fetchMe()
      // and pick up stale counts, which left the badges showing the pre-action state until
      // a manual page refresh (Discourse #9951). fetchMe coalesces these forceServer calls
      // onto a single trailing refetch, so rapid actions don't flood /session.
      await fetchMe(true, true)
      await modGroupStore.getModGroups()

      const chatcount = chatStore ? Math.min(99, chatStore.unreadCount) : 0
      const work = authStore.work
      const totalCount = work?.total + chatcount

      const skipBeep = isFirstCheckWork
      isFirstCheckWork = false

      if (
        work &&
        totalCount > currentTotal &&
        !skipBeep &&
        bodyoverflow !== 'hidden' &&
        authStore.user &&
        (!authStore.user.settings ||
          !Object.keys(authStore.user.settings).includes('playbeep') ||
          authStore.user.settings.playbeep)
      ) {
        console.log('Beep as new work', currentTotal, totalCount)
        makebeep()
      }
      const title = totalCount > 0 ? `(${totalCount}) ModTools` : 'ModTools'
      document.title = title

      const mobileStore = useMobileStore()
      mobileStore.setBadgeCount(totalCount ?? 0)
    }
    miscStore.deferGetMessages = false
    miscStore.workTimer = setTimeout(checkWork, 30000)
  }

  function resetCheckWork() {
    isFirstCheckWork = true
  }

  return {
    hasPermissionNewsletter,
    hasPermissionSpamAdmin,
    hasPermissionGiftAid,
    hasPermissionClearance,
    onTeam,
    onPartnershipsTeam,
    myModGroups,
    myModGroup,
    amAModOn,
    checkWorkDeferGetMessages,
    checkWork,
    resetCheckWork,
  }
}
