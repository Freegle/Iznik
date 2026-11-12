// What to say when Google's sign-in button never appears in the login box.
//
// Seen in Sentry (7750648574) in three shapes, which need different answers:
// - script_not_loaded: the container is there but Google's script never arrived (an ad blocker,
//   a browser inside another app). The member is told Google sign in is not loading, and nothing
//   of ours can change it, so it is counted in the logs and not raised as an error.
// - container_missing: the script loaded and the box says it is open, but the button's container
//   is not in the page. That is ours to explain, so it carries what the page looked like.
// - not_drawn: script and container are both there and Google drew nothing.
export function googleButtonFailureKind({ scriptLoaded, containerPresent }) {
  if (!containerPresent) {
    return 'container_missing'
  }

  return scriptLoaded ? 'not_drawn' : 'script_not_loaded'
}

// A browser that runs inside another app (Google app, Facebook, Instagram, Line, WeChat, an
// Android WebView), where Google blocks or never loads its sign-in script.
export function inAppBrowser(userAgent = '') {
  return /GSA\/|FBAN|FBAV|Instagram|Line\/|MicroMessenger|; wv\)/.test(
    userAgent
  )
}
