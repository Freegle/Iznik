// The areas a lockdown holds, in the order they are listed and lifted, with
// exactly what is held and what still works in each
// (plans/active/2026-09-27-lockdown-switch.md section 11.11). The Press
// explanation, the confirm dialog and the area rows all read these, so
// Support sees the same words before and after pressing.
//
// `kind` is the lockdown_holds / stats count key for the area, where held
// items are counted.
export const LOCKDOWN_AREAS = [
  {
    key: 'mods',
    label: 'Moderator actions',
    kind: 'refused',
    description:
      'Moderators can only use the basic Approve button. Rejecting, editing, holding, banning, standard messages, mailing members and starting chats with members are refused. Replies to members who wrote to the volunteers still work.',
  },
  {
    key: 'chat',
    label: 'Chat between members',
    kind: 'chat',
    description:
      'Messages one member sends another wait. Chat with the volunteers keeps working both ways.',
  },
  {
    key: 'posts',
    label: 'New posts and edits',
    kind: 'post',
    description:
      'Members can still post and edit; the post waits as pending and the member sees it as live. Nobody else sees it.',
  },
  {
    key: 'chitchat',
    label: 'ChitChat',
    kind: 'chitchat',
    description:
      'New posts and replies are hidden from everyone but the author.',
  },
  {
    key: 'events',
    label: 'Events, volunteering, noticeboards and stories',
    kind: 'events',
    description: 'New ones wait for approval; edits are refused.',
  },
  {
    key: 'push',
    label: 'App notifications',
    kind: 'push',
    description: 'None are sent.',
  },
  {
    key: 'email',
    label: 'Email to members',
    kind: 'email',
    description:
      'None is generated; sign-in, password, verification, unsubscribe and account deletion emails still go. On lifting, digests and notifications carry on from where they stopped.',
  },
  {
    key: 'export',
    label: 'Downloads',
    kind: 'export',
    description:
      'Member data exports, the Support user dump, the spammer list and partnership stats files are refused, for everyone.',
  },
]

// The two earlier wordings, offered as starting text for the member notice.
export const LOCKDOWN_NOTICE_WORDINGS = [
  {
    label: 'Running slowly',
    text: 'Freegle is running slowly today. Messages and posts may take longer than usual to reach people.',
  },
  {
    label: 'Spam attack',
    text: "We're dealing with a spam attack. Messages may be delayed. If you received a message about vouchers or payments, please don't click the link.",
  },
]

export const LOCKDOWN_BACK_TO_NORMAL = 'Things are back to normal.'

export const LOCKDOWN_NOTICE_MAX = 500
