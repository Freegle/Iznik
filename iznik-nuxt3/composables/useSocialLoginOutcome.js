// What a failed social sign-in means, from the message the provider's plugin gave.
//
// - cancelled: the member backed out ("Login cancelled", "Google Sign-In cancelled by user",
//   "The user canceled the sign-in flow."). Their choice, not a fault.
// - device: the phone cannot sign in with that provider at all: no Apple ID signed in (Apple
//   error 1000), or no Google account or credential on the device. Nothing in our code can change
//   it, so it is counted in the logs rather than raised as an error.
// - fault: anything else, which still goes to Sentry.
const CANCELLED = /cancel/i

const DEVICE = [
  /AuthorizationError error 1000\b/,
  /No credentials available/i,
  /Cannot find an eligible account/i,
  /Account reauth failed/i,
  /getCredentialAsync no provider dependencies/i,
]

export function socialLoginOutcome(message) {
  const text = String(message ?? '')

  if (CANCELLED.test(text)) {
    return 'cancelled'
  }

  if (DEVICE.some((pattern) => pattern.test(text))) {
    return 'device'
  }

  return 'fault'
}
