import { defineConfig } from 'vitest/config'

export default defineConfig({
  test: {
    environment: 'node',
    globals: true,
    // Keeps the suite out of the live database and the live log. See the file.
    setupFiles: ['src/__tests__/setup.ts'],
    // setup.ts is support code, not a spec.
    exclude: ['**/node_modules/**', '**/dist/**', '**/__tests__/setup.ts'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'json'],
    },
  },
})
