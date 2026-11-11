// An address that matched no page. Nuxt's router raises it with statusCode 404 and the message
// "Page not found: <path>". A page that throws its own not-found error (a message or group that
// does not exist) has a 404 too, with its own message, and is not this: that one still needs a
// person to look at it.
export function isPageNotFound(error) {
  return (
    Number(error?.statusCode) === 404 &&
    /^Page not found: /.test(
      String(error?.message ?? error?.statusMessage ?? '')
    )
  )
}
