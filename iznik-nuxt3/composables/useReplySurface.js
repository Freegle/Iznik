// Which surface is the user replying from? Derived from the current route at the moment
// they commit the reply, and sent to the server as advisory provenance (replysource on the
// chat message POST; stored in rippling_reply_attribution.client_source). It cross-checks
// the server-derived rippling attribution - it never feeds it, since it's client-supplied.
//
// Values are deliberately coarse route-level surfaces, not component names:
//   email links land on /message/<id> with ?src= (kept verbatim) or bare ?reply=1 -> 'email';
//   a plain /message deep link -> 'message_page'; /browse with a search term -> 'search',
//   without -> 'browse'; anything else -> its first path segment (e.g. 'myposts', 'chitchat').
// The server sanitises to ^[a-z0-9][a-z0-9_-]{0,31}$ and drops anything else.

// Derive the surface from a route object. Split from the composable so it's trivially
// unit-testable with plain objects.
export function replySurfaceForRoute(route) {
  if (!route) return 'unknown'
  const path = route.path || ''
  const query = route.query || {}

  if (path.startsWith('/message/')) {
    if (query.src) {
      return String(query.src)
    }
    // ?reply=1 without src only comes from the email post-card click.
    return query.reply ? 'email' : 'message_page'
  }

  if (path.startsWith('/browse')) {
    return route.params?.term ? 'search' : 'browse'
  }

  const segment = path.split('/')[1]
  return segment || 'home'
}
