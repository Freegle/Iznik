// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2024-11-01',
  devtools: { enabled: true },

  modules: [
    '@pinia/nuxt',
    '@bootstrap-vue-next/nuxt',
  ],

  css: [
    '~/assets/css/main.scss',
  ],

  app: {
    head: {
      title: 'Freegle Status',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
      link: [
        { rel: 'icon', type: 'image/png', href: 'https://www.ilovefreegle.org/icon.png' },
      ],
    },
  },

  // Server-side rendering for status page
  ssr: true,

  // Nitro server configuration
  nitro: {
    // Allow Docker socket access
    experimental: {
      tasks: true,
    },
  },

  // Runtime config for environment variables
  runtimeConfig: {
    // Server-only config
    dockerHost: process.env.DOCKER_HOST || 'unix:///var/run/docker.sock',
    // Public config (exposed to client)
    public: {
      apiBase: '/api',
    },
  },
})
