# Unsubscribe Flows Audit

This document maps out all user journeys related to leaving or reducing emails from Freegle, organised by user intent.

---

## Overview: Three User Intents

| Intent | Outcome | Account retained? | Group membership? |
|--------|---------|-------------------|-------------------|
| **Leave a single group** | Removed from one community | Yes | Other groups remain |
| **Turn off emails** | Stops some or all emails | Yes | All groups remain |
| **Leave Freegle completely** | Account deleted (14-day grace) | For 14 days | All groups removed |

---

# Intent 1: Leave a Single Group

User wants to leave one community but remain on Freegle and keep other group memberships.

## Entry Points

### 1A. Group Page Header

```
User viewing a community page (e.g. /explore/Freegle-Edinburgh)
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │         GROUP PAGE HEADER           │
    │                                     │
    │  [Leave] button (white, trash icon) │
    │  Visible if user is a Member        │
    │  (not visible to Mods/Owners)       │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  API removes user from that group   │
    │  Page refreshes                     │
    │  Button changes to [Join]           │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  FAREWELL EMAIL SENT (optional)     │
    │                                     │
    │  Subject: "Farewell from {group}"   │
    │  Body: "Parting is such sweet       │
    │         sorrow."                    │
    │                                     │
    │  Note: Only sent in certain cases   │
    │  (e.g. TN users, byemail flag)      │
    └─────────────────────────────────────┘
```

**Observation:** No confirmation is shown on the page - the action happens immediately. User can rejoin by clicking the Join button that replaces it.

### 1B. Unsubscribe Page Dropdown

```
User arrives at /unsubscribe (logged in)
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │         UNSUBSCRIBE PAGE            │
    │                                     │
    │  "Want to leave Freegle?"           │
    │                                     │
    │  ┌─────────────────────────────┐    │
    │  │ Leave a specific community: │    │
    │  │ [Community dropdown ▼]      │    │
    │  │ [Leave this community]      │    │
    │  └─────────────────────────────┘    │
    └─────────────────────────────────────┘
                    │
        User selects a community
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  [Leave this community] button      │
    │  becomes visible (green)            │
    └─────────────────────────────────────┘
                    │
        User clicks button
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  API removes user from that group   │
    │  Dropdown resets                    │
    │                                     │
    │  Success message shown on page:     │
    │  "We've removed you from {group}."  │
    │                                     │
    │  User remains on the same page      │
    │  and can leave other groups         │
    └─────────────────────────────────────┘
                    │
                    ▼
        (Farewell email sent as above)
```

### 1C. Settings Page (Advanced Mode)

```
User on /settings
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Click "Show advanced settings"     │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Per-group settings section         │
    │                                     │
    │  For each group:                    │
    │  - Email frequency dropdown         │
    │  - Community events toggle          │
    │  - Volunteer opportunities toggle   │
    │  - "Leave this community" link      │
    └─────────────────────────────────────┘
                    │
        User clicks "Leave this community"
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  API removes user from that group   │
    │  Page refreshes                     │
    │  Group no longer appears in list    │
    └─────────────────────────────────────┘
```

---

# Intent 2: Turn Off Emails (Stay a Member)

User wants to stop receiving some or all emails but remain a member of their groups.

## Entry Points

### 2A. Settings Page - Simple Mode (Default)

```
User arrives at /settings
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │         SETTINGS PAGE               │
    │                                     │
    │  Email Settings section             │
    │                                     │
    │  "Email level"                      │
    │  [Dropdown: Standard ▼]             │
    │                                     │
    │  Options:                           │
    │  - Standard (all emails)            │
    │  - Basic (daily digest + chats)     │
    │  - Off (no emails)                  │
    └─────────────────────────────────────┘
                    │
        User selects "Off"
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Warning shown:                     │
    │  "You won't get email               │
    │   notifications. Check Chats        │
    │   regularly."                       │
    │                                     │
    │  Settings saved automatically       │
    │  User remains a member of all       │
    │  groups                             │
    └─────────────────────────────────────┘
```

