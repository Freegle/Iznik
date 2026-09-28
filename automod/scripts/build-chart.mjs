// Builds chart.json: the national flowchart automated review walks for every post.
// Run after editing: node scripts/build-chart.mjs
//
// Each question is a tool node whose `check` says how it is answered (src/walk.js):
//   kind fact  - the batch computed it (AutomodFactsService); `fact` names it and
//                `<fact>_detail` is the evidence a moderator sees
//   kind text  - a model answers `question`; `description` is the short wording shown
// and, for either kind: `rule` (skip when the community allows it), `requiresRule` (ask only
// when the community restricts it), `when` (a fact that must be true), `flags` (keyword
// categories from src/prompt.js that count as evidence and trigger the review pass),
// `hint` (a fact whose detail is told to the model), `context` (a request field the question
// needs, which makes it a call of its own).
//
// The first question answered yes holds the post with that node's HOLD description; a post
// that gets to the end is approved. Nothing here rejects.
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const T = 0.7;

// [id, short question, hold reason, check]
const NODES = [
  // What Freegle already knows about the member and the post.
  ['MOD_NOTE', 'Has a moderator left a note about this member?',
    'A moderator has left a note about this member, so their posts wait for a moderator.',
    { kind: 'fact', fact: 'mod_note' }],
  ['MICROVOL_REJECT', 'Did a microvolunteer say this post should not be on Freegle?',
    'A microvolunteer said this post should not be on Freegle.',
    { kind: 'fact', fact: 'microvol_reject' }],
  ['RECENT_ACTION', "Has a moderator rejected, deleted or replied to one of this member's posts in the last 90 days?",
    "A moderator acted on one of this member's posts recently, so this one waits for a moderator.",
    { kind: 'fact', fact: 'recent_action' }],
  ['SPAMMER', "Is this member on Freegle's spammer list?",
    "This member is on Freegle's spammer list.",
    { kind: 'fact', fact: 'spammer' }],
  ['MEMBERSHIP_REVIEW', 'Is a review of this membership waiting for a moderator?',
    'A review of this membership is waiting for a moderator.',
    { kind: 'fact', fact: 'membership_review' }],
  ['POSTING_STATUS', 'Has a moderator set a posting status for this member on this community?',
    "A moderator has set this member's posting status, so their posts wait for a moderator.",
    { kind: 'fact', fact: 'member_moderated' }],
  ['NO_LOCATION', 'Is the post missing a location? (The place the member gave for it, mapped to a point; not their IP address.)',
    'The post has no location, so nobody could find it by place.',
    { kind: 'fact', fact: 'no_location' }],
  ['OUTSIDE_UK', "Is the post's location (the place the member gave, mapped to a point) outside the UK?",
    "The post's location is outside the UK.",
    { kind: 'fact', fact: 'outside_uk' }],
  ['DUPLICATE_SAME', "Is this the member's own open post again, or a repost sooner than this community allows?",
    "This is the member's own open post again, or a repost sooner than this community allows.",
    { kind: 'fact', fact: 'duplicate' }],
  ['SPAM_LINKS', 'Does it contain a web link, a messaging link, or a link on the Spamhaus blocklist?',
    'It contains a link, which the spam checks flag.',
    { kind: 'fact', fact: 'spam_links' }],
  ['SPAM_PATTERN', 'Do the spam checks match it: sent in bulk, a subject repeated across many posts, a known spammer, a greeting-only post, or image spam?',
    'The spam checks matched it.',
    { kind: 'fact', fact: 'spam_pattern' }],
  ['LANGUAGE', 'Is it written in a language other than English?',
    'It is not written in English.',
    { kind: 'fact', fact: 'not_english' }],
  ['PERSONAL_INFO', 'Does it contain a phone number or email address, on a community that does not allow them?',
    'It contains a phone number or email address, which this community does not allow.',
    { kind: 'fact', fact: 'personal_info', requiresRule: 'restrictpersonalinfo' }],

  // What the words of the post say.
  ['DUPLICATE_ITEM', "Is it the same item as one of the member's other open posts?",
    "It is the same item as one of the member's other open posts on this community.",
    { kind: 'text', when: 'has_other_posts', context: 'other_posts', threshold: T,
      question: "Compare this post with the member's other open posts on this community, listed below. Is it the same item as one of them, even if worded differently?" }],
  ['VAGUE', 'Is it too vague: does it name no specific item?',
    'Too vague: Freegle asks members to name the items they want. Ask the member to say what the item is.',
    { kind: 'text', threshold: T, flags: ['vague'], hint: 'vague',
      question: 'Freegle asks members to name the items they want, for example "a sofa, a cooker or a bed". Is this post a general request that names no specific item - "anything", "various", "any dog items", or only a broad category like "furniture" or "electrical" - or, for an offer, is it unclear what is being given? A short post that names one specific item is fine.' }],
  ['NOT_AN_ITEM', 'Is it about something other than a physical item?',
    'It is not about a physical item. Freegle posts must offer or ask for items, to keep them out of landfill.',
    { kind: 'text', threshold: T, flags: ['notitem'],
      question: 'Freegle posts must offer or ask for physical items, to keep them out of landfill. Is this post about something else: a service, job, event, lift, room, help, a question, general chat, a code, voucher or ticket sent electronically, or an appeal for people to buy new goods to donate?' }],
  ['MONEY', 'Does it mention money, selling or buying?',
    'Mentions money, selling or buying. Everything on Freegle must be given entirely free.',
    { kind: 'text', threshold: T, flags: ['money'],
      question: 'Everything on Freegle must be given entirely free, with no strings, and a post must not leave readers wondering. Any mention counts, even when the item otherwise seems free, with one exception: the poster saying they bought it, or that it was expensive, in the past, with no amount of money given, is fine. Does this post mention money, selling or buying in any way: a price, cash, "ono", what it cost or is worth, a bargain, "selling" or "for sale" (even as a figure of speech, and even with no price), buying or a buyer, a "donation" or "contribution" to the poster, a reward, or payment for delivery, fuel or postage?' }],
  ['LISTING', "Does it read like a sale advert or a trader's listing?",
    "It reads like a sale advert or a trader's listing rather than a member giving something away.",
    // Offers only: a Wanted from a trader collecting stock is allowed where it is declared
    // (the declareselling rule), and is for moderators, not this question.
    { kind: 'text', threshold: T, when: 'is_offer', flags: ['listing'],
      question: 'Does this post read as a commercial sale advert or a trader\'s listing rather than a member giving something away or asking for something for themselves or their family: for example a listing copied from a sale site with "buyer", "condition:", "model:" or "RRP", paid delivery or transport offered, or a business or trader collecting stock? A private member asking for items for their own use or a relative is not a listing.' }],
  ['LOAN', 'Is it a loan or a request to borrow?',
    'Asks to borrow, or offers a loan. This community does not allow loans.',
    { kind: 'text', threshold: T, rule: 'allowloans', flags: ['loan'],
      question: 'Is this post asking to borrow something, or offering to lend something, so that it would be given back?' }],
  ['SWAP', 'Does it ask for something in exchange?',
    'Asks for something in exchange. Posts on Freegle must be free.',
    { kind: 'text', threshold: T, flags: ['swap'],
      question: 'Does this post mention swapping, trading or exchanging at all - another item, a favour or work in return - even as one option alongside giving or asking freely?' }],
  ['ILLEGAL', 'Is it illegal, counterfeit or stolen?',
    'It looks illegal to give away in the UK, counterfeit, or stolen.',
    { kind: 'text', threshold: T, flags: ['illegal'],
      question: 'Is this item illegal to own, give away or use in the UK, or counterfeit, pirated, stolen, or something that needs a licence the post does not mention?' }],
  ['HAZARDOUS', 'Is it a hazardous or restricted substance?',
    'It is a hazardous or restricted substance.',
    { kind: 'text', threshold: T, flags: ['hazardous'],
      question: 'Gas cylinders are not part of this question (each community has its own rule for them), and nor are ordinary household items such as lighters, matches, paint or cleaning products. Is this post about a hazardous or restricted substance: chemicals, pesticides or weedkiller, loose fuel, fireworks, asbestos, or anything else dangerous to hand over?' }],
  ['ANIMALS_OFFER', 'Is it offering a live animal?',
    'Offers a live animal for rehoming. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'animalsoffer', when: 'is_offer', flags: ['animals'],
      question: 'Is this post offering a live animal, such as a pet or livestock, for rehoming?' }],
  ['ANIMALS_WANTED', 'Is it asking for a live animal?',
    'Asks for a live animal. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'animalswanted', when: 'is_wanted', flags: ['animals'],
      question: 'Is this post asking for a live animal, such as a pet or livestock? A very short post naming a breed or kind of animal counts.' }],
  ['WEAPONS', 'Is it about a weapon?',
    'Mentions a weapon. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'weapons', flags: ['weapons'],
      question: 'Is this post about a weapon of any kind, including air guns, crossbows, swords and catapults?' }],
  ['FIREARMS', 'Is it about a firearm?',
    'Mentions a firearm. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'firearms', flags: ['weapons'],
      question: 'Is this post about a firearm, including an air rifle, air pistol or BB gun, or ammunition?' }],
  ['KNIVES', 'Is it about knives?',
    'Mentions knives. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'knives', flags: ['weapons'],
      question: 'Is this post about a knife or other bladed item, other than ordinary cutlery or kitchen knives in a set?' }],
  ['MEDICINE_RX', 'Is it about prescription medicine?',
    'Prescription medicine. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'medicationsprescription', flags: ['medicine'],
      question: 'Is this post about prescription medicine?' }],
  ['MEDICINE_OTC', 'Is it about non-prescription medicine, supplements or medical products?',
    'Non-prescription medicine, supplements or medical products. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'medicationsotc', flags: ['medicine'],
      question: 'Is this post about medicine you can buy without a prescription, health supplements such as vitamins or nutritional drinks, or medical products such as nasal sprays, inhalers and spacers, test kits or dressings?' }],
  ['MEDICINE_ANIMAL', 'Is it about medicine for animals?',
    'Medicine for animals. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'medicationsanimals', flags: ['medicine'],
      question: 'Is this post about medicine or treatments for animals, such as flea or worming treatments?' }],
  ['CONTACT_LENSES', 'Is it about contact lenses?',
    'Contact lenses. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'contactlenses', flags: ['lenses'],
      question: 'Is this post about contact lenses?' }],
  ['LENS_SOLUTION', 'Is it about contact lens solution?',
    'Contact lens solution. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'contactlensessolutions', flags: ['lenses'],
      question: 'Is this post about contact lens solution or saline?' }],
  ['ALCOHOL', 'Is it about alcohol?',
    'Alcohol. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'alcohol', flags: ['alcohol'],
      question: 'Is this post about alcoholic drink?' }],
  ['TOBACCO', 'Is it about tobacco?',
    'Tobacco. This community does not allow it.',
    { kind: 'text', threshold: T, rule: 'tobacco', flags: ['tobacco'],
      question: 'Is this post about tobacco or cigarettes?' }],
  ['VAPING', 'Is it about vaping?',
    'Vaping products. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'vaping', flags: ['vaping'],
      question: 'Is this post about vapes, e-cigarettes or e-liquid?' }],
  ['TICKETS', 'Is it about tickets, vouchers or coupons?',
    'Tickets, vouchers or coupons. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'tickets', flags: ['tickets'],
      question: 'Is this post about tickets, vouchers, coupons, gift cards or discount codes?' }],
  ['GAS', 'Is it about gas cylinders?',
    'Gas cylinders. This community does not allow them.',
    { kind: 'text', threshold: T, rule: 'gascylinders', flags: ['gas'],
      question: 'Is this post about gas cylinders or gas bottles, full or empty?' }],
  ['CONCERN', 'A concern word matched: is the post really about that concern?',
    'A concern word matched, and the post is about that concern.',
    { kind: 'text', threshold: T, when: 'concern_keyword', hint: 'concern_keyword',
      question: "One of Freegle's concern words matched this post (see the findings above). Is the post genuinely about that concern, rather than the word appearing in an innocent sense?" }],
];

