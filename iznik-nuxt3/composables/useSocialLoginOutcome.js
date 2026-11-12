// What a failed social sign-in means, from the message the provider's plugin gave.
//
// - cancelled: the member backed out ("Login cancelled", "Google Sign-In cancelled by user",
//   "The user canceled the sign-in flow."). Their choice, not a fault.
// - device: the phone cannot sign in with that provider at all: no Apple ID signed in (Apple
//   error 1000), or no Google account or credential on the device. These can also be a misconfigured
//   build, so the caller leaves a Sentry breadcrumb (context for any event that follows) and counts
//   them in the logs; a spike in that rate is the signal that a release is broken.
// - fault: anything else, which still goes to Sentry.
// The plugins' own cancel messages, not any message that mentions cancelling: a network request
// that was "cancelled" is a fault.
const CANCELLED = [
  /\bLogin cancell?ed\b/i, // Facebook, native
  /Sign-In cancell?ed by user/i, // Google, iOS
  /user cancell?ed the sign-in flow/i, // Google, Android
  /^Apple login cancelled$/, // ours, from Apple error 1001
]

const DEVICE = [
  /AuthorizationError error 1000\b/,
  /No credentials available/i,
  /Cannot find an eligible account/i,
  /Account reauth failed/i,
  /getCredentialAsync no provider dependencies/i,
]

export function socialLoginOutcome(message) {
  const text = String(message ?? '')

  if (CANCELLED.some((pattern) => pattern.test(text))) {
    return 'cancelled'
  }

  if (DEVICE.some((pattern) => pattern.test(text))) {
    return 'device'
  }

  return 'fault'
}
