// Google's sign-in library takes its settings once per page: a second
// initialize() replaces the first and Google warns that it "is called multiple
// times". One Tap and the login modal both need it, each with its own handler
// for the credential, so it is set up once here and a credential goes to
// whichever handler asked most recently, as the second initialize() used to
// arrange. FedCM is turned on for the prompt: without it One Tap uses the
// older status check, which Google says will stop working.
const initialised = new WeakMap()

export function initGoogleIdentity(clientId, handler) {
  const id = window.google.accounts.id
  const existing = initialised.get(id)

  if (existing) {
    existing.handler = handler
    return
  }

  const state = { handler }
  id.initialize({
    client_id: clientId,
    callback: (response) => state.handler(response),
    use_fedcm_for_prompt: true,
  })
  initialised.set(id, state)
}
