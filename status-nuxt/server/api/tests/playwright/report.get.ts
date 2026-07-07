export default defineEventHandler(async (event) => {
  // Redirect to Playwright's built-in report server
  return sendRedirect(event, 'http://localhost:9323', 302)
})
