/**
 * Brand-identity strings consumed by `nuxt.config.ts` (and other shared head
 * config) when rendering meta tags, page titles, and social-card previews.
 *
 * Defaults are Freegle's. A Nuxt layer (e.g. modtools/, or a third-party
 * re-skin) can override this file via the layer extends system to swap
 * the entire brand in one place — without forking `nuxt.config.ts`.
 *
 * Keep this file small and stable. Avoid putting layout/CSS/feature flags
 * here — only the strings a brand replaces when it skins this product.
 */
export const branding = {
  // Human-readable site name. Appears in og:site_name, navbar fallback alt
  // text, and (via `${siteName} - ${tagline}`) in <title> and og:title.
  siteName: 'Freegle',

  // Short tagline appended after siteName in <title> / og:title /
  // twitter:title.
  tagline: "Don't throw it away, give it away!",

  // 1-2 sentence elevator pitch. Used as <meta name="description">,
  // og:description, twitter:description, and the
  // apple-mobile-web-app-title content.
  description:
    "Give and get stuff for free in your local community.  Don't just recycle - reuse, freecycle and freegle!",

  // Alt text for the brand logo as it appears in social cards.
  logoAlt: 'The Freegle logo',

  // Twitter @handle (without the @). Used for twitter:site.
  twitterHandle: 'thisisfreegle',

  // Facebook domain verification token (`<meta name="facebook-domain-
  // verification">`). One per brand. If a fork doesn't use FB
  // verification, leave empty — the tag is omitted.
  facebookDomainVerification: 'zld0jt8mvf06rt1c3fnxvls3zntxj6',
}

export default branding