**What each level means:**

| Level | Digests | Chat replies | Events | Newsletters | Notifications |
|-------|---------|--------------|--------|-------------|---------------|
| Standard | Immediate | ✓ | ✓ | ✓ | ✓ |
| Basic | Daily | ✓ | ✗ | ✗ | ✗ |
| Off | None | ✗ | ✗ | ✗ | ✗ |

### 2B. Settings Page - Advanced Mode

```
User on /settings
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Click "Show advanced settings"     │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  PER-GROUP SETTINGS                 │
    │                                     │
    │  For each group:                    │
    │  Email frequency:                   │
    │  [Immediately / Daily / Never ▼]    │
    │                                     │
    │  Community events: [On/Off]         │
    │  Volunteer opportunities: [On/Off]  │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  GLOBAL EMAIL SETTINGS              │
    │                                     │
    │  Email me replies to my posts [On]  │
    │  Copy of my sent messages    [Off]  │
    │  ChitChat & notifications    [On]   │
    │  Suggested posts for you     [On]   │
    │  Newsletters & stories       [On]   │
    │  Encouragement emails        [On]   │
    │                                     │
    │  Note: "We may occasionally send    │
    │  important admin emails."           │
    └─────────────────────────────────────┘
```

### 2C. Email Footer Link

```
User receives any Freegle email
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  EMAIL FOOTER (MJML/HTML emails)    │
    │                                     │
    │  "This email was sent to {email}"   │
    │  • Change your email settings       │
    │  • Unsubscribe                      │
    └─────────────────────────────────────┘
                    │
        User clicks "Change your email settings"
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Redirects to /settings             │
    │  (Flow 2A or 2B above)              │
    └─────────────────────────────────────┘
```

**Note:** Plain text emails only have a settings link, not an unsubscribe link.

### 2D. Help Page Path

```
User on /help
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  "Emails & notifications"           │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  "Get fewer emails"                 │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Shows text:                        │
    │  "You can reduce the number and     │
    │   frequency of emails in Settings   │
    │   under 'Mail Settings'. You can    │
    │   choose to get a daily digest      │
    │   instead of individual emails."    │
    │                                     │
    │  [Go to Settings →]                 │
    └─────────────────────────────────────┘
                    │
                    ▼
        Redirects to /settings
```

### 2E. Unsubscribe Page "Get fewer emails" Button

```
User on /unsubscribe page
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  [Get fewer emails] (green button)  │
    └─────────────────────────────────────┘
                    │
                    ▼
        Redirects to /settings
```

---

# Intent 3: Leave Freegle Completely

User wants to delete their account and remove all their data.

## Entry Points

### 3A. Unsubscribe Page - Logged In

```
User arrives at /unsubscribe (logged in)
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │         UNSUBSCRIBE PAGE            │
    │                                     │
    │  "Want to leave Freegle?"           │
    │                                     │
    │  "We'd love you to stay, but        │
    │   sometimes if you love someone,    │
    │   you have to let them go."         │
    │                                     │
    │  [Get fewer emails] [Leave Freegle  │
    │       (green)        completely]    │
    │                          (red)      │
    └─────────────────────────────────────┘
                    │
        User clicks "Leave Freegle completely"
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │      CONFIRMATION MODAL             │
    │                                     │
    │  "Permanently delete your account?" │
    │                                     │
    │  "This will delete all your         │
    │   personal data, chats and          │
    │   community memberships.            │
    │                                     │
    │   It's permanent - you can't undo   │
    │   it or get your data back.         │
    │                                     │
    │   If you just want to leave one     │
    │   community, please Cancel and      │
    │   select the community from the     │
    │   drop-down list."                  │
    │                                     │
    │  [Cancel]            [Confirm]      │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Account deleted                    │
    │  User logged out                    │
    │                                     │
    │  Redirect to /unsubscribe/          │
    │  unsubscribed                       │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  SUCCESS PAGE                       │
    │                                     │
    │  "We've removed your account"       │
    │                                     │
    │  "Other freeglers will no longer    │
    │   be able to see your personal      │
    │   data, posts or chat messages.     │
    │   You should stop receiving emails  │
    │   within a few hours.               │
    │                                     │
    │   We'll keep your account data for  │
    │   14 days, in case you change your  │
    │   mind. You can recover your        │
    │   account by logging back in -      │
    │   you'll see a button you can       │
    │   click to restore it. After that,  │
    │   we'll wipe your data."            │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  FOLLOW-UP EMAIL SENT               │
    │                                     │
    │  Subject: "Your Freegle account     │
    │  has been removed as requested"     │
    │                                     │
    │  Content:                           │
    │  - Confirms account removal         │
    │  - Explains 14-day recovery window  │
    │  - "I want to keep my account"      │
    │    button to restore                │
    │  - Notes permanent deletion after   │
    │    14 days                          │
    └─────────────────────────────────────┘
```

