// Shared Discourse configuration.
// Override DISCOURSE_URL in .env to change the target instance.

// Use `||` (not `?.replace() ??`) so an EMPTY DISCOURSE_URL also falls back:
// '' is not nullish, so `?? fallback` would leave DISCOURSE_BASE = '' and every
// request would become a relative URL (e.g. "/latest.json" → urllib ValueError:
// "unknown url type"), which silently broke discover_active_topics.
export const DISCOURSE_BASE =
  process.env.DISCOURSE_URL?.replace(/\/$/, '') ||
  'https://discourse.ilovefreegle.org'