const states = {
  START: { description: 'Entry point. Immediately continues to the first check.', nodeType: 'start' },
};
const transitions = [{ id: 'start', from: 'START', to: NODES[0][0], trigger: 'unconditional' }];

NODES.forEach(([id, description, hold, check], i) => {
  states[id] = { description, nodeType: 'tool', check };
  states[`HOLD_${id}`] = { description: hold, nodeType: 'end' };
  const next = i + 1 < NODES.length ? NODES[i + 1][0] : 'APPROVE';
  transitions.push(
    { id: `${id}_yes`, from: id, to: `HOLD_${id}`, trigger: 'host_driven', condition: 'yes', label: 'yes', metadata: { answer: 'yes' } },
    { id: `${id}_no`, from: id, to: next, trigger: 'host_driven', condition: 'no', label: 'no', metadata: { answer: 'no' } },
  );
});
states.APPROVE = { description: "Nothing in this post needs a moderator's attention.", nodeType: 'end' };

const chart = {
  id: 'freegle-automod',
  name: 'Freegle automated review',
  version: '5',
  description: 'Fact and text checks that decide whether a Pending post can be approved automatically (plans/active/automod-flowchart.md). Built by scripts/build-chart.mjs.',
  initialState: 'START',
  states,
  transitions,
};

const out = join(dirname(fileURLToPath(import.meta.url)), '..', 'chart.json');
writeFileSync(out, JSON.stringify(chart, null, 2) + '\n');
console.log(`wrote ${out}: ${NODES.length} questions, version ${chart.version}`);
