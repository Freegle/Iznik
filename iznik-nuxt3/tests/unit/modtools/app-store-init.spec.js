import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, it, expect } from 'vitest'

/**
 * Pinia stores here can't read the Nuxt config themselves, so modtools/app.vue reads it once
 * and hands it to every store via init(). A store that is used by a page but never gets that
 * call fails at runtime with "Cannot read properties of undefined (reading 'public')" on its
 * first API call - and unit tests don't catch it, because they mock the store away.
 */
const app = readFileSync(
  resolve(__dirname, '../../../modtools/app.vue'),
  'utf8'
)

describe('modtools/app.vue store wiring', () => {
  it('hands the runtime config to every store it creates', () => {
    const created = [
      ...app.matchAll(/const (\w+Store) = use\w+Store\(\)/g),
    ].map((m) => m[1])
    const initialised = new Set(
      [...app.matchAll(/(\w+Store)\.init\(runtimeConfig\)/g)].map((m) => m[1])
    )

    expect(created.length).toBeGreaterThan(0)
    expect(created.filter((s) => !initialised.has(s))).toEqual([])
  })

  it('sets up the partnerships store', () => {
    expect(app).toContain('const partnershipsStore = usePartnershipsStore()')
    expect(app).toContain('partnershipsStore.init(runtimeConfig)')
  })
})
