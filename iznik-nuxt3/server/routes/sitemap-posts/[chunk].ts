import {
  chunk as chunkList,
  messageLinks,
  renderUrlset,
  SITEMAP_CHUNK_SIZE,
} from '../../utils/sitemap'

// One file per chunk of live posts, e.g. /sitemap-posts/0. The index at /sitemap.xml
// declares how many of these exist; each holds up to SITEMAP_CHUNK_SIZE post URLs
// with their lastmod.
//
// No .xml on the end because the route param has to be a whole path segment - what
// matters to a crawler is the content type, which we set below.

export default defineEventHandler(async (event) => {
  const runtimeConfig = useRuntimeConfig()

  // eslint-disable-next-line no-undef
  appendResponseHeader(event, 'Content-Type', 'text/xml')

  // eslint-disable-next-line no-undef
  const index = parseInt(getRouterParam(event, 'chunk') || '0', 10)

  let messages = []

  try {
    const rsp = await fetch(runtimeConfig.public.APIv2 + '/message/sitemap')
    messages = await rsp.json()
  } catch (e) {
    console.log('Failed to fetch posts for sitemap', e)
  }

  const chunks = chunkList(messages || [], SITEMAP_CHUNK_SIZE)
  const wanted = Number.isNaN(index) ? [] : chunks[index] || []

  return renderUrlset(messageLinks(wanted), runtimeConfig.public.USER_SITE)
})
