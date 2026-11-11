'use strict'

// The agent writes suggested replies that volunteers send to members word for word. It once
// sent a member to https://www.ilovefreegle.org/myvolunteering, a page that has never existed.
// Every link to the member site in an answer is checked against the pages in the codebase
// checkout, and any that matches none is called out under the answer.

const fs = require('fs')
const path = require('path')

const SITE_LINK = /https?:\/\/(?:www\.)?ilovefreegle\.org(?:\/[^\s)\]"'`]*)?/gi

function escape(s) {
  return s.replace(/[.*+?^${}()|\\]/g, '\\$&')
}

// One page file (relative to the pages folder) as the pattern for the paths it serves:
// index is the folder itself, [[x]] is an optional part, [x] a required one, [...x] any depth.
function routePattern(file) {
  const parts = file.replace(/(\.client|\.server)?\.vue$/, '').split('/')
  if (parts[parts.length - 1] === 'index') parts.pop()
  let re = ''
  for (const part of parts) {
    if (/^\[\[\.\.\..+\]\]$/.test(part)) re += '(?:/.*)?'
    else if (/^\[\.\.\..+\]$/.test(part)) re += '/.+'
    else if (/^\[\[.+\]\]$/.test(part)) re += '(?:/[^/]+)?'
    else re += '/' + escape(part).replace(/\[[^\]]+\]/g, '[^/]+')
  }
  return new RegExp('^' + re + '/?$')
}

// Old paths the site redirects (routeRules in nuxt.config.ts, beside the pages folder) still
// work, so they count as pages: '/find' exactly, '/find/**' anything below it.
function redirectPatterns(pagesDir) {
  const config = path.join(pagesDir, '..', 'nuxt.config.ts')
  if (!fs.existsSync(config)) return []
  const out = []
  for (const m of fs.readFileSync(config, 'utf8').matchAll(/'(\/[^']*)'\s*:\s*\{\s*redirect\b/g)) {
    const key = m[1]
    if (key.endsWith('/**')) out.push(new RegExp('^' + escape(key.slice(0, -3)) + '(?:/.*)?$'))
    else out.push(new RegExp('^' + escape(key) + '/?$'))
  }
  return out
}

function pageFiles(dir, prefix = '') {
  let out = []
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const rel = prefix ? prefix + '/' + entry.name : entry.name
    if (entry.isDirectory()) out = out.concat(pageFiles(path.join(dir, entry.name), rel))
    else if (entry.name.endsWith('.vue')) out.push(rel)
  }
  return out
}

/**
 * The links to the member site in `text` that match no page in `pagesDir`, each once.
 * Without the pages folder nothing can be checked, so nothing is returned.
 */
function unknownSiteLinks(text, pagesDir) {
  if (!fs.existsSync(pagesDir)) return []
  const routes = pageFiles(pagesDir).map(routePattern).concat(redirectPatterns(pagesDir))
  const unknown = []
  for (const match of String(text || '').match(SITE_LINK) || []) {
    const link = match.replace(/[.,;:!?]+$/, '')
    // A placeholder such as <id> stands for any value.
    const p = link.replace(/^https?:\/\/[^/]+/, '').split(/[?#]/)[0].replace(/<[^>/]*>?/g, 'x') || '/'
    if (!routes.some((r) => r.test(p)) && !unknown.includes(link)) unknown.push(link)
  }
  return unknown
}

/** The answer, with a warning under it when it links to pages the site does not have. */
function withLinkWarning(text, pagesDir) {
  const unknown = unknownSiteLinks(text, pagesDir)
  if (!unknown.length) return text
  return (
    text +
    '\n\n---\n**Check before sending:** these links do not match any page on the site, so they ' +
    'may not work. Open them first, and take them out of a reply if they do not:\n' +
    unknown.map((l) => '- ' + l).join('\n')
  )
}

module.exports = { unknownSiteLinks, withLinkWarning }
