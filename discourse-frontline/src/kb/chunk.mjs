// Cutting pages into sections small enough to hand to a model a few at a time.
//
// A section is the unit an answer is cited from, so each keeps its page title and its
// heading. Long sections are split at paragraph breaks, never mid-sentence.

const MAX = 1400

function splitLong(body) {
  if (body.length <= MAX) return [body]
  const parts = []
  let cur = ''
  for (const para of body.split(/\n{2,}/)) {
    if (cur && cur.length + para.length + 2 > MAX) {
      parts.push(cur)
      cur = ''
    }
    cur = cur ? `${cur}\n\n${para}` : para
  }
  if (cur) parts.push(cur)
  return parts
}

function sections(lines, headingOf) {
  const out = []
  let heading = ''
  let buf = []
  const flush = () => {
    const body = buf.join('\n').replace(/\n{3,}/g, '\n\n').trim()
    if (body.length >= 40) for (const part of splitLong(body)) out.push({ heading, body: part })
    buf = []
  }
  for (const line of lines) {
    const h = headingOf(line)
    if (h !== null) {
      flush()
      heading = h
    } else {
      buf.push(line)
    }
  }
  flush()
  return out
}

/** Markdown from docs/. Front matter, images and link targets are dropped. */
export function chunkMarkdown(md) {
  const text = md
    .replace(/^---\n[\s\S]*?\n---\n/, '')
    .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
    .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1')
    .replace(/<[^>]+>/g, '')
    .replace(/[*_`]{1,3}/g, '')
  const title = /^#\s+(.+)$/m.exec(text)?.[1]?.trim() ?? ''
  const chunks = sections(text.split('\n'), (l) => {
    const m = /^#{1,4}\s+(.+)$/.exec(l)
    return m ? m[1].trim() : null
  })
  return { title, chunks }
}

/** MediaWiki wikitext, reduced to the words on the page. */
export function wikitextToPlain(wt) {
  let s = wt
    .replace(/<!--[\s\S]*?-->/g, '')
    .replace(/<ref\b[^>]*>[\s\S]*?<\/ref>|<ref\b[^>]*\/>/gi, '')
    .replace(/<(gallery|nowiki|pre|syntaxhighlight)\b[^>]*>[\s\S]*?<\/\1>/gi, '')
  // Templates and tables nest. Peel from the inside.
  for (let i = 0; i < 8 && /\{\{|\{\|/.test(s); i++) {
    s = s.replace(/\{\{(?:(?!\{\{|\}\})[\s\S])*?\}\}/g, '').replace(/\{\|(?:(?!\{\||\|\})[\s\S])*?\|\}/g, '')
  }
  return s
    .replace(/\[\[(?:File|Image|Category|Media):[^\]]*\]\]/gi, '')
    .replace(/\[\[[^\]|]*\|([^\]]*)\]\]/g, '$1')
    .replace(/\[\[([^\]]*)\]\]/g, '$1')
    .replace(/\[https?:\/\/\S+\s+([^\]]+)\]/g, '$1')
    .replace(/\[https?:\/\/\S+\]/g, '')
    .replace(/'{2,5}/g, '')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/^[*#:;]+\s*/gm, '- ')
    .replace(/__[A-Z]+__/g, '')
    .replace(/&nbsp;/g, ' ')
}

export function chunkWikitext(wt) {
  return sections(wikitextToPlain(wt).split('\n'), (l) => {
    const m = /^(={2,5})\s*(.+?)\s*\1\s*$/.exec(l)
    return m ? m[2].trim() : null
  })
}
