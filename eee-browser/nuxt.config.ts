export default defineNuxtConfig({
  modules: [],
  css: ['~/assets/css/main.css'],
  devtools: { enabled: false },
  ssr: true,
  postcss: {
    plugins: {
      tailwindcss: {},
      autoprefixer: {},
    },
  },
  nitro: {
    externals: {
      external: ['better-sqlite3'],
    },
    prerender: {
      crawlLinks: false,
      routes: [],
      ignore: ['/sitemap.xml'],
    },
  },
})
