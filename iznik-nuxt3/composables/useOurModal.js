import { ref, onMounted, onUnmounted, nextTick, useRouter } from '#imports'

export function useOurModal(options = {}) {
  // autoShow (default true): show the modal as soon as it mounts. Most modals
  // are v-if-gated, so they only mount when they should be shown and rely on
  // this. Always-mounted modals that should open only on demand (e.g. via a
  // button calling show()) pass { autoShow: false } so they don't pop up on
  // page load.
  const { autoShow = true } = options
  const modal = ref()
  const isShown = ref(false)

  onMounted(async () => {
    await nextTick()
    if (autoShow) {
      show()
    }
  })

  function show() {
    if (!modal.value) {
      console.error('useOurModal show problem')
    } else {
      modal.value.show()
      isShown.value = true
    }
  }

  function hide() {
    modal.value?.hide()
    isShown.value = false
  }

  const unregisterNavigationGuard = useRouter().beforeEach((to, from, next) => {
    if (isShown.value) {
      console.log('GUARD NAV STOPPED')
    } else {
      // console.log('GUARD OK OK OK')
    }
    // console.log('Guard', to?.query)
    if (to?.query?.noguard) {
      // This is a special query parameter we add to skip the guard.  This is used when we are navigating from
      // within a modal where the guard would otherwise suppress the navigation because it thinks we are trying
      // to use the back button to close the modal.
      console.log('Special')
      next()
    } else if (isShown.value) {
      console.log('Hide')
      hide()
      next(false)
    } else {
      next()
    }
  })

  onUnmounted(() => {
    isShown.value = false
    unregisterNavigationGuard()
  })

  return { modal, show, hide }
}
