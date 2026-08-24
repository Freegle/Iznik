import { staticLinks, groupLinks, renderUrlset } from '../utils/sitemap'

// The stable part of the site: landing and policy pages, plus one page per community.

export default defineEventHandler(async (event) => {
  const runtimeConfig = useRuntimeConfig()

  appendResponseHeader(event, 'Content-Type', 'text/xml')

  const links = staticLinks()

  try {
    const rsp = await fetch(runtimeConfig.public.APIv2 + '/group')
    const groups = await rsp.json()

    links.push(...groupLinks(groups))
  } catch (e) {
    console.log('Failed to fetch groups for sitemap', e)
  }

  return renderUrlset(links, runtimeConfig.public.USER_SITE)
})
