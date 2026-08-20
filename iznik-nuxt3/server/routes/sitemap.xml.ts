import { chunk, renderSitemapIndex, SITEMAP_CHUNK_SIZE } from '../utils/sitemap'

// The top-level sitemap is an index pointing at the child sitemaps, because the post
// list is far too big to sit alongside everything else in one file. Google reads this
// first (it's what robots.txt advertises) and then fetches each child.
//
// Until this change the sitemap listed only the 496 community pages and 26 static
// pages, and nothing at all told Google that an individual post existed.

export default defineEventHandler(async (event) => {
  const runtimeConfig = useRuntimeConfig()

  appendResponseHeader(event, 'Content-Type', 'text/xml')

  const site = runtimeConfig.public.USER_SITE
  const children = [site + '/sitemap-pages.xml']

  // Ask how many live posts there are, so we know how many post sitemaps to declare.
  // If the API is unreachable we still serve a valid index covering the pages rather
  // than failing the sitemap altogether.
  try {
    const rsp = await fetch(runtimeConfig.public.APIv2 + '/message/sitemap')
    const messages = await rsp.json()
    const chunks = chunk(messages || [], SITEMAP_CHUNK_SIZE)

    for (let i = 0; i < chunks.length; i++) {
      children.push(site + '/sitemap-posts/' + i)
    }
  } catch (e) {
    console.log('Failed to size post sitemaps', e)
  }

  return renderSitemapIndex(children, new Date().toISOString())
})
