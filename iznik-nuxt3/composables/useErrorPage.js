// A 404 that reaches the error page. Nuxt gives it statusCode 404 whether a page route did not
// match or a page threw a not-found error itself.
export function isPageNotFound(error) {
  return Number(error?.statusCode) === 404
}
