// What a report already tells us, read off the text by rule.
//
// A developer needs a handful of facts to start on a problem: what was seen, where,
// on what, when, and which member, group or post it was. Most of them are words a
// regular expression can find, so they are found here, the same way every time. The
// model is asked only for the three that need reading for sense: whether the report
// says what happened, what was expected, and how to make it happen again.

const RX = {
  app: /\b(?:app|android|iphone|ipad|ios|play store|app store|testflight)\b/i,
  web: /\b(?:website|web site|browser|chrome|firefox|safari|edge|opera|laptop|desktop|pc|macbook|windows|chromebook)\b/i,
  device: /\b(?:android|iphone|ipad|samsung|pixel|huawei|motorola|kindle|chromebook|macbook|windows(?: \d+)?|mac|linux|tablet|ios ?\d*)\b/gi,
  browser: /\b(?:chrome|firefox|safari|edge|opera|brave|duckduckgo)\b/gi,
  modtools: /\b(?:modtools|mod tools|mt|pending|approved members?|member logs?|spammers?|chat review|mod(?:erator)?s? (?:page|tools|settings)|standard messages?|stdmsgs?)\b/i,
  member_site: /\b(?:fd|freegle direct|my posts|browse|chitchat|give|find|ilovefreegle\.org)\b/i,
  when: /\b(?:today|yesterday|this (?:morning|afternoon|evening|week)|last (?:night|week|month)|tonight|just now|(?:a few|\d+) (?:minutes?|hours?|days?|weeks?) ago|since (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|yesterday|last|the)|(?:mon|tues|wednes|thurs|fri|satur|sun)day|\d{1,2}[:.]\d{2}\s?(?:am|pm)?|\d{1,2}(?:st|nd|rd|th)? (?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*)\b/i,
  frequency: /\b(?:every time|always|sometimes|occasionally|intermittent(?:ly)?|once|twice|each time|keeps? (?:on )?\w+ing|repeatedly|now and then)\b/i,
  url: /https?:\/\/[^\s)>\]]+/gi,
  id: /\b\d{5,}\b/g,
  email: /[\w.+-]+@[\w-]+\.[\w.-]+/,
  // Words in quote marks after a word like "says": the text of an error.
  errorText: /\b(?:says?|said|saying|message|error|warning|reads?|shows?|showing)\b[^"“'‘\n]{0,30}["“'‘]([^"”'’\n]{4,160})["”'’]/i,
  visual: /\b(?:looks?|looking|showing|shows|displayed?|appears?|blank|greyed|grayed|missing|button|screen|page|layout|overlap|cut off|squashed)\b/i,
}

