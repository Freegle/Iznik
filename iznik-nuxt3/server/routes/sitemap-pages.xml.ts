import { staticLinks, renderUrlset } from '../utils/sitemap'

// The stable part of the site: landing and policy pages.

export default defineEventHandler(async (event) => {
  const runtimeConfig = useRuntimeConfig()

  appendResponseHeader(event, 'Content-Type', 'text/xml')

  const links = staticLinks()

  return renderUrlset(links, runtimeConfig.public.USER_SITE)
})
