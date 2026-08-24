// Mock for isomorphic-dompurify.
// The real library pulls in jsdom for server-side sanitisation, which does not
// play well with the happy-dom test environment. Tests only need a stable
// identity sanitize; the real sanitisation runs in the browser.
export default {
  sanitize: (html) => html,
}
