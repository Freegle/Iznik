export default defineNuxtConfig({
  extends: ['../iznik-nuxt3'],
  ssr: false,
  devtools: { enabled: true },

  app: {
    head: {
      viewport: 'width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no',
      meta: [
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'theme-color', content: '#1d6607' },
      ],
    },
  },

  // Add our mobile styles on top of inherited CSS
  css: [
    '~/assets/css/mobile.scss',
  ],
})
