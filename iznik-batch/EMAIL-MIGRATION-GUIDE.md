# Email Migration Guide: iznik-server to iznik-batch

This guide documents lessons learned from migrating the Welcome Email and Chat Notification Email from iznik-server to iznik-batch. Follow this guide to avoid common mistakes.

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Pre-Migration Checklist](#pre-migration-checklist)
3. [File Structure Template](#file-structure-template)
4. [Common Mistakes and How to Avoid Them](#common-mistakes-and-how-to-avoid-them)
5. [Subject Line Generation](#subject-line-generation)
6. [Template Guidelines](#template-guidelines)
7. [AMP Email Implementation](#amp-email-implementation)
8. [Email Tracking](#email-tracking)
9. [Testing Checklist](#testing-checklist)
10. [Command Structure](#command-structure)
11. [Feature Flags for Email Types](#feature-flags-for-email-types)

---

## Architecture Overview

### Old Architecture (iznik-server)
- Synchronous processing within cron scripts
- Twig templating with PHP string building
- Direct SMTP sending via SwiftMailer
- Manual header construction

### New Architecture (iznik-batch)
- Asynchronous batch processing via Laravel Artisan commands
- MJML templating with Blade views for responsive emails
- Optional email spooling for resilience
- Automatic tracking headers via MjmlMailable base class
- AMP email support for interactive features

### Key Classes

| Component | Location | Purpose |
|-----------|----------|---------|
| `MjmlMailable` | `app/Mail/MjmlMailable.php` | Base class for all emails |
| `TrackableEmail` | `app/Mail/Traits/TrackableEmail.php` | URL and view tracking |
| `AmpEmail` | `app/Mail/Traits/AmpEmail.php` | AMP email support |
| `EmailSpoolerService` | `app/Services/EmailSpoolerService.php` | Resilient email sending |

---

## Pre-Migration Checklist

Before starting migration, complete these steps:

### 1. Study the Original Implementation
```bash
# Find the original code in iznik-server
grep -r "function nameOfEmail" iznik-server/include/
grep -r "email_type_name" iznik-server/scripts/cron/

# Find related tests
grep -r "testEmailFunction" iznik-server/test/ut/php/
```

### 2. Document Original Behaviour
- [ ] Subject line generation logic
- [ ] From/Reply-To address format
- [ ] Recipients and notification preferences
- [ ] Message type handling (if applicable)
- [ ] Special headers (X-Freegle-*, Return-Receipt-To, etc.)
- [ ] Outcome buttons/CTAs
- [ ] Text fallback content

### 3. Identify Database Queries
- [ ] Main data query (which users/messages to notify)
- [ ] Related data (referenced messages, images, job ads)
- [ ] Tracking/progress updates

---

## File Structure Template

For each new email type, create these files:

```
iznik-batch/
├── app/
│   └── Mail/
│       └── YourEmailType/
│           └── YourEmailMail.php        # Mailable class
├── app/
│   └── Console/
│       └── Commands/
│           └── Mail/
│               └── SendYourEmailCommand.php  # Artisan command
├── resources/
│   └── views/
│       └── emails/
│           ├── mjml/
│           │   └── your-email-type/
│           │       └── notification.blade.php  # MJML template
│           ├── text/
│           │   └── your-email-type/
│           │       └── notification.blade.php  # Plain text fallback
│           └── amp/  (if needed)
│               └── your-email-type/
│                   └── notification.blade.php  # AMP version
└── tests/
    └── Unit/
        └── Mail/
            └── YourEmailMailTest.php    # Unit tests
```

---

## Common Mistakes and How to Avoid Them

### Mistake 1: HTML Entities in Text Emails
**Commit:** `c0afbba - Fix HTML entities in text version of chat notification emails`

**Problem:** Using `{{ $variable }}` in text templates causes apostrophes to appear as `&#039;`.

**Fix:** Use raw Blade output `{!! $variable !!}` for user-generated content in text templates:
```blade
{{-- Wrong --}}
{{ $message->text }}

{{-- Correct --}}
{!! $message->text !!}
```

**Note:** Only use raw output in TEXT templates. HTML templates should still escape user content for security.

### Mistake 2: Subject Line Logic Mismatch
**Commit:** `7a82798 - Restore chat notification subject logic from iznik-server`

**Problem:** Implementing new subject line logic instead of copying the exact logic from iznik-server.

**Fix:** Always copy the EXACT subject line logic. For chat notifications:
```php
// From iznik-server ChatRoom::getChatEmailSubject()
// Query for the last TYPE_INTERESTED message in the chat
$sql = "SELECT subject, nameshort, namefull FROM messages
        INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id
        INNER JOIN messages_groups ON messages_groups.msgid = messages.id
        INNER JOIN `groups` ON groups.id = messages_groups.groupid
        WHERE chatid = ? AND chat_messages.type = ?
        ORDER BY chat_messages.id DESC LIMIT 1;";
```

### Mistake 3: Own Message Notifications Not Handled
**Commit:** `82aa132 - Fix chat notification for own message copies`

**Problem:** When users receive copies of their own messages, the email should reflect that.

**Fix:** Check if sender === recipient and adjust:
- Header text ("Copy of your message to X" not "New message from X")
- Button text ("View conversation" not "Reply to X")
- Hide "About sender" section
- Message label ("Your message" not "New message")

```php
$this->isOwnMessage = $message->userid === $recipient->id;
```

### Mistake 4: Base64 Images in Emails
**Commit:** `67eac82 - Fix welcome email to use hosted images instead of base64`

**Problem:** Gmail and most email clients strip base64 data URIs for security.

**Fix:** Always use hosted image URLs with the delivery service:
```php
// Wrong - will be stripped
$image = base64_encode(file_get_contents('image.png'));

// Correct - use delivery service
$imageUrl = $this->responsiveImage(
    config('freegle.images.welcome_hero'),
    [300, 600, 900],
    600
);
```

### Mistake 5: Ampersands Breaking MJML
**Commit:** `976b2e1 - Fix MJML parsing by escaping ampersands in image URLs`

**Problem:** URLs with `&` characters break MJML XML parsing.

**Fix:** Escape ampersands in URLs passed to MJML:
```php
$url = str_replace('&', '&amp;', $originalUrl);
```

### Mistake 6: Missing User ID in Tracking
**Commit:** `6396b2a - Fix ChatNotification user_id null in email tracking`

**Problem:** Passing null user IDs when users exist in the database.

**Fix:** Check if user exists before getting ID:
```php
$userId = $this->recipient->exists ? $this->recipient->id : null;

$this->initTracking(
    'EmailType',
    $this->recipient->email_preferred,
    $userId,  // Can be null for mock users in tests
    // ...
);
```

### Mistake 7: Emoji Display Issues
**Commit:** `d983bc6 - Add emoji decoding for chat notifications`

**Problem:** Emojis stored as escape sequences (e.g., `\u{1F600}`) display literally.

**Fix:** Decode emoji sequences before display:
```php
use App\Support\EmojiUtils;

$displayText = EmojiUtils::decode($message->message);
```

---

## Subject Line Generation

### Pattern from iznik-server

Always follow the original subject line logic exactly. Common patterns:

#### User2User Chats
```php
// Query for last TYPE_INTERESTED message to get item subject
// Format: "Regarding: [GroupName] ItemSubject"
// Fallback: "[Freegle] You have a new message"
```

#### User2Mod Chats (to member)
```php
"Your conversation with the {groupName} volunteers"
```

#### User2Mod Chats (to moderator)
```php
"Member conversation on {groupShortName} with {userName} ({email})"
```

### Stripping Existing Prefixes
Remove duplicate prefixes from subjects:
```php
$subject = str_replace('Regarding:', '', $subject);
$subject = str_replace('Re: ', '', $subject);
$subject = trim($subject);
$finalSubject = "Regarding: [{$groupName}] {$subject}";
```

---

## Template Guidelines

### MJML Template Structure (HTML)

```blade
{{-- resources/views/emails/mjml/your-email/notification.blade.php --}}
@extends('emails.mjml.layout')

@section('content')
<mj-section>
    <mj-column>
        <mj-text>Hello {{ $recipientName }}</mj-text>
    </mj-column>
</mj-section>
@endsection
```

### Text Template Structure

```blade
{{-- resources/views/emails/text/your-email/notification.blade.php --}}
Hello {!! $recipientName !!},

{!! $messageContent !!}

View on Freegle: {!! $url !!}

---
To change your notification settings: {!! $unsubscribeUrl !!}
```

**Critical:** Use `{!! !!}` for all user content in text templates.

### Image Guidelines

1. **Never use base64** - Gmail strips them
2. **Always use delivery service** for resizing:
   ```php
   $imageUrl = config('freegle.delivery.base_url')
       . '?url=' . urlencode($sourceUrl)
       . '&w=200';
   ```
3. **Use responsiveImage()** for srcset support:
   ```php
   $data = $this->responsiveImage($sourceUrl, [200, 400, 600], 400);
   // Returns: ['src' => '...', 'srcset' => '...200w, ...400w, ...600w']
   ```

### Footer Requirements

**All email templates must include a footer.** This applies to MJML, text, AND AMP templates.

The footer must contain:
1. **Recipient email address** - "This email was sent to {email}"
2. **Settings link** - Link to `/settings` for email preferences
3. **Unsubscribe link** - Link to `/unsubscribe` for account deletion
4. **Charity information** - HMRC reference and registered address

Example footer elements:
```
This email was sent to user@example.com

Change your email settings: https://www.ilovefreegle.org/settings
Unsubscribe: https://www.ilovefreegle.org/unsubscribe

Freegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers.
Registered address: 64a North Road, Ormesby, Great Yarmouth, Norfolk NR29 3LE
```

**Checklist when creating new email templates:**
- [ ] MJML template has footer section
- [ ] Text template has footer section
- [ ] AMP template has footer section (if AMP version exists)
- [ ] All three versions have consistent content

---

## AMP Email Implementation

### When to Add AMP

Add AMP support when:
- Email has interactive elements (reply, refresh)
- Target audience uses supported providers (Gmail, Yahoo)
- Security can be maintained via HMAC tokens

### Supported Providers (2025)

| Provider | Domains |
|----------|---------|
| Gmail | gmail.com, googlemail.com |
| Yahoo | yahoo.com, yahoo.co.uk, etc. (30+ TLDs) |
| AOL | aol.com, aol.co.uk |
| Mail.ru | mail.ru, inbox.ru, list.ru, bk.ru |
| Yandex | yandex.ru (limited) |

**NOT supported:** Outlook, Hotmail (Microsoft dropped support in 2023)

### AMP Token System

Use single HMAC token for both read and write:
```php
$message = 'amp' . $userId . $chatId . $expiry;
$token = hash_hmac('sha256', $message, config('freegle.amp.secret'));
```

### Forbidden CSS in AMP

These properties will fail validation:
- `pointer-events`
- `filter: url()`, `backdrop-filter`
- `clip-path`, `mask*`
- CSS variables
- `@import`
- `::before`, `::after` pseudo-elements

### Testing AMP Emails

```bash
# Generate test email with AMP
docker exec freegle-batch php artisan mail:test your-email --to=test@gmail.com --amp=on

# AMP HTML saved to /tmp/amp-email-*.html
# Validate at: https://amp.gmail.dev/playground/
```

---

## Email Tracking

### Automatic Headers (MjmlMailable)

All emails automatically get:
- `X-Freegle-Trace-Id`: Unique ID for log correlation
- `X-Freegle-Email-Type`: Class name
- `X-Freegle-Timestamp`: ISO 8601 timestamp
- `X-Freegle-User-Id`: Recipient user ID (if set)

### Tracked URLs

Use `trackedUrl()` for click analytics:
```php
$url = $this->trackedUrl(
    'https://www.ilovefreegle.org/item/123',
    'cta_view_item',      // Position identifier
    'click'               // Action type
);
```

### Tracking Pixel

Add to template for open tracking:
```blade
{!! $trackingPixelMjml !!}
```

### Initialize Tracking

In constructor:
```php
$this->initTracking(
    'EmailTypeName',           // Type for analytics
    $recipient->email,         // Recipient email
    $recipient->id,            // User ID (or null)
    $groupId,                  // Group ID (or null)
    $subject,                  // Email subject
    ['key' => 'value']         // Additional metadata
);
```

---

## Testing Checklist

### Unit Tests Required

For each email migration, test:

- [ ] Subject line generation (all variants)
- [ ] From/Reply-To addresses
- [ ] All message types handled correctly
- [ ] Own message notifications (if applicable)
- [ ] Text fallback contains no HTML entities
- [ ] Tracked URLs generated correctly
- [ ] Tracking initialized with correct parameters
- [ ] AMP validation passes (if applicable)

### Test Database Considerations

- Use `iznik_batch_test` database for tests
- Wrap tests in transactions (automatic rollback)
- Create mock users with `User::factory()`
- Don't rely on production data

### Example Test Structure

```php
public function test_subject_line_for_interested_message(): void
{
    $recipient = User::factory()->create();
    $sender = User::factory()->create();
    $chatRoom = ChatRoom::factory()->create();
    $message = ChatMessage::factory()
        ->interested()
        ->create(['chatid' => $chatRoom->id]);

    $mail = new ChatNotification($recipient, $sender, $chatRoom, $message, 'User2User');

    $this->assertStringContainsString('Regarding:', $mail->getSubject());
}
```

---

## Command Structure

### Artisan Command Template

```php
<?php

namespace App\Console\Commands\Mail;

use Illuminate\Console\Command;

class SendYourEmailCommand extends Command
{
    protected $signature = 'mail:your-email
        {--limit=100 : Maximum emails to send}
        {--dry-run : Preview without sending}
        {--spool : Use email spooler}
        {--once : Send single email then exit}';

    protected $description = 'Send your email notifications';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        // Query for recipients needing notification
        $recipients = $this->getRecipientsNeedingNotification($limit);

        foreach ($recipients as $recipient) {
            if ($dryRun) {
                $this->info("Would send to: {$recipient->email}");
                continue;
            }

            $this->sendNotification($recipient);
        }

        return Command::SUCCESS;
    }
}
```

### Naming Convention

| Email Type | Command Name |
|------------|--------------|
| Chat notifications (U2U) | `mail:chat:user2user` |
| Chat notifications (U2M) | `mail:chat:user2mod` |
| Welcome email | `mail:welcome:send` |
| Digest | `mail:digest:unified` |
| Donations | `mail:donations:ask` |

---

## Quick Reference: Copying from iznik-server

When migrating, copy these elements EXACTLY:

### 1. Subject Line Query
Find in iznik-server: `getChatEmailSubject()` or similar.

### 2. Recipient Query
Find the main SQL query that determines who to email.

### 3. Message Type Constants
```php
// Must match iznik-server ChatMessage constants
const TYPE_DEFAULT = 'Default';
const TYPE_INTERESTED = 'Interested';
const TYPE_PROMISED = 'Promised';
const TYPE_COMPLETED = 'Completed';
// etc.
```

### 4. From Address (IMPORTANT for AMP)
```php
// ALWAYS use noreply as From address - required for AMP email whitelisting
public function envelope(): Envelope
{
    return new Envelope(
        from: new Address(
            config('freegle.mail.noreply_addr'),  // noreply@ilovefreegle.org
            $this->fromDisplayName                 // Sender's display name
        ),
        replyTo: [new Address($this->replyToAddress, $this->fromDisplayName)],
        subject: $this->getSubject(),
    );
}
```

**Why noreply@ilovefreegle.org?**
- This address is whitelisted with Gmail/Yahoo for sending AMP emails
- Using any other From address (like notify-xxx@users.ilovefreegle.org) breaks AMP delivery
- The sender's name still appears to recipients (via display name)
- Replies still work correctly (via Reply-To header)

### 5. Reply-To Format
```php
// Standard format from iznik-server - used for routing replies
$replyTo = 'notify-' . $chatId . '-' . $userId . '@' . USER_DOMAIN;
```

### 6. Notification Preference Checks
```php
// Check if user wants email notifications
$emailNotifs = $user->notifsOn(User::NOTIFS_EMAIL);
$ownMessages = $user->notifsOn(User::NOTIFS_EMAIL_MINE);
```

---

## Feature Flags for Email Types

All email-sending services must respect the `FREEGLE_MAIL_ENABLED_TYPES` environment variable. This allows gradual rollout and quick disabling of specific email types.

### Implementation Pattern

1. **Add the FeatureFlags trait** to your service:
```php
use App\Mail\Traits\FeatureFlags;

class YourEmailService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'YourEmailType';
```

2. **Check at the start of your send method**:
```php
public function sendEmails(): array
{
    $stats = ['processed' => 0, 'emails_sent' => 0, 'errors' => 0];

    // Check if this email type is enabled.
    if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
        Log::info('YourEmailType emails disabled via FREEGLE_MAIL_ENABLED_TYPES');
        return $stats;
    }

    // ... rest of implementation
}
```

### Configuration

The enabled types are configured via environment variable:
```env
FREEGLE_MAIL_ENABLED_TYPES=Welcome,ChatNotification,ChatNotificationUser2Mod,ChatNotificationMod2Mod,Digest,UnifiedDigest,DonationThank,DonationAsk
```

### Adding New Email Types

When adding a new email type:

1. Add the FeatureFlags trait and check to your service
2. Add the new type to `docker-compose.yml` (for the batch container)
3. Add the new type to `phpunit.xml` (with `force="true"`)
4. Add the new type to `.env.testing`
5. Update `tests/Unit/Mail/FeatureFlagsTest.php` to test the new type

### Why This Matters

- **Gradual Rollout**: Enable new email types in staging first
- **Quick Disable**: Turn off problematic emails without code changes
- **Testing Control**: Run tests with all types enabled
- **Fail-Safe**: Unknown types are disabled by default

---

## Summary: Key Lessons

1. **Copy subject line logic EXACTLY** - don't improvise
2. **Use `{!! !!}` in text templates** - avoid HTML entities
3. **Never use base64 images** - Gmail strips them
4. **Handle own-message copies** - check if sender === recipient
5. **Escape ampersands in URLs** - MJML is XML
6. **Check user exists before getting ID** - avoid null pointer errors
7. **Decode emojis** - they may be stored as escape sequences
8. **Test text fallback** - many users don't see HTML
9. **Validate AMP** - use the playground before deploying
10. **Write tests first** - based on iznik-server behavior
11. **Add feature flag check** - respect FREEGLE_MAIL_ENABLED_TYPES setting
12. **Include footer in ALL templates** - MJML, text, AND AMP must have consistent footers
13. **Use noreply as From address** - required for AMP email whitelisting; use Reply-To for routing
