// Shared Discourse configuration.
// Override DISCOURSE_URL in .env to change the target instance.

export const DISCOURSE_BASE =
  process.env.DISCOURSE_URL?.replace(/\/$/, '') ??
  'https://community.ilovefreegle.org'