// One particular thing, with no way of telling which. Plurals count: "for some
// users" is as unfindable as "a user". "Someone" counts only when it is the person the
// report is about: "can someone look at this" names nobody we need.
const VAGUE = {
  member: /\b(?:a|one|another|some|this|that|these|those) (?:members?|users?|freeglers?|people|persons?)\b|\b(?:someone|somebody) (?:who|has|had|is|was|says?|said|reported|told|tried|posted|joined|got|keeps?|can(?:'t|not)|couldn't)\b|\bone of (?:my|our|the) (?:members|users|freeglers)\b/i,
  group: /\b(?:a|one|another|some|this|that|these|those) (?:groups?|communit(?:y|ies))\b|\bone of (?:my|our|the) (?:groups|communities)\b/i,
  post: /\b(?:a|one|another|some|this|that|her|his|their) (?:post|message|ad|listing|offer|wanted|item)\b/i,
}
// "A couple of posts", "several members": counted, and none of them named.
const COUNTED = /\b(?:a (?:couple|few|handful|number)(?: of)?|several|multiple|numerous|many|lots of|loads of|two|three|four|five|six|seven|eight|nine|ten|a dozen)\b/i
const PEOPLE = /\b(?:members?|users?|freeglers?|people|persons?)\b/i
const THINGS = /\b(?:posts?|messages?|ads?|listings?|offers?|items?|chats?|replies)\b/i

function uniq(matches) {
  return [...new Set((matches ?? []).map((m) => m.toLowerCase()))]
}

/** Facts found in one or more posts by the same reporter. */
export function extractFacts(text, { hasImage = false } = {}) {
  const urls = uniq(text.match(RX.url))
  const withoutUrls = text.replace(RX.url, ' ')
  const facts = {
    platform: RX.app.test(text) ? (RX.web.test(text) ? 'both' : 'app') : RX.web.test(text) ? 'web' : null,
    site: RX.modtools.test(text) ? 'modtools' : RX.member_site.test(text) ? 'freegle' : null,
    devices: uniq(text.match(RX.device)),
    browsers: uniq(text.match(RX.browser)),
    when: RX.when.exec(text)?.[0] ?? null,
    frequency: RX.frequency.exec(text)?.[0] ?? null,
    links: urls,
    ids: uniq(withoutUrls.match(RX.id)),
    hasEmail: RX.email.test(text),
    hasScreenshot: hasImage,
    errorText: RX.errorText.exec(text)?.[1] ?? null,
  }
  return facts
}

/** Merge facts from a later post into what the case already knows. */
export function mergeFacts(a, b) {
  const pick = (x, y) => (y === null || y === undefined || y === '' ? x : y)
  const join = (x = [], y = []) => [...new Set([...x, ...y])]
  return {
    platform: a.platform && b.platform && a.platform !== b.platform ? 'both' : pick(a.platform, b.platform),
    site: pick(a.site, b.site),
    devices: join(a.devices, b.devices),
    browsers: join(a.browsers, b.browsers),
    when: pick(a.when, b.when),
    frequency: pick(a.frequency, b.frequency),
    links: join(a.links, b.links),
    ids: join(a.ids, b.ids),
    hasEmail: Boolean(a.hasEmail || b.hasEmail),
    hasScreenshot: Boolean(a.hasScreenshot || b.hasScreenshot),
    errorText: pick(a.errorText, b.errorText),
  }
}

/**
 * What a bug report still needs before somebody could start on it.
 *
 * `has` is the model's reading of the three things that need reading for sense. A
 * fact found by rule always wins over it: the model is never allowed to ask for a
 * screenshot that is sitting in the post.
 *
 * Needed, in the order they are asked for: which thing it was, what happened, how to
 * make it happen, and where (app or website). Wanted but never asked for alone: a
 * screenshot and when it happened. What was expected is not asked for at all: it is
 * nearly always plain from what went wrong, and asking reads as not having listened.
 *
 * @returns {{ needed: string[], wanted: string[] }}
 */
export function missingForBug(text, facts, has) {
  const identified = facts.ids.length > 0 || facts.links.length > 0 || facts.hasEmail
  const needed = []
  const wanted = []

  const counted = COUNTED.test(text)
  if (!identified) {
    if (VAGUE.member.test(text) || (counted && PEOPLE.test(text))) needed.push('which-member')
    if (VAGUE.post.test(text) || (counted && THINGS.test(text))) needed.push('which-post')
  }
  if (VAGUE.group.test(text) && facts.links.length === 0) needed.push('which-group')
  if (!has.actual) needed.push('what-happened')
  if (!has.steps && !facts.errorText && !facts.hasScreenshot) needed.push('steps')
  const whereKnown = facts.platform || facts.devices.length || facts.browsers.length || has.platform
  if (!whereKnown) needed.push('where')

  if (!facts.hasScreenshot && RX.visual.test(text)) wanted.push('screenshot')
  if (!facts.when && !has.when) wanted.push('when')
  return { needed, wanted }
}

/** The wording of each thing that can be asked for. Fixed, so it is the same every time. */
export const ASK_TEXT = {
  'which-member': 'which member it was: their Freegle user number from ModTools is best (please don\'t post their email address or name here)',
  'which-post': 'a link to the post or message, or its number',
  'which-group': 'which community this was on',
  'what-happened': 'what you saw happen, as exactly as you can',
  steps: 'what you did just before it went wrong, step by step',
  where: 'whether this was in the app or on the website, and on what phone or browser',
  screenshot: 'a screenshot of what you saw, if you can',
  when: 'roughly when it happened',
}
