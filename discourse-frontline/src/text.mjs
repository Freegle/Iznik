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

/**
 * The same three readings from a post's raw markdown, for an archive that kept the
 * raw text and not the rendered page.
 */
export function rawToParts(raw) {
  let s = raw ?? ''
  const quotes = []
  for (const m of s.matchAll(/\[quote="[^"\]]*?post:(\d+),\s*topic:(\d+)[^"\]]*"\]/gi)) quotes.push({ post: Number(m[1]), topic: Number(m[2]) })
  for (let i = 0; i < 6 && /\[quote/i.test(s); i++) s = s.replace(/\[quote[^\]]*\](?:(?!\[quote)[\s\S])*?\[\/quote\]/gi, ' ')
  const image = /!\[[^\]]*\]\((?:upload:|https?:)[^)]*\)|<img\b/i.test(s)
  s = s
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, '$1 ($2)')
    .replace(/^>.*$/gm, '')
    .replace(/<[^>]+>/g, '')
  return { text: decodeEntities(s).replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim(), hasImage: image, quotes }
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

// A zero-width space: it breaks an @name so that nobody is notified, and shows as nothing.
const GAP = String.fromCharCode(0x200b)

/**
 * Somebody else's words, made safe to put inside a [quote] block in our own post.
 * A closing tag in their text would otherwise end the quote early and put the rest
 * in the service's voice, an @name would notify someone, and a link or an email
 * address would be published a second time under the service's name.
 */
export function safeQuote(s, max = 280) {
  return clip(s.replace(/\s+/g, ' ').trim(), max)
    .replace(/[\w.+-]+@[\w-]+\.[\w.-]+/g, '(email address)')
    .replace(/\b[a-z][a-z0-9+.-]*:\/\/\S+|\/\/[a-z0-9-]+\.\S+|\bwww\.\S+|\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}\/\S*/gi, '(link)')
    // Square brackets carry every Discourse tag and every markdown link, and angle
    // brackets carry HTML. None of them is needed to read what somebody said.
    .replace(/\[/g, '(').replace(/\]/g, ')').replace(/[<>`]/g, '')
    .replace(/@(\w)/g, `@${GAP}$1`)
    .replace(/^[#>\-*\s]+/, '')
}
