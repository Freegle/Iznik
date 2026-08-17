import { createConfigForNuxt } from '@nuxt/eslint-config/flat'
import prettierRecommended from 'eslint-plugin-prettier/recommended'

// Flat config replacing the old .eslintrc.js (@nuxtjs shareable config +
// plugin:prettier/recommended). @nuxt/eslint-config brings the Vue,
// TypeScript, import and Nuxt-specific rule sets; prettier runs as a rule and
// its config disables conflicting stylistic rules.
export default createConfigForNuxt({
  features: {
    // Stylistic formatting is prettier's job.
    stylistic: false,
  },
})
  .append(prettierRecommended)
  .append(
    {
      ignores: [
        'android/**',
        'ios/**',
        'capacitor.config.ts',
        'coverage/**',
        'monocart-report/**',
        'playwright-report/**',
        'test-results/**',
        'public/js/**',
      ],
    },
    {
      languageOptions: {
        globals: {
          process: 'readonly',
          defineAsyncComponent: 'readonly',
          defineExpose: 'readonly',
          defineProps: 'readonly',
          definePageMeta: 'readonly',
          useRuntimeConfig: 'readonly',
          defineNuxtConfig: 'readonly',
          defineNuxtPlugin: 'readonly',
          defineNitroPlugin: 'readonly',
          defineEmits: 'readonly',
          defineNuxtRouteMiddleware: 'readonly',
          defineEventHandler: 'readonly',
          getCurrentInstance: 'readonly',
          useAsyncData: 'readonly',
          useLazyAsyncData: 'readonly',
          useState: 'readonly',
          // Test-only: tests/unit/setup.ts exposes this to reset keyed useState between cases.
          clearNuxtState: 'readonly',
          createApp: 'readonly',
          useNuxtApp: 'readonly',
          useLoadingIndicator: 'readonly',
          useRuntimeHook: 'readonly',
          useHead: 'readonly',
          useRoute: 'readonly',
          useRouter: 'readonly',
          ref: 'readonly',
          computed: 'readonly',
          watch: 'readonly',
          onMounted: 'readonly',
          onBeforeUnmount: 'readonly',
          usePinia: 'readonly',
          piniaPluginPersistedstate: 'readonly',
          navigateTo: 'readonly',
          sendRedirect: 'readonly',
          setResponseStatus: 'readonly',
        },
      },
      rules: {
        'no-console': 'off',

        // Rule-strictness parity with the previous @nuxtjs (eslint 8) setup.
        // eslint 9 changed no-unused-vars' default to caughtErrors: 'all' and
        // added new rules; tightening beyond the old baseline is follow-up
        // work, not part of the toolchain migration.
        // The config wires unused-vars through the typescript-eslint variant
        // (which also handles plain JS); keep core off to avoid double reports.
        'no-unused-vars': 'off',
        '@typescript-eslint/no-unused-vars': [
          'error',
          {
            args: 'none',
            caughtErrors: 'none',
            ignoreRestSiblings: true,
          },
        ],
        'no-empty': ['error', { allowEmptyCatch: true }],
        'preserve-caught-error': 'off',

        // In Nuxt, useRoute/useRouter must come from #imports (or be
        // auto-imported), not from vue-router directly. Direct vue-router
        // imports can return undefined during SSR hydration because they
        // bypass Nuxt's context injection.
        'no-restricted-imports': [
          'error',
          {
            paths: [
              {
                name: 'vue-router',
                importNames: ['useRoute', 'useRouter'],
                message:
                  'Import useRoute/useRouter from #imports instead of vue-router. Direct vue-router imports can return undefined during SSR.',
              },
            ],
          },
        ],

        // Regex lookbehind ((?<=...) / (?<!...)) is unsupported by Safari
        // before 16.4. An unsupported regex *literal* fails to parse, which
        // kills the whole chunk and white-screens the page - not just the
        // check using it. Rewrite as (?:^|\D)-style alternation instead.
        'no-restricted-syntax': [
          'error',
          {
            selector: 'Literal[regex.pattern=/\\(\\?<[=!]/]',
            message:
              'Regex lookbehind is unsupported by Safari < 16.4 and breaks the whole chunk at parse time. Rewrite without it (e.g. (?<!\\d) becomes (?:^|\\D)).',
          },
        ],

        // We have a lot of legacy code which doesn't define emits.
        'vue/require-explicit-emits': 'off',
        // no-v-model-argument rule is broken for Vue3, which requires that
        // syntax for .sync.
        'vue/no-v-model-argument': 'off',
        'vue/multi-word-component-names': [
          'error',
          {
            // error page for custom errors triggers this, so ignore it.
            ignores: ['error'],
          },
        ],
        // (the old vue/script-setup-uses-vars rule was removed in
        // eslint-plugin-vue 10 — script-setup vars are handled natively)
      },
    },
    {
      // Declaration files import types purely for module augmentation and
      // mirror runtime signatures that are untyped (options?: any).
      files: ['**/*.d.ts'],
      rules: {
        '@typescript-eslint/no-unused-vars': 'off',
        '@typescript-eslint/no-explicit-any': 'off',
      },
    },
    {
      // Dynamic-route files like [id].vue are necessarily single-word.
      files: [
        'layouts/*.vue',
        'pages/**/*.vue',
        'modtools/layouts/*.vue',
        'modtools/pages/**/*.vue',
      ],
      rules: {
        'vue/multi-word-component-names': 'off',
      },
    }
  )
