import { describe, it, expect } from 'vitest'
import { socialLoginOutcome } from '~/composables/useSocialLoginOutcome'

describe('socialLoginOutcome', () => {
  it.each([
    'Login cancelled',
    'Google Sign-In cancelled by user',
    'The user canceled the sign-in flow.',
    'Google login error: Google Sign-In cancelled by user',
    'Apple login cancelled',
  ])('treats "%s" as the member backing out', (message) => {
    expect(socialLoginOutcome(message)).toBe('cancelled')
  })

  it.each([
    'The operation couldn’t be completed. (com.apple.AuthenticationServices.AuthorizationError error 1000.)',
    'Google Sign-In failed: No credentials available',
    'Google Sign-In failed: During get sign-in intent, failure response from one tap: 16: Cannot find an eligible account',
    'Google Sign-In failed: [16] Account reauth failed. The plugin cleared Credential Manager state',
    'Google Sign-In failed: getCredentialAsync no provider dependencies found - please ensure the desired provider dependencies are added',
  ])('treats "%s" as a phone that cannot sign in', (message) => {
    expect(socialLoginOutcome(message)).toBe('device')
  })

  it.each([
    'plugin exploded',
    'The request was cancelled',
    'Network request cancelled',
    'Facebook app login failed',
    'The operation couldn’t be completed. (com.apple.AuthenticationServices.AuthorizationError error 1004.)',
    'Google login failed: Request failed with status 500',
    undefined,
    null,
  ])('leaves %s as a fault', (message) => {
    expect(socialLoginOutcome(message)).toBe('fault')
  })
})
