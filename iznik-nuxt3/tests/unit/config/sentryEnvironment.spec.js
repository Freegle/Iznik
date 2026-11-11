import { describe, it, expect } from 'vitest'
import { sentryEnvironment } from '../../../config.js'

// The app is built on CircleCI. Read CIRCLECI first and the app in members'
// hands reports itself to Sentry as 'ci', which is what happened until
// 2026-11: 35,000 production app events in two weeks labelled 'ci'.
describe('sentryEnvironment', () => {
  it('labels the store build of the app production although it is built on CI', () => {
    expect(sentryEnvironment({ CIRCLECI: 'true', APP_ENV: 'production' })).toBe(
      'production'
    )
  })

  it('labels a debug build of the app dev', () => {
    expect(
      sentryEnvironment({ CIRCLECI: 'true', APP_ENV: 'development' })
    ).toBe('dev')
  })

  it('labels a web build on CI ci', () => {
    expect(
      sentryEnvironment({ CIRCLECI: 'true', NODE_ENV: 'production' })
    ).toBe('ci')
  })

  it('labels a production web build production', () => {
    expect(sentryEnvironment({ NODE_ENV: 'production' })).toBe('production')
  })

  it('labels the dev server dev', () => {
    expect(sentryEnvironment({ NODE_ENV: 'development' })).toBe('dev')
  })

  it('lets an explicit ENVIRONMENT win', () => {
    expect(
      sentryEnvironment({ ENVIRONMENT: 'staging', APP_ENV: 'production' })
    ).toBe('staging')
  })
})
