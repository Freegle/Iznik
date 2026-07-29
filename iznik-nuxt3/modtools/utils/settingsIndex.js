/**
 * Searchable index of ModTools settings.
 *
 * ModTools has ~70 settings spread over three tabs, and the Community ones sit
 * inside accordion sections that only render once a group is selected. That
 * means the search index can't be scraped from the DOM - it has to be declared
 * here, separately from the rendering.
 *
 * Each entry's `id` matches the `data-setting-id` attribute on the rendered
 * control, which is how we scroll to and highlight a result. Keeping the two in
 * step is enforced by tests/unit/components/modtools/settingsIndex.spec.js -
 * add a setting to a tab and the test will fail until it's indexed here.
 *
 * `keywords` is optional and holds terms mods might search for that don't
 * appear in the label or description.
 */
export const SETTINGS_INDEX = [
  {
    id: 'tagline',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'Tagline',
    description:
      'This should be short and snappy. Include some local reference that people in your area will feel connected to.',
  },
  {
    id: 'welcomemail',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'Welcome email',
    description:
      "This is emailed out to new members. Keep it short and positive (use 'do' not 'don't'). Make it LOCAL - only things specific to your community. Don't repeat what Freegle already tells every new member centrally (how to Give, Browse or Find; that items must be free and legal; being nice; staying safe; changing settings) - that is all in the standard welcome email and on the website. Good things to include: a warm local welcome, any genuinely local rules (e.g. a local collection point or a charity you work with), or a short note from your volunteers.",
  },
  {
    id: 'settings.keywords.offer',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'OFFER keyword',
  },
  {
    id: 'settings.keywords.taken',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'TAKEN keyword',
  },
  {
    id: 'settings.keywords.wanted',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'WANTED keyword',
  },
  {
    id: 'settings.keywords.received',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'RECEIVED keyword',
  },
  {
    id: 'settings.communityevents',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Community Events',
    description: 'Whether members can post local community events.',
  },
  {
    id: 'settings.volunteering',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Volunteer Opportunities',
    description: 'Whether members can post requests for volunteers.',
  },
  {
    id: 'settings.stories',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Stories',
    description:
      'Whether members are prompted to tell us their Freegle Story for publicity.',
  },
  {
    id: 'settings.allowedits.moderated',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Moderated members can edit?',
    description:
      'When this setting is Yes (for most communities), moderated members can edit their own posts; edits go live immediately but are retrospectively reviewed from Messages->Edits. When this setting is No, moderated members cannot edit.',
  },
  {
    id: 'settings.allowedits.group',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Members on Group Settings can edit?',
    description:
      'When this setting is Yes (for most groups), members on Group Settings can edit their own posts; edits go live immediately. When this setting is No, members on Group Settings cannot edit.',
  },
  {
    id: 'settings.relevant',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Send relevant messages to members?',
    description:
      'Email specific messages to members based on their searches and posting history. Members can turn this on/off themselves, so you would only turn this off if you want to override their decision.',
  },
  {
    id: 'settings.newsfeed',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Send occasional digests of chitchat to members?',
    description:
      'We can send an occasional mail to members of recent activity from other members on ChitChat (like the old cafe groups). This encourages them to take part. Members can turn this off themselves, so you would only turn this off if you want to override their decision.',
  },
  {
    id: 'settings.newsletter',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Send newsletters to members?',
    description:
      'Email occasional newsletters to members. Members can turn this on/off themselves, so you would only turn this off if you want to override their decision.',
  },
  {
    id: 'settings.engagement',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Member engagement?',
    description:
      'We take various steps to nudge members to become more active freeglers. This may result in them receiving occasional emails/notifications. Members can turn this on/off themselves, so you would only turn this off if you want to override their decision.',
  },
  {
    id: 'settings.maxagetoshow',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Expire posts',
    description:
      'Posts will be considered as expired (i.e. no longer available) after the greater of this number of days and the maximum duration of autoreposts (i.e. max * repost time). Set to 0 to use the default of 30 days. Max 90 days.',
  },
  {
    id: 'settings.reposts.chaseups',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Chaseup',
    description:
      "Ask what's happening with the item this number of days after the last reply (0 to disable)",
  },
  {
    id: 'settings.reposts.max',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Max auto-reposts',
    description:
      'Auto-reposting is proven to help more posts get replies. We mail the member before auto-reposting so that they can choose what happens. 0 to disable.',
  },
  {
    id: 'settings.reposts.offer',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'OFFER repost time',
    description:
      'Controls when the member can manually repost, and when auto-repost kicks in. 0 = always show manual Repost button.',
  },
  {
    id: 'settings.reposts.wanted',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'WANTED repost time',
    description:
      'Controls when the member can manually repost, and when auto-repost kicks in. 0 = always show manual Repost button.',
  },
  {
    id: 'settings.showjoin',
    tab: 'community',
    section: 'accordion-features-members',
    sectionLabel: 'Features for Members',
    label: 'Show Join button?',
    description:
      "On the map in Browse, Join buttons will display below the map. You can control how far from the member's own location (i.e. their postcode) this community will show. You can use this to discourage people joining from further away, but please remember that many people commute to work, might be moving house, or have friends/family/partners in different locations. In miles, 0 = always show.",
  },
  {
    id: 'settings.moderated',
    tab: 'community',
    section: 'accordion-features-mods',
    sectionLabel: 'Features for Moderators',
    label: 'All Posts Moderated',
    description:
      'When this setting is No (for most groups), all new members are Moderated and members can be changed to Group Settings (meaning unmoderated) once they have made a valid post. When this setting is Yes, all posts must be moderated no matter what setting the user has.',
  },
  {
    id: 'settings.autoadmins',
    tab: 'community',
    section: 'accordion-features-mods',
    sectionLabel: 'Features for Moderators',
    label: 'Suggest ADMINs?',
    description:
      "Freegle has a selection of ADMINs which you can adapt to your group, which we can suggest from time to time. You can edit or delete each suggested ADMIN, so you'd only turn this off if you never wanted to even seen them.",
  },
  {
    id: 'settings.widerchatreview',
    tab: 'community',
    section: 'accordion-features-mods',
    sectionLabel: 'Features for Moderators',
    label: 'Quicker Chat Review?',
    description:
      "Chat messages between members may get sent for review to check they're ok. They will always show for you if the recipient is a member of your group. You can choose whether they are also shown to other Freegle mods who have chosen this setting, in which case they can be approved quickly if they are innocent (but can't be rejected). Otherwise they'll only show for mods on the group. NB this function is not active yet - see https://discourse.ilovefreegle.org/t/messages-delayed-in-chat-review/4633/82 for details",
  },
  {
    id: 'microvolunteering',
    tab: 'community',
    section: 'accordion-microvolunteering',
    sectionLabel: 'Microvolunteering',
    label: 'Allow members to perform small and useful tasks?',
    description: 'Is microvolunteering enabled?',
  },
  {
    id: 'microvolunteeringoptions.approvedmessages',
    tab: 'community',
    section: 'accordion-microvolunteering',
    sectionLabel: 'Microvolunteering',
    label: 'Review approved messages',
    description:
      "Members may be shown approved messages which haven't been reviewed and asked to say if they're OK or not. Messages which aren't OK will show in Messages->Review for mods to check.",
  },
  {
    id: 'microvolunteeringoptions.wordmatch',
    tab: 'community',
    section: 'accordion-microvolunteering',
    sectionLabel: 'Microvolunteering',
    label: 'Match similar items',
    description:
      'Members may be shown popular items and asked to choose two which are similar. This data will be used to improve our search.',
  },
  {
    id: 'microvolunteeringoptions.photorotate',
    tab: 'community',
    section: 'accordion-microvolunteering',
    sectionLabel: 'Microvolunteering',
    label: 'Photo rotate',
    description:
      'Members may be shown photos and asked to click to rotate any which are wrong.',
  },
  {
    id: 'settings.spammers.messagereview',
    tab: 'community',
    section: 'accordion-spam',
    sectionLabel: 'Spam Detection',
    label: 'Check for spam messages to group?',
    description:
      'Check posted messages and put possible spam in Messages->Spam?',
  },
  {
    id: 'settings.spammers.replydistance',
    tab: 'community',
    section: 'accordion-spam',
    sectionLabel: 'Spam Detection',
    label: 'Reply distance check?',
    description:
      'When members reply to messages which are this far apart, in miles, then they may be flagged for review. Default 100, 0 to disable.',
  },
  {
    id: 'settings.spammers.worrywords',
    tab: 'community',
    section: 'accordion-spam',
    sectionLabel: 'Spam Detection',
    label: 'Worry words?',
    description:
      "List of 'worry words' to force posts to Messages->Pending. Separate by commas.",
  },
  {
    id: 'settings.duplicates.check',
    tab: 'community',
    section: 'accordion-dups',
    sectionLabel: 'Duplicate Detection',
    label: 'Flag duplicate messages?',
    description: 'We can flag messages which look the same in ModTools.',
  },
  {
    id: 'settings.duplicates.offer',
    tab: 'community',
    section: 'accordion-dups',
    sectionLabel: 'Duplicate Detection',
    label: 'OFFER duplicate period',
  },
  {
    id: 'settings.duplicates.taken',
    tab: 'community',
    section: 'accordion-dups',
    sectionLabel: 'Duplicate Detection',
    label: 'TAKEN duplicate period',
  },
  {
    id: 'settings.duplicates.wanted',
    tab: 'community',
    section: 'accordion-dups',
    sectionLabel: 'Duplicate Detection',
    label: 'WANTED duplicate period',
  },
  {
    id: 'settings.duplicates.received',
    tab: 'community',
    section: 'accordion-dups',
    sectionLabel: 'Duplicate Detection',
    label: 'RECEIVED duplicate period',
  },
  {
    id: 'cga',
    tab: 'community',
    section: 'accordion-maps',
    sectionLabel: 'Mapping',
    label: 'Core Group Area (CGA)',
    description: "This is the area that the community 'owns'.",
  },
  {
    id: 'dpa',
    tab: 'community',
    section: 'accordion-maps',
    sectionLabel: 'Mapping',
    label: 'Default Posting Area (DPA)',
    description:
      'This is the area for which this the community is the default one suggested for freeglers to use.',
  },
  {
    id: 'settings.map.zoom',
    tab: 'community',
    section: 'accordion-maps',
    sectionLabel: 'Mapping',
    label: 'Default zoom for maps',
    description:
      'Where we show maps on the site for this community, which Google zoom level should we use?',
  },
  {
    id: 'publish',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'Enabled on website?',
    description: 'Is this available for people to use?',
  },
  {
    id: 'onhere',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'Hosted on the site?',
    description:
      'Hosted on www.ilovefreegle.org (normally yes, but some are hosted elsewhere).',
  },
  {
    id: 'onmap',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'Shown on the map?',
    description:
      "Normally you'd set this yes, except for a few hidden test groups.",
  },
  {
    id: 'ontn',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'On TrashNothing?',
    description: 'On trashnothing.com too?',
  },
  {
    id: 'onlovejunk',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'On LoveJunk?',
    description: 'On lovejunk.com too?',
  },
  {
    id: 'mentored',
    tab: 'community',
    section: 'accordion-stats',
    sectionLabel: 'Status',
    label: 'Caretakers?',
    description: 'Whether this community is being run by Caretakers.',
  },
  {
    id: 'community-actively-moderating',
    tab: 'community',
    section: 'accordion-your',
    sectionLabel: 'Your Settings',
    label: 'Are you actively moderating this community?',
    description: 'We notify you about work to do for active communities.',
  },
  {
    id: 'community-standard-messages-choice',
    tab: 'community',
    section: 'accordion-your',
    sectionLabel: 'Your Settings',
    label: 'Standard Messages to use for this community:',
    description:
      'The Standard Messages you choose controls which collection of standard message buttons you can use. You can see the settings for them on the separate tab.',
  },
  {
    id: 'community-profile-picture',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'Profile picture',
    description:
      "This is used in emails and on the site. It needs to look good small, like a Facebook profile picture. Avoid text - it's not readable. Aim for a simple image that people will recognise as relating to your location.",
  },
  {
    id: 'community-description',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'Description',
    description:
      "This is a longer description which will display on the community page and elsewhere on the site. HTML is OK in here, and if you're really geeky, Bootstrap-5 styling.",
  },
  {
    id: 'community-keywords',
    tab: 'community',
    section: 'accordion-appearance',
    sectionLabel: 'How It Looks',
    label: 'Keywords',
  },
  {
    id: 'community-region',
    tab: 'community',
    section: 'accordion-maps',
    sectionLabel: 'Mapping',
    label: 'Region',
    description:
      'Each community is an in region of the UK. You can see other communities in this region here .',
  },
  {
    id: 'community-areas',
    tab: 'community',
    section: 'accordion-maps',
    sectionLabel: 'Mapping',
    label: 'Areas',
    description:
      'Each postcode in a group lies within an area, which is something that a freegler would recognise as a description of a rough location. You can set these areas up here:',
  },
  {
    id: 'personal-your-visible-name',
    tab: 'personal',
    label: 'Your visible name',
    description:
      'This is your name as displayed publicly to other users, including in the $myname substitution string.',
  },
  {
    id: 'personal-your-email-address',
    tab: 'personal',
    label: 'Your email address',
    description: "Anything we mail to you, we'll mail to this email address.",
  },
  {
    id: 'personal-moderation-notifications-active',
    tab: 'personal',
    label: 'Moderation Notifications (Active)',
    description:
      "For groups that you're an active mod on, we will mail you when there is moderation work to do which has been outstanding for more than a certain time. You can control the frequency or disable this here. We only mail you between 8am and 10pm.",
  },
  {
    id: 'personal-moderation-notifications-backup',
    tab: 'personal',
    label: 'Moderation Notifications (Backup)',
    description:
      "This is for groups where you're a backup mod. You'd usually set this to a higher value than the previous setting so that the active mods will get notified first.",
  },
  {
    id: 'personal-play-beep',
    tab: 'personal',
    label: 'Play Beep',
    description: 'Play beep when new ModTools work arrives.',
  },
  {
    id: 'personal-show-me-as-a-volunteer',
    tab: 'personal',
    label: 'Show me as a volunteer?',
    description:
      'We can show members who the volunteers on a group are, to make it seem more friendly. You can choose whether we show you.',
  },
  {
    id: 'personal-email-me-about-chitchat',
    tab: 'personal',
    label: 'Email me about ChitChat?',
    description:
      'Your members may post in the ChitChat, perhaps to introduce themselves, or perhaps because they have problems. Replying to these posts helps them and makes Freegle friendlier. We only mail you about communities you are an active mod on.',
  },
  {
    id: 'personal-enter-send-vs-newline',
    tab: 'personal',
    label: 'Enter send vs newline',
    description:
      'On ModTools, normally enter/return adds a new line. This the opposite way round from on the Freegle site, where sending the message is the best compromise option given the limitations of what is technically possible for websites when used from mobiles. If you prefer enter to send on ModTools too, you can change the setting on this device.',
  },
  {
    id: 'name',
    tab: 'stdmsgs',
    label: 'Name',
    description:
      "This is the name of this collection of standard messages. It appears when you're choosing which collection to apply to your community.",
  },
  {
    id: 'chatread',
    tab: 'stdmsgs',
    label: 'Leave chat unread?',
    description:
      "When you send a standard message it will go in the chat between that member and the group mods. If this setting is 'Leave as unread' then it will be mailed to other mods and they will see it as unread in chat (i.e. with a red number). If this setting is 'Mark as read', then they will still be able to see it in the chat, but it won't get mailed and it won't be unread.",
  },
  {
    id: 'fromname',
    tab: 'stdmsgs',
    label: "'From:' name in messages",
    description:
      "You can choose whether the mod's own name is used in standard messages.",
  },
  {
    id: 'coloursubj',
    tab: 'stdmsgs',
    label: 'Colour-code subjects?',
    description:
      'Whether subjects are coded green/red based to indicate that they are correctly formatted.',
  },
  {
    id: 'subjlen',
    tab: 'stdmsgs',
    label: 'Subject length warning',
    description: 'Keeping subject lines short is better for small screens.',
  },
  {
    id: 'subjreg',
    tab: 'stdmsgs',
    label: 'Regular expression for colour-coding',
    description:
      'Regular expressions can be difficult; test changes at http://www.phpliveregex.com',
  },
  {
    id: 'network',
    tab: 'stdmsgs',
    label: '$network substitution string',
    description:
      "Normally you'd leave this set to Freegle or blank (same thing).",
  },
  {
    id: 'protected',
    tab: 'stdmsgs',
    label: 'Locked against changes?',
    description:
      'You can set this read-only so that only the person who created it can make changes',
  },
]

export const SETTINGS_TABS = {
  personal: { index: 0, label: 'Personal' },
  community: { index: 1, label: 'Community' },
  stdmsgs: { index: 2, label: 'Standard Messages' },
}
