// Is a bug report specific enough to act on?
//
// The reports that waste the most time are the ones that point at one particular
// thing without saying which: "a member says a group deleted her post". Nobody can
// look that up. The monitor used to take such a report into the fix pipeline anyway,
// where the diagnosis had nothing to hold on to, or park it as deferred without ever
// telling the person who wrote it. Either way the reporter heard nothing back.
//
// This works out whether a report names anything you could look up, and if not, what
// to ask for. It is deliberately not a judgement call by a model: the same report
// always gets the same answer, and the reasoning can be read off the regular
// expressions below.

/** Something in the report that a person could go and look up. */
export type Anchor = 'id' | 'email' | 'link' | 'screenshot' | 'group'

export interface ReportSpecifics {
  /** What the report gives us to work from. Empty means nothing at all. */
  anchors: Anchor[]
  /** Plain-English things to ask for, at most three, most useful first. */
  missing: string[]
  /** True when there is nothing to work from AND something worth asking for. */
  isVague: boolean
}

// Five digits or more. Four would match a year, and a report that says "in 2026"
// is no more findable for it.
const ID = /\b\d{5,}\b/
const EMAIL = /[\w.+-]+@[\w-]+\.[\w-]+/
const LINK = /\bilovefreegle\.org\/\S+/i
const UPLOAD = /!\[|upload:\/\//

// A reference to one particular thing, with no way of telling which one.
const VAGUE_MEMBER = /\b(?:a|one|another|some|this|that) (?:member|user|freegler|person)\b|\bsomeone\b|\bsomebody\b|\bone of (?:my|our) (?:members|users)\b/i
const VAGUE_GROUP = /\b(?:a|one|another|some|this|that) (?:group|community)\b|\bone of (?:my|our) groups\b|\bthe group\b/i
const VAGUE_POST = /\b(?:a|one|another|some|this|that|her|his|their) (?:post|message|ad|listing|offer|wanted|item)\b/i

// "I saw some of them, but not which ones."
//
// The patterns above only catch a single thing referred to by an article: "a post",
// "one member". A moderator describing a morning's work counts them instead - "a
// couple of posts duplicated within hours, one person asking for cash, several
// giving full addresses" - and every one of those is a particular thing nobody can
// look up. That phrasing went straight past the article patterns and into a fix
// that had nothing to hold on to (topic 10063, PR #1574, closed).
//
// A count is only a signal when the report is about things of the kind we can be
// pointed at, so both halves have to appear. "Chat notification emails are going
// out twice" counts nothing and names no such thing, and must stay untouched.
// The "of" is optional on purpose: "a few groups" counts just as much as "a few
// of the groups", and requiring it let the first phrasing through.
const COUNTED = /\b(?:a (?:couple|few|handful|number)(?: of)?|several|multiple|numerous|many|lots of|loads of|\d+)\b/i
const INSTANCE_NOUN = /\b(?:posts?|messages?|members?|users?|freeglers?|people|persons?|groups?|communities|ads?|listings?|offers?|items?|chats?|replies)\b/i
// The report describes what somebody saw, so a picture of it would settle a lot.
const VISUAL = /\b(?:looks?|looking|showing|shows|displayed?|appears?|blank|greyed|grayed|missing|button|screen|page|layout)\b/i

const ASK = {
  member: 'which member this was, with their email address or a link to their profile',
  group: 'which group this was on',
  post: 'a link to the post or message',
  screenshot: 'a screenshot of what they saw',
}

/**
 * Work out what a report gives us, and what is worth asking for.
 *
 * `hasScreenshot` and `groupName` come from triage, which reads the post itself:
 * an image is stripped out before the text reaches here, and a group name is not
 * something a regular expression can recognise.
 */
export function assessReportSpecifics(input: {
  /** What the reporter wrote. Vagueness is judged on this and nothing else. */
  text: string
  /**
   * Where to look for things that can be looked up, when that is wider than the
   * reporter's own words: a triage summary may carry an id it pulled out of the
   * thread. Defaults to the text.
   */
  anchorText?: string
  hasScreenshot?: boolean
  groupName?: string | null
  userRef?: string | null
}): ReportSpecifics {
  const text = input.text ?? ''
  const anchorText = input.anchorText ?? text
  const anchors: Anchor[] = []
  if (ID.test(anchorText) || (input.userRef ?? '').trim()) anchors.push('id')
  if (EMAIL.test(anchorText)) anchors.push('email')
  if (LINK.test(anchorText)) anchors.push('link')
  if (input.hasScreenshot || UPLOAD.test(anchorText)) anchors.push('screenshot')
  if ((input.groupName ?? '').trim()) anchors.push('group')

  const missing: string[] = []
  const identified = anchors.some(a => a === 'id' || a === 'email' || a === 'link')
  if (VAGUE_MEMBER.test(text) && !identified) missing.push(ASK.member)
  if (VAGUE_GROUP.test(text) && !anchors.includes('group') && !anchors.includes('link')) missing.push(ASK.group)
  if (VAGUE_POST.test(text) && !identified) missing.push(ASK.post)

  // Counted, but not identified. Ask for whichever of the two the report leaned on,
  // so the question matches what they were describing.
  if (COUNTED.test(text) && INSTANCE_NOUN.test(text)) {
    const aboutPeople = /\b(?:members?|users?|freeglers?|people|persons?)\b/i.test(text)
    if (aboutPeople && !identified && !missing.includes(ASK.member)) missing.push(ASK.member)
    if (!identified && !missing.includes(ASK.post)) missing.push(ASK.post)
  }
  if (VISUAL.test(text) && !anchors.includes('screenshot') && missing.length > 0) missing.push(ASK.screenshot)

  const capped = missing.slice(0, 3)
  return { anchors, missing: capped, isVague: anchors.length === 0 && capped.length > 0 }
}

/** The reply asking for what is missing. Short, and only about what was left out. */
export function detailRequestBody(missing: string[]): string {
  const items = missing.filter(m => m.trim())
  if (items.length === 0) throw new Error('detailRequestBody: nothing to ask for')
  // One line each, rather than one long sentence: three things joined by commas
  // runs past the length a reply on a phone should be, and a list is easier to
  // answer point by point.
  const list = items.map(i => `- ${i}`).join('\n')
  return `Happy to look at this. To find it I need a bit more:\n\n${list}`
}