### 3B. Unsubscribe Page - Not Logged In

```
User arrives at /unsubscribe (not logged in)
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │         UNSUBSCRIBE PAGE            │
    │                                     │
    │  "Please enter your email address"  │
    │  "We'll email you to confirm that   │
    │   you want to leave Freegle."       │
    │                                     │
    │  [Email input________________]      │
    │                                     │
    │  [Get fewer emails] [Leave Freegle  │
    │       (green)        completely]    │
    │                          (red)      │
    └─────────────────────────────────────┘
                    │
        User enters email and clicks
        "Leave Freegle completely"
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  CONFIRMATION EMAIL SENT            │
    │                                     │
    │  Subject: "Please confirm you       │
    │  want to leave Freegle"             │
    │                                     │
    │  Body:                              │
    │  "Please click here to leave        │
    │   Freegle:                          │
    │                                     │
    │   {confirmation link}               │
    │                                     │
    │   This will remove all your data    │
    │   and cannot be undone. If you      │
    │   just want to leave a Freegle or   │
    │   reduce the number of emails you   │
    │   get, please sign in and go to     │
    │   Settings.                         │
    │                                     │
    │   If you didn't try to leave,       │
    │   please ignore this mail."         │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Page shows:                        │
    │  "We've sent you an email to        │
    │   confirm. Please check your email, │
    │   including your spam folder."      │
    └─────────────────────────────────────┘
                    │
        User clicks link in email
                    │
                    ▼
        (Same flow as 3A from
         confirmation modal onwards)
```

### 3C. Help Page Path

```
User on /help
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  "Emails & notifications"           │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  "Unsubscribe completely"           │
    └─────────────────────────────────────┘
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Shows text:                        │
    │  "Sorry to see you go! You can      │
    │   unsubscribe from all Freegle      │
    │   emails. If you just want fewer    │
    │   emails, consider changing your    │
    │   mail settings instead."           │
    │                                     │
    │  [Unsubscribe →]                    │
    └─────────────────────────────────────┘
                    │
                    ▼
        Redirects to /unsubscribe
        (Flow 3A or 3B)
```

### 3D. One-Click Unsubscribe (Email Header)

This is the RFC 8058 `List-Unsubscribe` header. When users click "Unsubscribe" in their email client (Gmail, Outlook, etc.), this flow executes.

```
User clicks "Unsubscribe" button in email client
                    │
                    ▼
    ┌───────────────────────────────┐
    │  /one-click-unsubscribe/{id}  │
    │                               │
    │  1. Auto-login with secure key│
    │  2. Calls forget() - deletes  │
    │     account                   │
    │  3. Logs user out             │
    └───────────────────────────────┘
                    │
        ┌───────────┴───────────┐
        │                       │
        ▼                       ▼
    SUCCESS                  FAILURE
        │                       │
        ▼                       ▼
┌─────────────────┐    ┌─────────────────┐
│ /unsubscribe/   │    │  /unsubscribe   │
│   unsubscribed  │    │                 │
│                 │    │ Manual options  │
│ Success page    │    │ shown to user   │
│ (User logged    │    │                 │
│  out)           │    │ (Flow 3A or 3B) │
└─────────────────┘    └─────────────────┘
        │
        ▼
    Follow-up email sent
    (same as Flow 3A)
```

