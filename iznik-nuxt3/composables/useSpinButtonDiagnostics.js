import { apiCallsInFlight } from '~/composables/useApiCallsInFlight'

// What to attach when a SpinButton's handler never reports back (Sentry "SpinButton - callback not
// called"). The report used to say only the button's variant, label and icon, which names the
// button and not what it was waiting on. This adds the component that owns it, how long it waited,
// the API calls still in flight and what the browser believes about the connection.
export function spinButtonContext({
  componentName,
  label,
  clickedAt,
  online,
  now = Date.now(),
  connection,
}) {
  return {
    component: componentName || null,
    label,
    waitedMs: clickedAt ? now - clickedAt : null,
    online,
    apiCallsInFlight: apiCallsInFlight(),
    effectiveType: connection?.effectiveType ?? null,
    saveData: connection?.saveData ?? null,
  }
}
