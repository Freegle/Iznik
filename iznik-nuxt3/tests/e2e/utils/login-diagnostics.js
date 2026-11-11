/**
 * Say why a login modal did not close.
 *
 * Roughly one forced login in a run of CI did not complete although the form
 * had been filled and submitted, and the cause has not been found: the modal
 * stayed open for the whole wait and nothing in the log said what state it was
 * in. A retry would hide it, so this only records. When the wait times out it
 * writes one block describing the page, then fails as before.
 *
 * What it records, and what each answer rules in or out:
 *   - how many login modals exist and their data-instance ids: more than one,
 *     or an id other than the one typed into, means the modal was replaced;
 *   - whether the store holds a signed-in member: yes means the login worked
 *     and the modal is merely still drawn;
 *   - the email field's value and the password's length (never the password):
 *     empty means the form was reset before submit;
 *   - any error text the modal is showing: the server or the form refused;
 *   - the navigation entry's type and age: "reload" or a young page means the
 *     page was reloaded under the test.
 */
const { timeouts } = require('../config')

async function describeLoginState(page) {
  return page
    .evaluate(() => {
      const modals = [...document.querySelectorAll('#loginModal')]
      const instances = [...document.querySelectorAll('[data-instance]')].map(
        (el) => ({
          id: el.getAttribute('data-instance'),
          domId: el.id || null,
          visible: el.getBoundingClientRect().height > 0,
        })
      )

      const modal = modals[0]
      const email = modal?.querySelector(
        'input[type="email"], input[name="email"]'
      )
      const password = modal?.querySelector(
        'input[type="password"], input[name="password"]'
      )
      const errors = modal
        ? [
            ...modal.querySelectorAll(
              '.alert-danger, .invalid-feedback, .text-danger'
            ),
          ]
            .map((el) => (el.textContent || '').trim())
            .filter(Boolean)
        : []

      let store
      try {
        const pinia =
          document.querySelector('#__nuxt')?.__vue_app__?.config
            ?.globalProperties?.$pinia
        const auth = pinia?.state?.value?.auth
        store = {
          userId: auth?.user?.id ?? null,
          hasJwt: !!auth?.auth?.jwt,
          loginStateKnown: auth?.loginStateKnown ?? null,
          forceLogin: auth?.forceLogin ?? null,
        }
      } catch (e) {
        store = 'error: ' + e.message
      }

      return {
        url: location.href,
        loginModalElements: modals.length,
        modalCount: document.querySelectorAll('.modal-content').length,
        instances,
        store,
        email: email ? email.value : null,
        passwordLength: password ? password.value.length : null,
        errors,
        navigation: performance.getEntriesByType('navigation').map((n) => ({
          type: n.type,
          name: n.name,
          startTime: Math.round(n.startTime),
        })),
        pageAgeMs: Math.round(performance.now()),
      }
    })
    .catch((e) => ({ unreadable: e.message.split('\n')[0] }))
}

/**
 * Log the state of the login modal in one block.
 */
async function logLoginState(page, label) {
  const state = await describeLoginState(page)
  console.log(
    `[LOGIN-DIAG] ${label}: modal still open. ${JSON.stringify(state)}`
  )
  return state
}

/**
 * Wait for a login modal locator to hide. On timeout, record the page's state
 * and rethrow the original error: there is no retry and no second attempt.
 */
async function waitForLoginModalClosed(
  page,
  loginModal,
  label,
  timeout = timeouts.navigation.default
) {
  try {
    await loginModal.waitFor({ state: 'hidden', timeout })
  } catch (e) {
    await logLoginState(page, label)
    throw e
  }
}

module.exports = { describeLoginState, logLoginState, waitForLoginModalClosed }
