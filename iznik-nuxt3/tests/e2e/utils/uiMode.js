// The chat shell is Freegle's default front door. The end to end specs describe the
// classic pages, so their browser contexts carry the classic choice; the chat shell
// specs set the same cookie to "chat".
function classicModeCookie(baseURL) {
  return {
    name: 'freegle-ui-mode',
    value: 'classic',
    domain: new URL(baseURL).hostname,
    path: '/',
    expires: -1,
    httpOnly: false,
    secure: false,
    sameSite: 'Lax',
  }
}

function defaultBaseURL() {
  return process.env.TEST_BASE_URL || 'http://freegle-prod-local.localhost'
}

module.exports = { classicModeCookie, defaultBaseURL }
