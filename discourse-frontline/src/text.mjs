// Turning a Discourse post into the words its author wrote.
//
// Discourse gives each post as rendered HTML ("cooked"). Quotes of other people are
// inside it, and a report judged on somebody else's quoted words is judged wrong, so
// they come out first.

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', hellip: '...', rsquo: "'", lsquo: "'", ldquo: '"', rdquo: '"', ndash: '-', mdash: '-', pound: '£' }

export function decodeEntities(s) {
  return s
    .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(Number.parseInt(h, 16)))
    .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
    .replace(/&([a-z]+);/gi, (m, name) => ENTITIES[name.toLowerCase()] ?? m)
}

// An uploaded picture. Emoji are images too, and say nothing.
const POST_IMAGE = /<img\b(?![^>]*\bclass="[^"]*\bemoji\b)[^>]*>|class="lightbox"/i

export function hasImage(cooked) {
  return POST_IMAGE.test(cooked ?? '')
}

/** Post numbers this post quotes, from Discourse's own quote markup. */
export function quotedPosts(cooked) {
  const out = []
  for (const m of (cooked ?? '').matchAll(/<aside\b[^>]*class="[^"]*\bquote\b[^"]*"[^>]*>/gi)) {
    const post = /data-post="(\d+)"/.exec(m[0])
    const topic = /data-topic="(\d+)"/.exec(m[0])
    if (post) out.push({ post: Number(post[1]), topic: topic ? Number(topic[1]) : null })
  }
  return out
}

/** The author's own words as plain text. Quotes, code fences and markup are gone. */
export function cookedToText(cooked) {
  let s = cooked ?? ''
  // Quotes can nest, so peel from the inside until none are left.
  for (let i = 0; i < 6 && /<aside\b[^>]*\bquote\b/i.test(s); i++) {
    s = s.replace(/<aside\b[^>]*\bquote\b[^>]*>(?:(?!<aside\b)[\s\S])*?<\/aside>/gi, ' ')
  }
  for (let i = 0; i < 6 && /<blockquote\b/i.test(s); i++) {
    s = s.replace(/<blockquote\b[^>]*>(?:(?!<blockquote\b)[\s\S])*?<\/blockquote>/gi, ' ')
  }
  s = s
    .replace(/<img\b[^>]*\bclass="[^"]*\bemoji\b[^>]*>/gi, '')
    .replace(/<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/gi, (_, href, label) => {
      const t = label.replace(/<[^>]+>/g, '').trim()
      return !t || t === href || href.startsWith('#') || href.startsWith('/u/') ? (t || href) : `${t} (${href})`
    })
    .replace(/<li\b[^>]*>/gi, '\n- ')
    .replace(/<br\s*\/?>(\n)?/gi, '\n')
    .replace(/<\/(p|div|h\d|ul|ol|pre|tr)>/gi, '\n')
    .replace(/<[^>]+>/g, '')
  return decodeEntities(s).replace(/[ \t]+/g, ' ').replace(/ ?\n ?/g, '\n').replace(/\n{3,}/g, '\n\n').trim()
}

export function wordCount(s) {
  return (s.match(/\S+/g) ?? []).length
}

/** Cut at a word, and say so. Nothing is shortened without the mark. */
export function clip(s, max) {
  if (s.length <= max) return s
  const cut = s.slice(0, max)
  const at = cut.lastIndexOf(' ')
  return `${cut.slice(0, at > max * 0.6 ? at : max).trimEnd()} [...]`
}

/**
 * Somebody else's words, made safe to put inside a [quote] block in our own post.
 * A closing tag in their text would otherwise end the quote early and put the rest
 * in the service's voice, and an @name would notify someone.
 */
export function safeQuote(s, max = 280) {
  return clip(s.replace(/\s+/g, ' ').trim(), max)
    .replace(/\[(\/?)(quote|code|img|url|details)/gi, '[​$1$2')
    .replace(/@(\w)/g, '@​$1')
    .replace(/!\[/g, '!​[')
    .replace(/https?:\/\/\S+/gi, '(link)')
}