**Observation:** Users clicking "Unsubscribe" in their email client may expect to stop receiving that type of email. The actual behaviour is complete account deletion. This may or may not align with user expectations depending on context.

### 3E. Settings Page Link

```
User on /settings
                    │
                    ▼
    ┌─────────────────────────────────────┐
    │  Account section (bottom of page)   │
    │                                     │
    │  "Unsubscribe or leave communities" │
    │  (trash icon)                       │
    └─────────────────────────────────────┘
                    │
                    ▼
        Redirects to /unsubscribe
        (Flow 3A)
```

---

# Account Restoration (After Deletion)

If a user logs in after deleting their account:

```
User logs in after having deleted account
                    │
                    ▼
    ┌───────────────────────────────────────┐
    │  Any page (account has deleted flag)  │
    └───────────────────────────────────────┘
                    │
    ┌───────────────┴───────────────┐
    │                               │
    ▼                               ▼
WITHIN 14 DAYS              AFTER 14 DAYS
    │                               │
    ▼                               ▼
┌─────────────────────┐  ┌─────────────────────┐
│ RED BANNER SHOWN:   │  │ RED BANNER SHOWN:   │
│                     │  │                     │
│ "You deleted your   │  │ "You deleted your   │
│  account {time ago}.│  │  account {time ago}.│
│  It will be         │  │  Your data has now  │
│  completely removed │  │  been removed.      │
│  soon.              │  │                     │
│                     │  │  If you'd like to   │
│  Meanwhile, other   │  │  rejoin, we'd love  │
│  freeglers can't    │  │  to have you."      │
│  see your details,  │  │                     │
│  posts or chats.    │  │  [Rejoin Freegle]   │
│                     │  └─────────────────────┘
│  [Restore your      │              │
│   account]          │              ▼
└─────────────────────┘      Logs out, redirects
         │                   to homepage for
         ▼                   fresh signup
┌─────────────────────┐
│ Account restored    │
│ User continues      │
│ normally            │
└─────────────────────┘
```

---

# Email Footer Variations

Different email types show different footer content:

