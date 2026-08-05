// Handles /one-click-unsubscribe/{uid}/{key}, the List-Unsubscribe URL that bulk mail used
// to carry. Current mail points List-Unsubscribe at the apiv2 endpoint instead, but mail
// already in someone's inbox keeps the header it was sent with, so this route has to keep
// working - and keep working safely - for as long as that mail exists.
//
// It must be handled entirely here. Falling through to the page underneath was how a single
// unauthenticated POST came to delete an account: that page's setup script runs during SSR
// and calls authStore.forget(), so Gmail's one-click POST - a machine action with nobody
// confirming anything - removed the member outright.
export default defineEventHandler(async (event) => {
  const to = event?.node?.req?.url

  const match = to?.match(/^\/one-click-unsubscribe\/([^/?#]+)\/([^/?#]+)/)

  // If no match, return
  if (!match) return

  const uid = match[1]
  const key = match[2]

  // One click unsubscribe should only be called with a POST, because we add the List-Unsubscribe-Post header.  But not
  // all mail clients support that, and therefore it might be called with a GET.  In that case we don't want to action it,
  // to avoid virus-scanners triggering it (which is why List-Unsubscribe-Post was invented).  So we redirect to the
  // usual unsubscribe page, which will require further action.
  if (event.node.req.method !== 'POST') {
    return sendRedirect(event, '/unsubscribe?u=' + uid + '&k=' + key, 302)
  }

  // A POST is RFC 8058 one-click. This URL carries no category, so it means "stop emailing
  // me": turn off every kind of email, but leave the account alone. Deleting an account is
  // not something to infer from an unsubscribe click.
  const runtimeConfig = useRuntimeConfig()
  const apiV2 = String(runtimeConfig.public.APIv2).replace(/\/$/, '')
  const params = new URLSearchParams({ u: uid, k: key, t: 'all' })

  try {
    // eslint-disable-next-line no-undef
    await $fetch(apiV2 + '/user/unsubscribe?' + params.toString(), {
      method: 'POST',
    })
  } catch (e) {
    // A bad key gets a 403 from the API. Say so rather than claiming success, so a mail
    // client doesn't tell someone they are unsubscribed when they are not.
    const status = e?.response?.status || 500
    setResponseStatus(event, status)
    return { ret: status, status: 'Failed' }
  }

  return { ret: 0, status: 'Success' }
})
