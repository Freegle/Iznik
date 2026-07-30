// Content for the /compare/* pages: friendly, honest "Freegle vs ..." write-ups.
//
// Tone: British English, fairly informal, warm. NOT a marketing spec sheet. The
// through-line on every page is that we're glad when stuff gets reused, whoever
// you do it with. Honesty is deliberate: a fair comparison is more use to a reader
// (and gets picked up by search and AI answers) than a puff piece.
//
// Each entry writes its OWN `differences` rather than sharing a pool of stock
// paragraphs, so the pages don't all read the same. Pick the contrasts that
// actually matter for that competitor.
//
// NB competitor facts are drafted and want a final check before publishing.

// Reused at the foot of every comparison: the gracious note.
export const sharedClose =
  'Honestly, we just like seeing things reused instead of binned. If the other lot ' +
  'work better for you, grand, use them with our blessing. And if you fancy giving ' +
  'Freegle a go too, come and say hello.'

export const comparisons = {
  'trash-nothing': {
    slug: 'trash-nothing',
    name: 'Trash Nothing',
    title: 'Freegle vs Trash Nothing',
    metaDescription:
      'An honest look at Freegle and Trash Nothing: how they’re related, what’s ' +
      'different, and which might suit you. Both free, both about reuse.',
    intro:
      'Trash Nothing is a free app for giving and getting stuff, and it’s actually ' +
      'closely tied in with us. A lot of what you see on Trash Nothing is Freegle ' +
      'posts, because it works as another way in to Freegle communities (alongside ' +
      'some other networks it pulls together).',
    whichToUse:
      'Genuinely, it’s up to you, whichever you prefer. If you like the Trash Nothing ' +
      'app and it does the job, that’s great, you’re still reaching the Freegle ' +
      'community. Using Freegle directly means you’re supporting the charity that ' +
      'actually runs the communities, and you get the community bits all in one ' +
      'place. But we’re not precious about it.',
    differences: [
      'The communities you’re posting into are ours. The local volunteers who approve ' +
        'posts, answer questions and sort out problems are Freegle volunteers, and the ' +
        'charity foots the bill for running the whole thing. Trash Nothing is a ' +
        'separate, ad-funded organisation that shows those posts alongside a few other ' +
        'networks.',
      'The community side of it lives here. Chit-chat with your neighbours, community ' +
        'events like repair cafés and swap shops, volunteering, the stories people ' +
        'share about what they’ve reused. An app shows you the stuff; Freegle is the ' +
        'community the stuff comes from.',
      'You never need to do anything twice. Your Freegle post is already shared with ' +
        'Trash Nothing, so posting it once here reaches both lots of people.',
    ],
    atAGlance: [
      {
        point: 'What it is',
        freegle: 'The charity that runs the local communities',
        them: 'An app showing Freegle and other listings',
      },
      { point: 'Cost', freegle: 'Free, no selling', them: 'Free' },
      {
        point: 'Community side',
        freegle: 'Built in',
        them: 'More of a listings app',
      },
      {
        point: 'Where your support goes',
        freegle: 'The reuse charity',
        them: 'A separate organisation',
      },
    ],
    faqs: [
      {
        q: 'Is Trash Nothing the same as Freegle?',
        a: 'No, it’s a separate organisation. But it shows Freegle listings, so a lot of what you see on it comes from the Freegle community.',
      },
      {
        q: 'Will I see the same stuff on both?',
        a: 'Largely yes, because Trash Nothing displays Freegle posts alongside other networks.',
      },
      {
        q: 'Should I post on both?',
        a: 'No need. Post it once on Freegle and it turns up on Trash Nothing anyway, because it carries Freegle posts. Posting the same thing twice just makes duplicates and more replies for you to keep on top of.',
      },
    ],
  },

  olio: {
    slug: 'olio',
    name: 'Olio',
    title: 'Freegle vs Olio',
    metaDescription:
      'Freegle and Olio both help things get reused. Olio started with spare food; ' +
      'Freegle’s the free-reuse community for everything else. Here’s the honest version.',
    intro:
      'Olio started life as a lovely way to share spare food so it didn’t get thrown ' +
      'away, and it’s still the go-to for that, with a proper network of volunteers ' +
      'who collect surplus from shops. These days it does other household bits too, ' +
      'and even a bit of selling.',
    whichToUse:
      'If it’s food you’re sharing or after, Olio is hard to beat, and we’d happily ' +
      'point you their way. For everything else, the wardrobe, that spare drill, a bag ' +
      'of the kids’ outgrown clothes, Freegle is where the reuse community really is, ' +
      'and you’ll reach more people for the bigger stuff.',
    differences: [
      'Unlike Olio, there are no investors to keep happy here. Freegle’s a charity, ' +
        'funded by donations and run mostly by volunteers, so nobody needs us to grow, ' +
        'sell or turn a profit. Olio has raised tens of millions in venture capital, ' +
        'which is money that has to find its way back somehow. That’s not a dig, it’s ' +
        'just a different set of pressures from ours.',
      'Everything here is free, always. No selling, no listing fees, no “or best ' +
        'offer”. Olio has added buying and selling alongside the giving away, and we’ve ' +
        'deliberately stayed out of that, so you never have to work out which bits cost ' +
        'money.',
      'We’re fussy about what counts as “local”. Olio has you pick a distance, ' +
        'anything from about a third of a mile up to sixteen, and shows you whatever ' +
        'falls inside that circle. We work out where you could realistically get to by ' +
        'road instead. So if you’re in Bristol we won’t tempt you with a sofa that ' +
        'looks close on the map but is over the water in Wales and an hour over the ' +
        'bridge. Handy stuff shows up first, and it only spreads further afield if ' +
        'nobody nearby takes it.',
    ],
    atAGlance: [
      {
        point: 'Best known for',
        freegle: 'Reusing anything and everything',
        them: 'Sharing spare food',
      },
      { point: 'How you use it', freegle: 'Web, email and app', them: 'App' },
      {
        point: 'Cost',
        freegle: 'Free',
        them: 'Free to share, plus some selling',
      },
      {
        point: 'Behind it',
        freegle: 'A reuse charity',
        them: 'A venture-backed startup',
      },
    ],
    faqs: [
      {
        q: 'Is Olio just for food?',
        a: 'It started with food and that’s still its strength, though it does other items now too.',
      },
      {
        q: 'Which is better for furniture and bigger items?',
        a: 'Freegle, generally. That’s the bread and butter of the reuse community here.',
      },
      {
        q: 'Are they both free?',
        a: 'Sharing on Olio is free, though it now has some paid selling mixed in too. On Freegle, everything is free, always.',
      },
    ],
  },

  freecycle: {
    slug: 'freecycle',
    name: 'Freecycle',
    title: 'Freegle vs Freecycle',
    metaDescription:
      'Freegle and Freecycle share the same idea and history. Here’s the friendly, ' +
      'honest difference, and which one covers your town.',
    intro:
      'Freecycle is the original free-reuse network, started in the US back in 2003. ' +
      'Freegle actually grew out of it. We were set up in 2009 by the UK volunteers ' +
      'who wanted something run here, for here.',
    whichToUse:
      'Same lovely idea, both free, neither lets you sell. In the UK, Freegle tends to ' +
      'have more members, UK-based volunteers and support, and, we’d like to think, a ' +
      'nicer website and apps. Outside the UK, Freecycle is your option.',
    differences: [
      'We’re UK-only, and that’s rather the point. The volunteers, the support, and ' +
        'the people who answer when something goes wrong are all here. Freecycle is a ' +
        'worldwide network with the UK as one corner of it.',
      'Freecycle works on towns: you find yours, join it, and that’s your patch, up to ' +
        'five of them if you live near a boundary. We don’t make you guess. Your post ' +
        'starts near you and spreads outwards along the roads if nobody nearby takes ' +
        'it, so somebody two streets the wrong side of a town boundary still gets a ' +
        'look in, without having had to join the right list first.',
      'We build our own website and apps and keep chipping away at them. Freecycle has ' +
        'never had an official app of its own, so people tend to use a third-party one ' +
        'or plain email.',
    ],
    atAGlance: [
      { point: 'Where it works', freegle: 'UK', them: 'Worldwide' },
      {
        point: 'Started',
        freegle: '2009, by UK volunteers',
        them: '2003, in the US',
      },
      {
        point: 'Run by',
        freegle: 'A UK charity',
        them: 'A US-based non-profit',
      },
      { point: 'Cost', freegle: 'Free, no selling', them: 'Free, no selling' },
    ],
    faqs: [
      {
        q: 'Is Freegle the same as Freecycle?',
        a: 'No. Freegle is a separate UK charity, set up in 2009 by former UK Freecycle volunteers.',
      },
      {
        q: 'Which one covers my UK town?',
        a: 'Almost certainly Freegle. Most UK towns have a Freegle community.',
      },
      {
        q: 'Are both free?',
        a: 'Yes, both are completely free and neither allows selling.',
      },
    ],
  },

  lovejunk: {
    slug: 'lovejunk',
    name: 'LoveJunk',
    title: 'Freegle vs LoveJunk',
    metaDescription:
      'Freegle and LoveJunk work together: free Freegle offers get shared over to ' +
      'LoveJunk. Here’s the honest difference between free reuse and paid clearance.',
    intro:
      'LoveJunk is a UK marketplace for getting rubbish shifted. You list what needs ' +
      'taking away, licensed waste collectors bid for the job, and you pick a quote. ' +
      'It also has a free section, and that’s the bit we’re plugged into: when you ' +
      'offer something free on Freegle, we share it over to LoveJunk so their users ' +
      'can see it and reply.',
    whichToUse:
      'Depends what you’ve got. If somebody would actually want the thing, offer it on ' +
      'Freegle. It costs nothing, and because we pass free offers over to LoveJunk you ' +
      'reach their people too, without doing anything extra. If it’s genuinely waste, ' +
      'a broken bed frame, a pile of rubble, a shed of odds and ends you need gone by ' +
      'Friday, LoveJunk’s paid clearance is a sensible shout, and a lot better than it ' +
      'ending up fly-tipped down a lane.',
    differences: [
      'We’re partners rather than rivals, but it only runs one way. Your free Freegle ' +
        'offers get shared over to LoveJunk so their users can see them and reply. It ' +
        'doesn’t come back the other way, so free things listed on LoveJunk don’t turn ' +
        'up here. Which is a long way of saying: post it on Freegle and you reach both.',
      'Nobody pays anybody on our side. LoveJunk’s main business is paid clearance, ' +
        'where you pay a collector and LoveJunk takes a cut of it. On Freegle the ' +
        'person who turns up actually wants your thing and is pleased to have it, and ' +
        'no money changes hands in either direction.',
      'It goes to a neighbour, not into a van. A Freegle item goes to someone local ' +
        'who’s been after exactly that, which is why people send us photos of it ' +
        'sitting in their front room afterwards. That’s a different thing from a ' +
        'collector taking it away to be dealt with, even when it does still get reused.',
    ],
    atAGlance: [
      {
        point: 'Mainly for',
        freegle: 'Reusing things somebody wants',
        them: 'Getting rubbish taken away',
      },
      {
        point: 'Cost',
        freegle: 'Free, always',
        them: 'You pay the collector; free section too',
      },
      {
        point: 'Who collects',
        freegle: 'A neighbour who wants it',
        them: 'A licensed waste collector, or a reuser',
      },
      {
        point: 'Your free offers',
        freegle: 'Posted here',
        them: 'Shared over from Freegle',
      },
    ],
    faqs: [
      {
        q: 'Should I post on both?',
        a: 'No need. Offer it on Freegle and we share it over to LoveJunk, so their users see it too. Posting separately on both just makes duplicates.',
      },
      {
        q: 'Do LoveJunk’s free items show up on Freegle?',
        a: 'No. The sharing only goes from Freegle to LoveJunk, not the other way round.',
      },
      {
        q: 'I just want it gone. Does it matter which I use?',
        a: 'If it’s usable, try Freegle first. It costs nothing and somebody ends up with something they need. If it’s really waste, LoveJunk will get it taken away properly and legally.',
      },
    ],
  },

  'facebook-marketplace': {
    slug: 'facebook-marketplace',
    name: 'Facebook Marketplace',
    title: 'Freegle vs Facebook Marketplace',
    metaDescription:
      'Plenty of free stuff changes hands on Facebook Marketplace. Here’s an honest ' +
      'look at how it compares with Freegle, warts and all.',
    intro:
      'Facebook Marketplace is huge, and loads of free stuff changes hands there. ' +
      'It’s handy if you’re already on Facebook and happy to mix free items in with ' +
      'buying and selling.',
    whichToUse:
      'Marketplace has reach, no arguing with that. But it mixes selling in with the ' +
      'freebies, it’s built around ads and your data, and scams are a lot more common. ' +
      'Freegle is free-only, run by a charity, and we put real effort into keeping the ' +
      'dodgy stuff out. If Marketplace works for you and things get reused, we’re ' +
      'still glad, but for giving and getting for free, we think Freegle’s the kinder ' +
      'place to be.',
    differences: [
      'There’s somebody in the middle here. A mix of volunteer moderators and ' +
        'automated checks keeps most of the scammers and spammers away from you in the ' +
        'first place. Marketplace has no such layer, which is rather why almost all of ' +
        'their safety advice is about how to protect yourself.',
      'We’re a charity, not an advertising business. You don’t need a Facebook account ' +
        'to use Freegle, and we’re not quietly building a profile of you to sell ads ' +
        'against.',
      'Freegle only does the one thing. Everything on it is free and nothing is for ' +
        'sale, so you’re not sifting the freebies out of a shop. On Marketplace, “free” ' +
        'is a filter on a marketplace that mostly wants to sell you something.',
    ],
    atAGlance: [
      {
        point: 'What it’s for',
        freegle: 'Free reuse only',
        them: 'Buying, selling and free, mixed',
      },
      {
        point: 'Ads and data',
        freegle: 'Minimal, we’re a charity',
        them: 'Ad-funded, data-driven',
      },
      {
        point: 'Scam protection',
        freegle: 'Active moderation and checks',
        them: 'Largely down to you',
      },
      {
        point: 'Account needed',
        freegle: 'A Freegle account',
        them: 'A Facebook account',
      },
    ],
    faqs: [
      {
        q: 'Is Freegle free like Marketplace’s free section?',
        a: 'Yes, and on Freegle everything is free, there’s no selling at all.',
      },
      {
        q: 'Do I need a Facebook account for Freegle?',
        a: 'No. Freegle is completely separate and works on the web, by email or via our app.',
      },
      {
        q: 'Can I sell things on Freegle?',
        a: 'No, Freegle is just for giving and getting things for free.',
      },
    ],
  },

  gumtree: {
    slug: 'gumtree',
    name: 'Gumtree',
    title: 'Freegle vs Gumtree',
    metaDescription:
      'Gumtree is a classifieds site with a freebies corner. Freegle is built only ' +
      'for free reuse. An honest look at the difference.',
    intro:
      'Gumtree is a big UK classifieds site, mostly for buying and selling, cars, ' +
      'furniture, flats, jobs, all sorts. It has a freebies section too, where people ' +
      'list things to give away.',
    whichToUse:
      'If you’re happy digging through a general classifieds site and keeping your ' +
      'wits about you, Gumtree’s freebies can turn up the odd gem. But free reuse is a ' +
      'small corner of a selling site there. On Freegle it’s the whole point, ' +
      'everything’s free, and we do a lot more to keep the scammers off your back.',
    differences: [
      'Free is a side-line for them. Gumtree is first and foremost a place to buy and ' +
        'sell, and the freebies sit in one corner of it. Freegle only does free, so ' +
        'you’re not panning for giveaways in a river of paid listings.',
      'Have a look at their own safety pages sometime. They’re thick with warnings ' +
        'about courier scams, fake payment links and the like, because on an open ' +
        'classifieds site you’re largely on your own. We put volunteer moderators and ' +
        'automated checks between you and most of that.',
      'It’s a neighbour, not a transaction. A Freegle item goes to a local who ' +
        'actually wanted it, and often you’ll hear how they got on with it. Gumtree is ' +
        'built around the deal, free or not.',
    ],
    atAGlance: [
      {
        point: 'What it’s for',
        freegle: 'Free reuse only',
        them: 'Classifieds: buying, selling, freebies',
      },
      {
        point: 'The free bit',
        freegle: 'The whole site',
        them: 'One category among many',
      },
      {
        point: 'Scam protection',
        freegle: 'Active moderation and checks',
        them: 'Mostly your own lookout',
      },
      {
        point: 'Behind it',
        freegle: 'A reuse charity',
        them: 'A commercial classifieds site',
      },
    ],
    faqs: [
      {
        q: 'Is Freegle like Gumtree’s freebies?',
        a: 'Same idea, giving things away, but on Freegle everything is free and it’s built for reuse, not tucked inside a selling site.',
      },
      {
        q: 'Which is safer?',
        a: 'Freegle, we’d say. We actively moderate; Gumtree leaves most of the caution to you, which is rather why their help pages are full of scam warnings.',
      },
      {
        q: 'Can I sell on Freegle?',
        a: 'No, Freegle is free-only. If you want to sell, that’s what Gumtree’s main site is for.',
      },
    ],
  },

  nextdoor: {
    slug: 'nextdoor',
    name: 'Nextdoor',
    title: 'Freegle vs Nextdoor',
    metaDescription:
      'Nextdoor is a neighbourhood social network with a free-and-for-sale corner. ' +
      'Freegle is built only for free reuse. Here’s the honest difference.',
    intro:
      'Nextdoor is a neighbourhood social network, the place for local notices, ' +
      'recommendations and the occasional heated thread about parking. It has a “For ' +
      'Sale & Free” section where people give things away or sell them.',
    whichToUse:
      'If you’re already on Nextdoor and something free pops up nearby, grab it, ' +
      'that’s reuse and we’re glad of it. But giving things away is a small bolt-on to ' +
      'a social network there. On Freegle it’s the only thing we do, and you don’t ' +
      'have to join the local chatter to take part.',
    differences: [
      'Reuse is a side feature for them. Nextdoor is a social network first, with ' +
        'giving and selling bolted on. Freegle exists for one reason, keeping usable ' +
        'things out of the bin, and that’s all it does.',
      'Nextdoor leans on knowing your name and address, and that sounds reassuring ' +
        'until you notice it doesn’t actually stop anyone dodgy, it just knows where ' +
        'they live. Our trust comes from local volunteers who moderate posts and sort ' +
        'out problems, which is a different, and we’d argue more useful, kind of safe.',
      'You don’t have to sign up to the neighbourhood to take part. Freegle is just ' +
        'about the giving and getting, so you’re not opting into local politics and ' +
        'notifications to rehome a bookcase.',
    ],
    atAGlance: [
      {
        point: 'What it’s for',
        freegle: 'Free reuse only',
        them: 'A neighbourhood social network',
      },
      {
        point: 'Reuse is',
        freegle: 'The whole thing',
        them: 'One feature among many',
      },
      {
        point: 'Trust comes from',
        freegle: 'Volunteer moderation',
        them: 'Name and address verification',
      },
      {
        point: 'Behind it',
        freegle: 'A reuse charity',
        them: 'An ad-funded social network',
      },
    ],
    faqs: [
      {
        q: 'Do I need a Nextdoor account to use Freegle?',
        a: 'No, they’re completely separate. Freegle works on the web, by email or via our app.',
      },
      {
        q: 'Isn’t Nextdoor safer because it checks your address?',
        a: 'It knows who you are, which isn’t quite the same as keeping you safe. Freegle relies on volunteers actively moderating, which tends to catch the actual problems.',
      },
      {
        q: 'Is Freegle just for my street?',
        a: 'No. Your post starts nearby and spreads outwards along the roads, so it isn’t boxed into one neighbourhood.',
      },
    ],
  },
}

export const comparisonList = Object.values(comparisons)
