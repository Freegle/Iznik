// The notes shown under the item box when someone posts. The desktop form (PostItem) and the phone and
// app forms (pages/give/mobile/details, pages/ask/mobile/details) all show them, so they live here.
//
// A keyword matches anywhere in the item, case aside, as long as it ends where a word ends; a plural
// in -s or -es counts. So "free" does not match "freezer" and "child car" does not match "child
// carrier", while "stool" still matches "footstool". Phrases in a note's exclude list are taken out of
// the item before its keywords are tried.

export const ITEM_WARNINGS = [
  // First, so that any other note wins over it: the last match is the one shown.
  {
    type: 'Free',
    message:
      "Everything on Freegle is given away freely.  You don't need to say that - and it keeps things simpler if you remove it.",
    keywords: ['free', 'giving away'],
  },
  {
    type: 'Upholstered household items and furniture',
    message:
      "There is no requirement for freegled items to have fire labels, but please be honest in your description or make sure you don't ask for things that aren't suitable for your use.",
    keywords: [
      'sofa',
      'sofabed',
      'couch',
      'settee',
      'armchair',
      'headboard',
      'stool',
      'futon',
      'mattress',
      'mattress',
      'pillow',
      'cushion',
      'seat pad',
    ],
  },
  {
    type: 'Cot Mattress',
    message:
      'To be safe mattresses should be clean, dry and free from fabric tears, fit the cot snugly, with no gaps, firm and with no sagging.',
    keywords: ['cot mattress'],
  },
  {
    type: 'Motorcycle and cycle helmets',
    message:
      'Using helmets that have been involved in a crash is not recommended.',
    keywords: ['helmet'],
  },
  {
    // Kept in step with childCarSeatTerms and childCarSeatExclusions in
    // iznik-server-go/chat/replyconfirm.go, which asks anyone replying to one of these to check the
    // same things before their reply goes through.
    type: 'Car seats',
    message:
      'Car seats have a limited life: plastics degrade, some seats expire after five years, and a seat should never be used again after an accident. Please say the make and model, how old it is, and that it has never been in a crash. More at childcarseats.org.uk.',
    wantedMessage:
      'Second-hand car seats can be unsafe: plastics degrade, some seats expire after five years, and a seat should never be used again after an accident. Ask for the make, model and age, and whether it has ever been in a crash. More at childcarseats.org.uk.',
    keywords: [
      'car seat',
      'carseat',
      'child car',
      'booster seat',
      'isofix',
      'infant carrier',
    ],
    // A cover, or a seat for a dog, is not a child car seat.
    exclude: [
      'seat cover',
      'dog car seat',
      'dog booster seat',
      'dog seat',
      'pet car seat',
      'pet booster seat',
      'pet seat',
    ],
  },
  {
    type: 'Knives',
    message:
      "Knives should only be given to those over 18 years of age, and must be collected and handed over in person (not left for collection in an agreed safe place). Knives shouldn't be carried openly on the street, so should be wrapped or in a container.",
    keywords: ['knife', 'knives', 'sword', 'swords'],
  },
  {
    type: 'Sealed and in date',
    message: 'Please make sure this is sealed and in date.',
    keywords: [
      'contact lens solution',
      'contact lens fluid',
      'contact lens cleaner',
      'lens solution',
      'lens fluid',
      'cosmetics',
      'make up',
      'makeup',
      'mascara',
      'sunscreen',
      'sun cream',
      'suncream',
      'sun lotion',
      'aftersun',
    ],
  },
  {
    type: 'Banned Plants',
    message:
      "This looks like a plant that is categorised as an invasive species, which can't be given away on Freegle.",
    keywords: [
      'alligator weed',
      'American skunk cabbage',
      'Asiatic tearthumb',
      'balloon vine',
      'broadleaf water milfoil',
      'broadleaf watermilfoil',
      'broomsedge',
      'Chilean rhubarb',
      'Chinese bush clover',
      'Chinese bushclover',
      'Chinese shrub clover',
      'Chinese tallow',
      'climbing fern',
      'crimson fountain grass',
      'curly water weed',
      'curly waterweed',
      'Eastern baccharis',
      'fanwort',
      'floating pennywort',
      'floating water primrose',
      'giant hogweed',
      'giant rhubarb',
      'giant salvinia',
      'golden wreath wattle',
      'Himalayan balsam',
      'Japanese hop',
      'Japanese stiltgrass',
      'kudzu',
      'mesquite',
      'mile-a-minute',
      'milkweed',
      "Nuttall's water weed",
      "Nuttall's waterweed",
      'Nuttalls waterweed',
      "parrot's feather",
      'parrots feather',
      'parthenium weed',
      'perennial veldt grass',
      'perennial veldtgrass',
      'Persian hogweed',
      'purple pampas grass',
      'purple veldt grass',
      'purple veldtgrass',
      'Salvinia moss',
      'Senegal tea',
      "Sosnowsky's hogweed",
      'tree groundsel',
      'tree of heaven',
      'Tromso palm',
      'Tromsø palm',
      'vine-like fern',
      'water hyacinth',
      'water primrose',
      'water shield',
      'whitetop weed',
      'yellow skunk cabbage',
    ],
  },
]

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function wordPattern(phrase) {
  return new RegExp(escapeRegExp(phrase.toLowerCase()) + '(?:e?s)?\\b', 'g')
}

const compiled = ITEM_WARNINGS.map((w) => ({
  warning: w,
  keywords: w.keywords.map(wordPattern),
  exclude: (w.exclude || []).map(wordPattern),
}))

// The note for an item, or null. When several match, the last in the list is the one shown.
export function findItemWarning(item) {
  if (!item) return null

  const text = item.toLowerCase()
  let ret = null

  for (const c of compiled) {
    const rest = c.exclude.reduce((t, re) => t.replace(re, ' '), text)

    if (c.keywords.some((re) => rest.search(re) !== -1)) {
      ret = c.warning
    }
  }

  return ret
}

// A note can say something different to someone asking for the item than to someone offering it.
export function itemWarningMessage(warning, type) {
  if (!warning) return null

  if (type === 'Wanted' && warning.wantedMessage) {
    return warning.wantedMessage
  }

  return warning.message
}
