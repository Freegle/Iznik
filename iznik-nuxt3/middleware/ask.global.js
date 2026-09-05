// The WANTED flow lived at /find until Aug 2026 and is now /ask.
//
// Netlify's _redirects and nuxt.config's routeRules both send a 301 for a
// request that reaches the server, which covers old emails, bookmarks and app
// shortcuts. Neither fires for navigation that happens inside the running app -
// notably /engage?action=find, where the win-back emails hand engage.vue an
// action that it turns into router.push('/find'). Those emails sit in inboxes
// for months, so this middleware is permanent too.
//
// It also carries the app: the Capacitor build is a static export served from
// the APK (capacitor.config.ts webDir '.output/public'), so a legacy deep link
// falls through to index.html and is resolved entirely client-side, with no
// server and no _redirects file in sight.
//
// replace: true matters - without it, Back from /ask lands on /find, which
// redirects forward again and traps the member.
export default defineNuxtRouteMiddleware((to) => {
  if (to.path !== '/find' && !to.path.startsWith('/find/')) {
    return
  }

  return navigateTo(
    {
      path: '/ask' + to.path.slice('/find'.length),
      query: to.query,
      hash: to.hash,
    },
    { redirectCode: 301, replace: true }
  )
})
