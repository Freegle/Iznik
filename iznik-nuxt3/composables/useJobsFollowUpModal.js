import { useMiscStore } from '~/stores/misc'

/* Frequency cap for the jobs follow-up modal.
 * Returns helpers for checking and recording whether the modal has been
 * shown, with two guards:
 *   1. sessionStorage – cleared when the tab/window closes, so each new
 *      browsing session can see the modal once.
 *   2. miscStore timestamp – persisted to localStorage, so the 30-minute
 *      cross-tab cooldown survives soft reloads.
 */
export function useJobsFollowUpModal() {
  const miscStore = useMiscStore()

  /* Returns true when the modal is allowed to open.
   * False when already shown this session or within the last 30 minutes. */
  function shouldShowModal() {
    if (
      typeof sessionStorage !== 'undefined' &&
      sessionStorage.getItem('jobs_modal_shown_this_session')
    ) {
      return false
    }

    const lastShown = miscStore.get('last_jobs_modal_shown')
    if (lastShown && new Date().getTime() - lastShown < 30 * 60 * 1000) {
      return false
    }

    return true
  }

  /* Record that the modal was shown; blocks further shows for this session
   * and for 30 minutes cross-tab. */
  function recordShown() {
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.setItem('jobs_modal_shown_this_session', '1')
    }
    miscStore.set({
      key: 'last_jobs_modal_shown',
      value: new Date().getTime(),
    })
  }

  return { shouldShowModal, recordShown }
}
