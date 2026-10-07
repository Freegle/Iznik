import { ref, computed, watch } from 'vue'
import { useAdminsStore } from '~/stores/admins'
import { useMe } from '~/composables/useMe'
import {
  isSingleEmail,
  apiMessage,
} from '~/modtools/composables/useAdminContent'

// Sending a test of an ADMIN to one address, shared by the Create tab and pending ADMINs.
//
// content() returns the fields that decide what the email looks like; validate() returns a
// message if the content can't be sent yet, or null. The test is "current" while the content
// is unchanged since it was sent.
export function useAdminTestSend(content, validate) {
  const adminsStore = useAdminsStore()
  const { me } = useMe()

  const testEmail = ref(me.value?.email || '')
  const testing = ref(false)
  const testError = ref(null)
  const testtoken = ref(null)
  const testedKey = ref(null)
  const testedTo = ref(null)

  // Default the test address to the moderator's own, once we know it.
  watch(
    () => me.value?.email,
    (email) => {
      if (email && !testEmail.value) {
        testEmail.value = email
      }
    }
  )

  const contentKey = computed(() => JSON.stringify(content()))

  const tested = computed(
    () => !!testtoken.value && testedKey.value === contentKey.value
  )

  async function sendTest() {
    testError.value = await validate()
    if (testError.value) {
      return
    }

    if (!isSingleEmail(testEmail.value)) {
      testError.value = 'Please give one email address to send the test to.'
      return
    }

    const params = content()
    const key = contentKey.value
    const email = testEmail.value.trim()

    testing.value = true
    try {
      testtoken.value = await adminsStore.test({ ...params, email })
      testedKey.value = key
      testedTo.value = email
    } catch (e) {
      testError.value = apiMessage(
        e,
        "Couldn't send the test - please try again."
      )
    } finally {
      testing.value = false
    }
  }

  // After the ADMIN is created: one test, one ADMIN.
  function clearTest() {
    testtoken.value = null
    testedKey.value = null
  }

  return {
    testEmail,
    testing,
    testError,
    testtoken,
    testedKey,
    testedTo,
    tested,
    sendTest,
    clearTest,
  }
}