| Email Type | Settings Link | Unsubscribe Link | Notes |
|------------|--------------|------------------|-------|
| MJML/HTML (chat, digest, etc.) | ✓ | ✓ | Full footer |
| Plain text (all types) | ✓ | ✗ | Settings only |
| AMP (chat notifications) | ✗ | ✗ | No footer (bug - PR #10 fixes this) |
| PHP newsletters | ✓ | Via mailto: | Different approach |

---

# One-Click Unsubscribe: Requirements and Implications

## Provider Requirements

Since February 2024, major email providers require RFC 8058 one-click unsubscribe for bulk senders:

| Provider | Requirement | Threshold | Enforcement |
|----------|-------------|-----------|-------------|
| Gmail | One-click unsubscribe header | 5,000+ emails/day to Gmail addresses | Nov 2025: permanent rejections |
| Yahoo | One-click unsubscribe header | 5,000+ emails/day | June 2024 onwards |
| Microsoft | One-click unsubscribe header | Bulk senders | May 2025: junk folder or rejection |

**Processing requirement:** Unsubscribe requests must be honoured within 48 hours.

Sources: [Google Email Sender Guidelines](https://support.google.com/a/answer/81126), [Mailgun RFC 8058 Guide](https://www.mailgun.com/blog/deliverability/what-is-rfc-8058/)

## Marketing vs Transactional Emails

The one-click unsubscribe requirement applies **only to marketing/promotional emails**, not transactional emails.

### What counts as transactional (exempt):
- Password resets
- Purchase receipts
- Shipping confirmations
- One-time passwords (OTPs)
- Account security alerts

### What counts as marketing (requires one-click unsubscribe):
- Newsletters
- Promotional offers
- Marketing campaigns
- **Subscription messages** - any email users can unsubscribe from

### The grey area - notification emails:

> "The distinction between promotional and transactional messages can vary depending on industry and applicable regulations. **Message recipients, not Google, determine the nature of the messages they receive.**"
> — [Google Email Sender Guidelines FAQ](https://support.google.com/a/answer/14229414)

This is the critical point: **Gmail and Yahoo classify emails based on recipient behaviour and content analysis, not sender intent.** An email that a sender considers "transactional" may still be classified as promotional if:
- It's sent to many people at once (bulk distribution)
- Recipients treat it as promotional (low engagement, frequent unsubscribes)
- It contains any promotional content

### Where do Freegle emails fall?

| Email Type | Sender Intent | Possible Classification | Notes |
|------------|---------------|------------------------|-------|
| Chat notifications | Transactional (reply to user action) | Ambiguous | Triggered by another user's message |
| Digests | Marketing (summary of activity) | Marketing | Regular bulk distribution |
| Newsletters | Marketing | Marketing | Clearly promotional |
| Welcome emails | Transactional | Transactional | One-time, triggered by signup |
| Password resets | Transactional | Transactional | Clearly transactional |

Chat notifications are in a grey area: they're triggered by user action (someone messaged you), but they're also encouraging re-engagement with the platform, which is a promotional purpose.

## Freegle's Approach

Freegle **conservatively adds one-click unsubscribe headers to all emails**, including chat notifications that could be classified as transactional. This is implemented in `iznik-server/include/misc/Mail.php` and `iznik-batch/app/Mail/Chat/ChatNotification.php`.

This conservative approach assumes the worst case: that Gmail/Yahoo may classify any of our emails as marketing based on recipient behaviour. By including one-click unsubscribe on everything, Freegle ensures compliance regardless of how providers classify the emails.

## Impact on Email Deliverability

### Unsubscribes vs Spam Reports

| Action | Impact on Sender Reputation |
|--------|----------------------------|
| User clicks unsubscribe | **Neutral** - no negative impact |
| User marks as spam | **Very damaging** - harms domain/IP reputation |

This is counterintuitive but important: **making unsubscribe easy actually protects sender reputation.** When users can't easily unsubscribe, they're more likely to mark emails as spam instead, which is far more damaging.

> "By offering a straightforward unsubscribe path, you significantly reduce the likelihood of recipients marking your emails as spam."
> — [Suped Email Deliverability Guide](https://www.suped.com/knowledge/email-deliverability/sender-reputation/do-unsubscribe-links-and-rates-affect-email-deliverability-and-spam-filtering)

### Difficult Unsubscribe → Spam Reports

Research shows a direct link between difficult unsubscribe processes and spam complaints:

| Finding | Source |
|---------|--------|
| **47%** of users will mark as spam if they can't find an unsubscribe link | [Mailmend](https://mailmend.io/blogs/spam-complaint-statistics) |
| **~20%** of consumers mark emails as spam rather than unsubscribing | [Mailmend](https://mailmend.io/blogs/spam-complaint-statistics) |
| **20%** of deliverability issues are caused by high spam complaints | [ZeroBounce](https://www.zerobounce.net/email-statistics-report/) |

> "People will normally take the route of least resistance – if it's easier to complain than to unsubscribe, then that's what will happen."
> — [GlockApps](https://glockapps.com/blog/definitive-guide-about-spam-complaints/)

Users often resort to the spam button out of frustration when faced with:
- Hidden unsubscribe links at the bottom of long emails
- Multi-step unsubscribe processes requiring form submissions
- Feeling that senders are "being sneaky" about opt-outs

> "Every click beyond two to unsubscribe makes the ruthless efficiency of the one-click report spam button look more appealing."
> — [Campaign Cleaner](https://campaigncleaner.com/blog/when-to-mark-email-spam-vs-delete-unsubscribe.html)

### Spam Complaint Thresholds

Gmail requires spam complaint rates below:
- **0.3%** - maximum acceptable (enforcement starts)
- **0.1%** - recommended target

Exceeding these thresholds leads to:
- Emails going to spam/junk folder
- Temporary delivery delays
- Permanent rejections (as of Nov 2025)
- Long-term reputation damage that can take months to recover

> "A high spam complaint rate results in long-term deliverability issues that can take months to correct."
> — [Mailgun](https://www.mailgun.com/blog/deliverability/spam-complaint-rate/)

### The Deliverability Paradox

Senders who implement proper one-click unsubscribe see **lower** spam complaint rates:

> "Data shows that senders including [one-click unsubscribe] header recorded complaint rates below 0.1%, while those without it faced higher spam feedback loops."
> — [MailReach](https://www.mailreach.co/blog/email-deliverability-statistics)

This creates a paradox for Freegle: the one-click unsubscribe header is good for deliverability, but the current implementation (account deletion) may not match user expectations, potentially leading to support issues or user frustration rather than spam complaints.

## Rationale for Account Deletion

The claimed rationale for using complete account deletion as the one-click unsubscribe action is:

**Emails going to spam is a bigger problem than user complaints.**

Specifically:
- If Freegle's spam reputation is damaged, emails stop reaching users entirely - this affects everyone
- User complaints about accidental unsubscription or inability to unsubscribe are individual issues
- The 14-day recovery window mitigates accidental deletion
- A user who clicks "Unsubscribe" has expressed a clear intent to stop receiving emails

Therefore, the approach prioritises protecting spam reputation over minimising user confusion. The risk calculus is:

| Risk | Impact | Mitigation |
|------|--------|------------|
| Spam reputation damage | All users stop receiving emails | One-click unsubscribe compliance |
| User accidentally deletes account | Individual user loses access | 14-day recovery window |
| User confused by deletion | Support request / frustration | Clear messaging on success page |

This approach accepts that some users may be surprised by account deletion in exchange for ensuring Freegle maintains good email deliverability for all users.

## The Expectation Mismatch Problem

When a user clicks "Unsubscribe" in their email client, their mental model may vary:

| User's Mental Model | What They Expect |
|--------------------|------------------|
| "This is marketing email" | Stop receiving marketing, keep account |
| "This is from a mailing list" | Leave this particular list |
| "I don't want these notifications" | Stop this type of notification |
| "Stop all email from this sender" | Stop all email, but keep account |
| "I'm done with this service" | Delete account entirely |

Freegle's one-click unsubscribe performs **complete account deletion**, which matches the last mental model but not the others.

### Industry Context

Most email marketing platforms implement one-click unsubscribe to:
- Remove the user from that specific mailing list, OR
- Stop that type of email (e.g., promotional but not transactional)

Very few use one-click unsubscribe to delete the user's entire account.

### Potential Issue

A user who receives a Freegle chat notification and clicks "Unsubscribe" in Gmail may expect to stop receiving chat notifications (or at worst, stop all Freegle emails). Instead, their entire account is deleted.

This could be particularly surprising because:
1. Chat notifications feel transactional (someone messaged me)
2. The user may have active posts or ongoing conversations
3. Other platforms don't typically delete accounts via one-click unsubscribe

---

# Observations

### One-Click Unsubscribe Behaviour

When users click "Unsubscribe" in their email client (Gmail, Outlook, Apple Mail), the one-click unsubscribe mechanism performs complete account deletion. Users familiar with email marketing may expect this to simply stop that type of email while keeping their account active. Whether this meets user expectations depends on how users interpret "unsubscribe" in the context of a community platform versus a marketing email.

### No Confirmation for Leaving a Group

When leaving a single group via the Group Header or Settings page, there is no confirmation dialog. The action happens immediately. On the unsubscribe page, there is a success message, but on other pages the only indication is that the Leave button changes to Join.

### Terminology

The unsubscribe page uses the term "Leave Freegle completely" for account deletion, and the confirmation modal says "Permanently delete your account?" This language is explicit about the permanence of the action.

### 14-Day Retention Window

The 14-day retention period is communicated:
- On the success page after deletion
- In the follow-up email
- On the restoration banner when logging back in

It is not mentioned in the confirmation modal before deletion.

