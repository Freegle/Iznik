export default defineNuxtConfig({
  modules: [],
  css: ['~/assets/css/main.css'],
  devtools: { enabled: false },
  ssr: true,
  nitro: {
    prerender: {
      crawlLinks: false,
      routes: [],
      ignore: ['/sitemap.xml'],
    },
  },
})
