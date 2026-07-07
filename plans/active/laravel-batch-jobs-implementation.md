# Laravel Batch Jobs Implementation Plan

## Overview

This document outlines the plan to migrate the batch/cron jobs from iznik-server (PHP) to a new Laravel-based implementation in `iznik-server-laravel`. The goal is to create idiomatic Laravel code while maintaining complete compatibility with the existing database schema.

## Related Documentation

| Document | Location | Purpose |
|----------|----------|---------|
| **Email Migration Guide** | `iznik-batch/EMAIL-MIGRATION-GUIDE.md` | Lessons learned from Welcome and Chat Notification email migrations - **READ BEFORE migrating any email** |
| **Migration Status** | `iznik-batch/MIGRATION-STATUS.md` | Tracks progress on all cron script migrations |
| **AMP Email Plan** | `plans/active/amp-email-implementation.md` | AMP email feature implementation details |
| **iznik-batch CLAUDE.md** | `iznik-batch/CLAUDE.md` | Development guidelines for the Laravel batch processor |

## Current State Analysis

### Existing Cron Jobs (127 scripts)

The current system has approximately 127 cron scripts in `iznik-server/scripts/cron/` organized into these categories:

1. **Real-time Queue Processing** (Every 1 minute)
   - `background.php` - Process Beanstalk job queue (webpush, SQL, etc.)
   - `spool.php` - Flush email spool to SMTP
   - `exports.php` - User data exports
   - `message_spatial.php` - Geographic spatial index updates

2. **Chat Processing** (Every 1 minute)
   - `chat_process.php` - Core chat message processing
   - `chat_notifyemail_user2user.php` - User-to-user chat email notifications
   - `chat_notifyemail_user2mod.php` - User-to-mod chat email notifications
   - `chat_expected.php` - Expected reply tracking
   - `chat_latestmessage.php` - Latest message timestamp updates
   - `chat_spam.php` - Chat spam detection and warnings

3. **Email/Notification Delivery**
   - `digest.php` - Email digests (multiple frequencies: -1, 1, 2, 4, 8, 24 hours)
   - `post_notifications.php` - Push notifications for new posts
   - `newsfeed_digest.php` - Newsfeed/community digest emails
   - `notification_chaseup.php` - Missed notification follow-ups

4. **Message Lifecycle**
   - `messages_expired.php` - Handle message expiration
   - `messages_popular.php` - Identify popular messages
   - `message_fromuser.php` - Reconcile message sender information
   - `message_unindexed.php` - Index missing messages
   - `message_deindex.php` - Remove messages from indices
   - `autorepost.php` - Auto-repost expired messages

5. **Purge/Maintenance**
   - `purge_messages.php` - Multi-stage message purge (90+ days history, drafts, etc.)
   - `purge_chats.php` - Remove old/orphaned chat data
   - `purge_logs.php` - Clean up logging tables
   - `purge_events.php` - Remove expired events
   - `purge_sessions.php` - Delete old sessions

6. **User/Membership Management**
   - `memberships_processing.php` - Process membership requests
   - `users_checkemails.php` - Validate/fix email addresses
   - `bounce_users.php` / `bounce.php` - Handle email bounces
   - `users_added.php` - Track new users
   - `users_ratings.php` - Calculate user ratings
   - `users_retention.php` - Re-engagement emails

7. **Moderation/Spam**
   - `check_spammers.php` - Check spam user list
   - `spam_toddlers.php` - Infant product spam detection
   - `searchdups.php` - Find duplicate messages

8. **Payment/Donations**
   - `donations_giftaid.php` - Chase gift aid consent
   - `donations_email.php` - Donation thank you emails
   - `user_askdonation.php` - Ask item recipients to donate

9. **External Integrations**
   - `discourse_checkusers.php` - Discourse forum sync
   - `lovejunk.php` - LoveJunk partner integration
   - `tn_sync.php` - TimelineApp synchronization

10. **Analytics/Reporting**
    - `spiralling_curve_analysis.php` - Geographic distribution analysis
    - `group_stats.php` - Group statistics generation
    - `mod_active.php` - Moderator activity tracking
    - `mod_notifs.php` - Moderator work notifications

### Common Patterns in Existing Code

1. **Lock Pattern**: `Utils::lockScript()` / `Utils::unlockScript()` for mutual exclusion
2. **Chunked Processing**: LIMIT 1000 batches to avoid table locks
3. **Modulo-Based Sharding**: `-m mod -v val` for parallel execution
4. **Swift Mailer with Twig**: HTML emails with Base64 encoding
5. **Continuous Loop**: For daemon-style scripts with periodic restart
6. **Abort File Checking**: `/tmp/iznik.*.abort` for graceful shutdown

## Laravel Implementation Architecture

### Directory Structure

```
iznik-server-laravel/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── Digest/
│   │   │   │   ├── SendDigestCommand.php
│   │   │   │   └── SendNewsfeedDigestCommand.php
│   │   │   ├── Chat/
│   │   │   │   ├── ProcessChatCommand.php
│   │   │   │   ├── NotifyUser2UserCommand.php
│   │   │   │   ├── NotifyUser2ModCommand.php
│   │   │   │   ├── CheckExpectedRepliesCommand.php
│   │   │   │   └── ProcessChatSpamCommand.php
│   │   │   ├── Message/
│   │   │   │   ├── ProcessExpiredMessagesCommand.php
│   │   │   │   ├── UpdateSpatialIndexCommand.php
│   │   │   │   ├── IndexUnindexedMessagesCommand.php
│   │   │   │   └── AutoRepostCommand.php
│   │   │   ├── Purge/
│   │   │   │   ├── PurgeMessagesCommand.php
│   │   │   │   ├── PurgeChatsCommand.php
│   │   │   │   ├── PurgeLogsCommand.php
│   │   │   │   └── PurgeEventsCommand.php
│   │   │   ├── User/
│   │   │   │   ├── ProcessMembershipsCommand.php
│   │   │   │   ├── CheckEmailsCommand.php
│   │   │   │   ├── ProcessBouncesCommand.php
│   │   │   │   └── SendRetentionEmailsCommand.php
│   │   │   ├── Donation/
│   │   │   │   ├── ChaseGiftAidCommand.php
│   │   │   │   ├── SendDonationThankYouCommand.php
│   │   │   │   └── AskForDonationCommand.php
│   │   │   ├── Notification/
│   │   │   │   ├── SendPostNotificationsCommand.php
│   │   │   │   └── ChaseUpNotificationsCommand.php
│   │   │   ├── Moderation/
│   │   │   │   ├── CheckSpammersCommand.php
│   │   │   │   ├── SendModNotificationsCommand.php
│   │   │   │   └── TrackModActivityCommand.php
│   │   │   ├── Integration/
│   │   │   │   ├── SyncDiscourseCommand.php
│   │   │   │   ├── SyncLoveJunkCommand.php
│   │   │   │   └── SyncTimelineAppCommand.php
│   │   │   ├── Analytics/
│   │   │   │   ├── GenerateGroupStatsCommand.php
│   │   │   │   └── AnalyzeSpirallingCurveCommand.php
│   │   │   └── Queue/
│   │   │       ├── ProcessBackgroundJobsCommand.php
│   │   │       └── ProcessExportsCommand.php
│   │   └── Kernel.php
│   ├── Jobs/
│   │   ├── SendDigestJob.php
│   │   ├── SendChatNotificationJob.php
│   │   ├── ProcessMessageExpiryJob.php
│   │   └── ... (queueable jobs)
│   ├── Mail/
│   │   ├── Digest/
│   │   │   ├── SingleDigest.php
│   │   │   ├── MultipleDigest.php
│   │   │   ├── EventsDigest.php
│   │   │   ├── VolunteeringDigest.php
│   │   │   └── NearbyDigest.php
│   │   ├── Chat/
│   │   │   ├── ChatNotification.php
│   │   │   └── ChatSpamWarning.php
│   │   ├── Donation/
│   │   │   ├── DonationThankYou.php
│   │   │   ├── GiftAidChaseUp.php
│   │   │   └── DonationRequest.php
│   │   ├── User/
│   │   │   ├── Welcome.php
│   │   │   ├── GroupWelcome.php
│   │   │   └── ForgotPassword.php
│   │   ├── Moderation/
│   │   │   └── ModNotification.php
│   │   └── ... (other mails)
│   ├── Models/
│   │   ├── User.php
│   │   ├── Group.php
│   │   ├── Message.php
│   │   ├── MessageGroup.php
│   │   ├── MessageOutcome.php
│   │   ├── ChatRoom.php
│   │   ├── ChatMessage.php
│   │   ├── ChatRoster.php
│   │   ├── Membership.php
│   │   ├── Admin.php
│   │   ├── Notification.php
│   │   ├── UserEmail.php
│   │   ├── UserDonation.php
│   │   ├── GiftAid.php
│   │   ├── CommunityEvent.php
│   │   ├── Volunteering.php
│   │   ├── ... (all tables as models)
│   │   └── Scopes/
│   │       ├── FreegleGroupScope.php
│   │       └── ActiveMessageScope.php
│   ├── Services/
│   │   ├── DigestService.php
│   │   ├── ChatService.php
│   │   ├── MessageService.php
│   │   ├── NotificationService.php
│   │   ├── DonationService.php
│   │   ├── ModerationService.php
│   │   └── PurgeService.php
│   └── Traits/
│       ├── ChunkedProcessing.php
│       ├── ShardedExecution.php
│       └── GracefulShutdown.php
├── config/
│   ├── freegle.php
│   └── mjml.php
├── database/
│   └── migrations/ (empty - using existing schema)
├── resources/
│   └── views/
│       └── emails/
│           └── mjml/
│               ├── components/
│               │   ├── header.blade.php
│               │   ├── footer.blade.php
│               │   └── button.blade.php
│               ├── digest/
│               │   ├── single.blade.php
│               │   ├── multiple.blade.php
│               │   ├── events.blade.php
│               │   ├── volunteering.blade.php
│               │   └── nearby.blade.php
│               ├── chat/
│               │   ├── notification.blade.php
│               │   └── spam-warning.blade.php
│               ├── donation/
│               │   ├── thank-you.blade.php
│               │   ├── giftaid-chaseup.blade.php
│               │   └── request.blade.php
│               └── ... (other templates)
├── tests/
│   ├── Feature/
│   │   ├── Commands/
│   │   │   ├── DigestCommandTest.php
│   │   │   ├── ChatCommandTest.php
│   │   │   ├── MessageCommandTest.php
│   │   │   └── ... (per command tests)
│   │   └── Mail/
│   │       ├── DigestMailTest.php
│   │       └── ... (per mail tests)
│   └── Unit/
│       ├── Services/
│       │   ├── DigestServiceTest.php
│       │   └── ... (per service tests)
│       └── Models/
│           ├── UserTest.php
│           └── ... (per model tests)
├── docker/
│   ├── Dockerfile
│   ├── entrypoint.sh
│   └── supervisor.conf
├── composer.json
├── phpunit.xml
└── .env.example
```

### Key Design Decisions

#### 1. Database Access - Eloquent with Preserved Indexing

All database access will use Eloquent ORM, but queries will be carefully designed to ensure the same index usage as the original PHP code. Raw queries will only be used when Eloquent cannot preserve the exact index behavior.

```php
// Example: Preserving index usage
// Original SQL: SELECT * FROM messages WHERE deleted IS NULL AND lat IS NOT NULL ORDER BY arrival DESC LIMIT 1000
// Eloquent equivalent (uses same index):
Message::whereNull('deleted')
    ->whereNotNull('lat')
    ->orderByDesc('arrival')
    ->limit(1000)
    ->get();
```

#### 2. MJML Email Templates

All email templates will be converted to MJML for responsive, modern email design. The existing Twig templates will be converted to Blade templates that compile MJML.

```php
// Using spatie/laravel-mjml
class SingleDigest extends Mailable
{
    use Queueable, SerializesModels;

    public function build()
    {
        return $this->mjml('emails.mjml.digest.single')
            ->subject($this->subject)
            ->with([
                'messages' => $this->messages,
                'group' => $this->group,
                'user' => $this->user,
            ]);
    }
}
```

#### 3. Laravel Console Commands

Each cron job will be implemented as a Laravel Artisan command with proper scheduling:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Real-time processing (every minute)
    $schedule->command('freegle:chat:process')->everyMinute()->withoutOverlapping();
    $schedule->command('freegle:chat:notify-user2user')->everyMinute()->withoutOverlapping();

    // Digest emails (various frequencies)
    $schedule->command('freegle:digest:send', ['--frequency' => -1])->everyMinute();
    $schedule->command('freegle:digest:send', ['--frequency' => 1])->hourly();
    $schedule->command('freegle:digest:send', ['--frequency' => 24])->dailyAt('06:00');

    // Maintenance
    $schedule->command('freegle:purge:messages')->daily();
    $schedule->command('freegle:purge:chats')->daily();

    // Analytics
    $schedule->command('freegle:analytics:group-stats')->weekly();
}
```

#### 4. Chunked Processing Trait

```php
trait ChunkedProcessing
{
    protected int $chunkSize = 1000;
    protected int $processedCount = 0;

    protected function processInChunks(Builder $query, callable $processor): void
    {
        $query->chunkById($this->chunkSize, function ($items) use ($processor) {
            foreach ($items as $item) {
                $processor($item);
                $this->processedCount++;

                if ($this->processedCount % 1000 === 0) {
                    $this->info("Processed {$this->processedCount} items...");
                }
            }
        });
    }
}
```

#### 5. Sharded Execution Trait

```php
trait ShardedExecution
{
    protected int $mod = 1;
    protected int $val = 0;

    protected function applySharding(Builder $query, string $column = 'id'): Builder
    {
        if ($this->mod > 1) {
            return $query->whereRaw("MOD({$column}, ?) = ?", [$this->mod, $this->val]);
        }
        return $query;
    }

    protected function parseShardingOptions(): void
    {
        $this->mod = (int) $this->option('mod') ?: 1;
        $this->val = (int) $this->option('val') ?: 0;
    }
}
```

#### 6. Graceful Shutdown

```php
trait GracefulShutdown
{
    protected bool $shouldStop = false;

    protected function setupSignalHandlers(): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
        pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
    }

    protected function checkAbortFile(string $scriptName): bool
    {
        return file_exists("/tmp/iznik.{$scriptName}.abort");
    }
}
```

### Model Definitions

All models will be generated to match the existing schema exactly. Here are the key models:

#### User Model
```php
class User extends Model
{
    protected $table = 'users';
    protected $guarded = ['id'];

    public function emails(): HasMany
    {
        return $this->hasMany(UserEmail::class, 'userid');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'userid');
    }

    public function chatRoomsAsUser1(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user1');
    }

    public function chatRoomsAsUser2(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user2');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(UserDonation::class, 'userid');
    }

    public function getEmailPreferredAttribute(): ?string
    {
        return $this->emails()
            ->orderByRaw('preferred DESC, validated DESC')
            ->value('email');
    }
}
```

#### Message Model
```php
class Message extends Model
{
    protected $table = 'messages';
    protected $guarded = ['id'];

    protected $casts = [
        'arrival' => 'datetime',
        'deadline' => 'date',
        'deleted' => 'datetime',
    ];

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'messages_groups', 'msgid', 'groupid')
            ->withPivot(['collection', 'arrival', 'approved_by', 'deleted']);
    }

    public function outcomes(): HasMany
    {
        return $this->hasMany(MessageOutcome::class, 'msgid');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fromuser');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereHas('groups', function ($q) {
            $q->where('collection', 'Approved');
        });
    }

    public function scopeNotDeleted(Builder $query): Builder
    {
        return $query->whereNull('deleted');
    }
}
```

#### ChatRoom Model
```php
class ChatRoom extends Model
{
    protected $table = 'chat_rooms';
    protected $guarded = ['id'];

    protected $casts = [
        'created' => 'datetime',
        'latestmessage' => 'datetime',
        'flaggedspam' => 'boolean',
    ];

    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user1');
    }

    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user2');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chatid');
    }

    public function roster(): HasMany
    {
        return $this->hasMany(ChatRoster::class, 'chatid');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }
}
```

### Implementation Phases

#### Phase 1: Foundation & Core Jobs (Week 1-2)
- [ ] Set up Laravel project structure
- [ ] Configure Docker container
- [ ] Generate all Eloquent models from schema
- [ ] Implement Mail infrastructure with MJML
- [ ] Implement `SendDigestCommand` (all frequencies)
- [ ] Implement `SendNewsfeedDigestCommand`
- [ ] Implement chat notification commands
- [ ] Write tests for Phase 1

#### Phase 2: Message Management (Week 2-3)
- [ ] Implement `ProcessExpiredMessagesCommand`
- [ ] Implement `UpdateSpatialIndexCommand`
- [ ] Implement `AutoRepostCommand`
- [ ] Implement all purge commands
- [ ] Write tests for Phase 2

#### Phase 3: User & Donation Management (Week 3-4)
- [ ] Implement `ProcessMembershipsCommand`
- [ ] Implement `CheckEmailsCommand`
- [ ] Implement `ProcessBouncesCommand`
- [ ] Implement donation commands (gift aid, thank you, request)
- [ ] Write tests for Phase 3

#### Phase 4: Moderation & Spam (Week 4-5)
- [ ] Implement `CheckSpammersCommand`
- [ ] Implement `ProcessChatSpamCommand`
- [ ] Implement moderator notification commands
- [ ] Write tests for Phase 4

#### Phase 5: External Integrations (Week 5-6)
- [ ] Implement Discourse sync
- [ ] Implement LoveJunk sync
- [ ] Implement TimelineApp sync
- [ ] Write tests for Phase 5

#### Phase 6: Analytics & Queue Processing (Week 6-7)
- [ ] Implement analytics commands
- [ ] Implement background job processing
- [ ] Implement export processing
- [ ] Write tests for Phase 6

#### Phase 7: Review & Optimization (Week 7-8)
- [ ] Code review for Laravel best practices
- [ ] Performance optimization
- [ ] Documentation
- [ ] Final testing and code coverage verification

### Testing Strategy

#### Unit Tests
- Test each service method in isolation
- Test model relationships and scopes
- Test traits functionality
- Mock database interactions where appropriate

#### Feature Tests
- Test each Artisan command end-to-end
- Test email generation and content
- Use MailHog for email verification
- Test with realistic data scenarios

#### Test Coverage Target: 90%

```php
// Example test
class SendDigestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_digest_to_eligible_members(): void
    {
        // Arrange
        $group = Group::factory()->freegle()->create();
        $user = User::factory()->create();
        Membership::factory()->create([
            'userid' => $user->id,
            'groupid' => $group->id,
            'emailfrequency' => 24,
        ]);
        Message::factory()
            ->approved()
            ->for($group)
            ->count(5)
            ->create(['arrival' => now()->subHour()]);

        Mail::fake();

        // Act
        $this->artisan('freegle:digest:send', ['--frequency' => 24])
            ->assertExitCode(0);

        // Assert
        Mail::assertSent(MultipleDigest::class, function ($mail) use ($user) {
            return $mail->hasTo($user->getEmailPreferredAttribute());
        });
    }
}
```

### Configuration

```php
// config/freegle.php
return [
    'mail' => [
        'support_addr' => env('FREEGLE_SUPPORT_ADDR', 'support@ilovefreegle.org'),
        'noreply_addr' => env('FREEGLE_NOREPLY_ADDR', 'noreply@ilovefreegle.org'),
        'geeks_addr' => env('FREEGLE_GEEKS_ADDR', 'geeks@ilovefreegle.org'),
    ],

    'sites' => [
        'user' => env('FREEGLE_USER_SITE', 'https://www.ilovefreegle.org'),
        'mod' => env('FREEGLE_MOD_SITE', 'https://modtools.org'),
    ],

    'donations' => [
        'target' => env('FREEGLE_DONATION_TARGET', 2),
        'paypal_from' => env('FREEGLE_PAYPAL_THANKS_FROM', 'volunteers@ilovefreegle.org'),
    ],

    'message' => [
        'expire_days' => env('FREEGLE_MESSAGE_EXPIRE_DAYS', 31),
        'recent_posts_days' => env('FREEGLE_RECENT_POSTS_DAYS', 31),
    ],

    'purge' => [
        'message_history_days' => 90,
        'draft_days' => 31,
        'html_body_days' => 2,
        'full_message_days' => 30,
        'spam_chat_days' => 7,
    ],
];
```

### Docker Configuration

```dockerfile
# docker/Dockerfile
FROM php:8.3-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libgmp-dev \
    libicu-dev \
    zip \
    unzip \
    supervisor \
    default-mysql-client

# Install PHP extensions
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gmp \
    zip \
    intl

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install Node.js for MJML
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# Install MJML globally
RUN npm install -g mjml

WORKDIR /var/www/laravel

COPY . .

RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# Create supervisor config
COPY docker/supervisor.conf /etc/supervisor/conf.d/laravel-worker.conf

CMD ["/usr/bin/supervisord", "-n"]
```

```yaml
# Addition to docker-compose.yml
services:
  laravel-batch:
    build:
      context: ./iznik-server-laravel
      dockerfile: docker/Dockerfile
    container_name: laravel-batch
    restart: unless-stopped
    depends_on:
      - percona
      - mailhog
    environment:
      - DB_CONNECTION=mysql
      - DB_HOST=percona
      - DB_PORT=3306
      - DB_DATABASE=iznik
      - DB_USERNAME=root
      - DB_PASSWORD=iznik
      - MAIL_MAILER=smtp
      - MAIL_HOST=mailhog
      - MAIL_PORT=1025
    volumes:
      - ./iznik-server-laravel:/var/www/laravel
    networks:
      - default
```

### MJML Template Example

```blade
{{-- resources/views/emails/mjml/digest/multiple.blade.php --}}
<mjml>
  <mj-head>
    <mj-attributes>
      <mj-all font-family="Arial, sans-serif" />
      <mj-text font-size="14px" color="#333333" line-height="20px" />
      <mj-button background-color="#5cb85c" color="white" font-size="14px" />
    </mj-attributes>
    <mj-style inline="inline">
      .message-card { margin-bottom: 15px; }
      .message-title { font-weight: bold; }
    </mj-style>
  </mj-head>
  <mj-body>
    <mj-section background-color="#f4f4f4">
      <mj-column>
        <mj-image width="150px" src="{{ $logoUrl }}" alt="Freegle" />
      </mj-column>
    </mj-section>

    <mj-section>
      <mj-column>
        <mj-text>
          Hi {{ $user->name ?? 'there' }},
        </mj-text>
        <mj-text>
          Here are {{ count($messages) }} new posts on {{ $group->nameshort }}:
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach($messages as $message)
    <mj-section css-class="message-card">
      <mj-column width="25%">
        @if($message->attachments->isNotEmpty())
        <mj-image width="80px" src="{{ $message->attachments->first()->url }}" />
        @endif
      </mj-column>
      <mj-column width="75%">
        <mj-text css-class="message-title">
          {{ $message->subject }}
        </mj-text>
        <mj-text font-size="12px" color="#666666">
          {{ Str::limit($message->textbody, 150) }}
        </mj-text>
        <mj-button href="{{ $userSite }}/message/{{ $message->id }}">
          View Post
        </mj-button>
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#f4f4f4">
      <mj-column>
        <mj-text font-size="12px" color="#666666" align="center">
          To change how often you get these emails,
          <a href="{{ $settingsUrl }}">click here</a>.
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
```

### Migration from Old System

The Laravel batch system will run alongside the existing PHP cron jobs initially, with feature flags to control which system handles each job type. This allows for gradual migration and rollback capability.

```php
// Feature flag in config
'features' => [
    'use_laravel_digest' => env('FREEGLE_USE_LARAVEL_DIGEST', false),
    'use_laravel_chat_notify' => env('FREEGLE_USE_LARAVEL_CHAT_NOTIFY', false),
    // ...
],
```

### Success Criteria

1. **Functional Parity**: All batch jobs produce the same results as the PHP versions
2. **Test Coverage**: 90% code coverage across all new code
3. **Performance**: Equal or better performance than PHP versions
4. **Email Rendering**: All emails render correctly in major email clients
5. **Index Usage**: Database queries use the same indexes as original code
6. **Error Handling**: Proper logging and error reporting to Sentry
7. **Documentation**: Complete documentation for all commands and configuration

### Risks & Mitigations

| Risk | Mitigation |
|------|------------|
| Database query performance differs | Verify query plans match, use raw SQL where needed |
| MJML rendering issues | Test all templates in Email on Acid or similar |
| Email delivery timing differs | Compare logs between systems during parallel run |
| Memory usage increases | Profile and optimize, use chunked processing |
| Complex business logic missed | Comprehensive testing against production data snapshots |

### Next Steps

1. Create the `iznik-server-laravel` directory structure
2. Initialize Laravel project
3. Configure Docker container
4. Generate Eloquent models from schema
5. Begin Phase 1 implementation

---

## Implementation Status

### Completed

#### Phase 1: Core Infrastructure & Digest/Chat
- [x] Laravel 12 project setup
- [x] Docker container configuration
- [x] Eloquent models for existing schema (User, Group, Message, Membership, ChatRoom, ChatMessage, etc.)
- [x] MJML email infrastructure with spatie/mjml-php
- [x] Base traits (ChunkedProcessing, ShardedExecution, GracefulShutdown)
- [x] DigestService and SendDigestCommand
- [x] ChatNotificationService and User2User/User2Mod commands
- [x] MJML templates (header, footer, digest single/multiple, chat notification)
- [x] Unit tests for DigestService, ChatNotificationService
- [x] Feature tests for digest and chat commands

#### Phase 2: Message Expiry & Purge
- [x] MessageExpiryService for deadline processing
- [x] PurgeService for chat, message, and data cleanup
- [x] DeadlineReached email with MJML template
- [x] ProcessExpiredMessagesCommand
- [x] PurgeChatsCommand, PurgeMessagesCommand, PurgeAllCommand
- [x] Unit tests for MessageExpiryService, PurgeService

#### Phase 3: Donations & User Management
- [x] DonationService for thank you emails and donation requests
- [x] UserManagementService for bounced emails, kudos, retention
- [x] DonationThankYou and AskForDonation emails with MJML templates
- [x] ThankDonorsCommand, AskDonationsCommand
- [x] UpdateKudosCommand, ProcessBouncedEmailsCommand, RetentionStatsCommand
- [x] Unit tests for DonationService, UserManagementService

#### Schedule Configuration
- [x] All commands registered in routes/console.php
- [x] Appropriate scheduling intervals matching original cron jobs

### Files Created

```
iznik-server-laravel/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       ├── Chat/
│   │       │   ├── NotifyUser2UserCommand.php
│   │       │   └── NotifyUser2ModCommand.php
│   │       ├── Digest/
│   │       │   └── SendDigestCommand.php
│   │       ├── Donation/
│   │       │   ├── AskDonationsCommand.php
│   │       │   └── ThankDonorsCommand.php
│   │       ├── Message/
│   │       │   └── ProcessExpiredMessagesCommand.php
│   │       ├── Purge/
│   │       │   ├── PurgeAllCommand.php
│   │       │   ├── PurgeChatsCommand.php
│   │       │   └── PurgeMessagesCommand.php
│   │       └── User/
│   │           ├── ProcessBouncedEmailsCommand.php
│   │           ├── RetentionStatsCommand.php
│   │           └── UpdateKudosCommand.php
│   ├── Mail/
│   │   ├── MjmlMailable.php
│   │   ├── Chat/
│   │   │   └── ChatNotification.php
│   │   ├── Digest/
│   │   │   ├── MultipleDigest.php
│   │   │   └── SingleDigest.php
│   │   ├── Donation/
│   │   │   ├── AskForDonation.php
│   │   │   └── DonationThankYou.php
│   │   └── Message/
│   │       └── DeadlineReached.php
│   ├── Models/
│   │   ├── ChatImage.php
│   │   ├── ChatMessage.php
│   │   ├── ChatRoom.php
│   │   ├── ChatRoster.php
│   │   ├── GiftAid.php
│   │   ├── Group.php
│   │   ├── GroupDigest.php
│   │   ├── Membership.php
│   │   ├── Message.php
│   │   ├── MessageGroup.php
│   │   ├── MessageOutcome.php
│   │   ├── Notification.php
│   │   ├── User.php
│   │   ├── UserDonation.php
│   │   └── UserEmail.php
│   ├── Services/
│   │   ├── ChatNotificationService.php
│   │   ├── DigestService.php
│   │   ├── DonationService.php
│   │   ├── MessageExpiryService.php
│   │   ├── PurgeService.php
│   │   └── UserManagementService.php
│   └── Traits/
│       ├── ChunkedProcessing.php
│       ├── GracefulShutdown.php
│       └── ShardedExecution.php
├── config/
│   └── freegle.php
├── docker/
│   ├── Dockerfile
│   ├── entrypoint.sh
│   └── supervisor.conf
├── resources/
│   └── views/
│       └── emails/
│           └── mjml/
│               ├── chat/
│               │   └── notification.blade.php
│               ├── components/
│               │   ├── button.blade.php
│               │   ├── footer.blade.php
│               │   └── header.blade.php
│               ├── digest/
│               │   ├── multiple.blade.php
│               │   └── single.blade.php
│               ├── donation/
│               │   ├── ask.blade.php
│               │   └── thank-you.blade.php
│               └── message/
│                   └── deadline-reached.blade.php
├── routes/
│   └── console.php
└── tests/
    ├── Feature/
    │   ├── Chat/
    │   │   └── NotifyChatCommandTest.php
    │   └── Digest/
    │       └── SendDigestCommandTest.php
    └── Unit/
        ├── Models/
        │   ├── GroupModelTest.php
        │   └── UserModelTest.php
        └── Services/
            ├── ChatNotificationServiceTest.php
            ├── DigestServiceTest.php
            ├── DonationServiceTest.php
            ├── MessageExpiryServiceTest.php
            ├── PurgeServiceTest.php
            └── UserManagementServiceTest.php
```

### PHP Code Cleanup (Completed)

The following PHP code has been removed from iznik-server now that Laravel batch handles these emails:

#### Welcome Email (Generic)
- **Removed:** `User::welcome()` function from `include/user/User.php`
- **Removed:** `$u->welcome()` call from `include/mail/MailRouter.php` (email subscription flow)
- **Removed:** `mailtemplates/mjml/welcome/welcome.mjml` and `mailtemplates/twig/welcome/welcome.html`
- **Removed:** `scripts/fix/fix_welcome.php`
- **Kept:** Per-group welcome emails (`Group::sendWelcome()`) - still sent via PHP

### Remaining Work

1. **Additional Commands**: More commands from the original 127 scripts could be ported (e.g., spam detection, moderator notifications, external integrations).
2. **Run Tests**: Execute the test suite against the Docker environment.
3. **Email Client Testing**: Verify MJML emails render correctly across clients.
4. **Performance Profiling**: Compare query performance with original PHP.
5. **Parallel Run**: Run alongside existing PHP crons to compare outputs.
6. **Coverage Report**: Generate and review code coverage metrics.

---

## Go API to Laravel Email Integration

### Problem Statement

As the v2 Go API handles more functionality, it needs a clean way to trigger email generation via the Laravel batch system. The Go API should be able to request emails without duplicating the MJML templates or email sending logic.

### Recommended Architecture: MySQL-based Job Queue

The simplest, most robust approach is to use a MySQL table as a job queue. This aligns with the existing database-centric architecture and requires no additional infrastructure.

#### Database Schema

```sql
CREATE TABLE `email_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email_type` varchar(100) NOT NULL COMMENT 'Email template type (e.g., welcome, chat_notification, donation_request)',
  `payload` json NOT NULL COMMENT 'JSON payload with all data needed for email',
  `status` enum('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `priority` tinyint NOT NULL DEFAULT '5' COMMENT '1=urgent, 5=normal, 10=low',
  `attempts` tinyint NOT NULL DEFAULT '0',
  `max_attempts` tinyint NOT NULL DEFAULT '3',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `processed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_priority_created` (`status`, `priority`, `created_at`),
  KEY `email_type` (`email_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

#### Go API Side

```go
// internal/email/queue.go
package email

import (
    "database/sql"
    "encoding/json"
)

type EmailJob struct {
    Type     string      `json:"type"`
    Payload  interface{} `json:"payload"`
    Priority int         `json:"priority"`
}

func (q *EmailQueue) Enqueue(db *sql.DB, job EmailJob) error {
    payloadJSON, err := json.Marshal(job.Payload)
    if err != nil {
        return err
    }

    _, err = db.Exec(`
        INSERT INTO email_jobs (email_type, payload, priority)
        VALUES (?, ?, ?)
    `, job.Type, payloadJSON, job.Priority)
    return err
}

// Example usage for welcome email
func SendWelcomeEmail(db *sql.DB, userID int64, password string) error {
    return queue.Enqueue(db, EmailJob{
        Type: "welcome",
        Payload: map[string]interface{}{
            "user_id":  userID,
            "password": password,
        },
        Priority: 1, // Urgent
    })
}
```

#### Laravel Side

```php
// app/Console/Commands/Email/ProcessEmailQueueCommand.php
namespace App\Console\Commands\Email;

use App\Mail\Welcome\WelcomeMail;
use App\Mail\Chat\ChatNotification;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ProcessEmailQueueCommand extends Command
{
    protected $signature = 'freegle:email:process-queue
                            {--batch=100 : Number of jobs to process}';

    protected $description = 'Process email jobs from the queue';

    private array $emailHandlers = [
        'welcome' => 'handleWelcome',
        'chat_notification' => 'handleChatNotification',
        'donation_request' => 'handleDonationRequest',
        // Add more email types as needed
    ];

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');

        // Claim jobs atomically
        $jobs = DB::table('email_jobs')
            ->where('status', 'pending')
            ->orderBy('priority')
            ->orderBy('created_at')
            ->limit($batchSize)
            ->lockForUpdate()
            ->get();

        foreach ($jobs as $job) {
            $this->processJob($job);
        }

        return Command::SUCCESS;
    }

    private function processJob(object $job): void
    {
        DB::table('email_jobs')
            ->where('id', $job->id)
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
            ]);

        try {
            $handler = $this->emailHandlers[$job->email_type] ?? null;
            if (!$handler || !method_exists($this, $handler)) {
                throw new \Exception("Unknown email type: {$job->email_type}");
            }

            $payload = json_decode($job->payload, true);
            $this->$handler($payload);

            DB::table('email_jobs')
                ->where('id', $job->id)
                ->update([
                    'status' => 'completed',
                    'processed_at' => now(),
                ]);
        } catch (\Exception $e) {
            $status = $job->attempts >= $job->max_attempts ? 'failed' : 'pending';
            DB::table('email_jobs')
                ->where('id', $job->id)
                ->update([
                    'status' => $status,
                    'error_message' => $e->getMessage(),
                ]);
        }
    }

    private function handleWelcome(array $payload): void
    {
        $user = User::findOrFail($payload['user_id']);
        Mail::send(new WelcomeMail($user, $payload['password'] ?? null));
    }

    private function handleChatNotification(array $payload): void
    {
        // Implementation for chat notifications
    }

    private function handleDonationRequest(array $payload): void
    {
        // Implementation for donation requests
    }
}
```

### Alternative Architectures Considered

#### 1. Redis Queue
- **Pros**: Fast, native Laravel queue support, job delays
- **Cons**: Additional infrastructure, Redis not currently used, adds complexity

#### 2. Direct HTTP API Call from Go to Laravel
- **Pros**: Immediate feedback, simpler Go code
- **Cons**: Tight coupling, requires exposing Laravel endpoints, network failures block Go

#### 3. RabbitMQ / AMQP
- **Pros**: Enterprise-grade, guaranteed delivery
- **Cons**: Significant infrastructure overhead, overkill for this use case

#### 4. Beanstalkd (existing)
- **Pros**: Already in use for background.php
- **Cons**: Less Laravel-native, being phased out

### Why MySQL Queue is Recommended

1. **Zero new dependencies**: Uses existing MySQL infrastructure
2. **Transactional consistency**: Jobs can be inserted in same transaction as other data
3. **Easy debugging**: Jobs visible in database, easy to query/retry
4. **Familiar tooling**: Standard SQL queries for monitoring
5. **Atomic operations**: `SELECT ... FOR UPDATE` prevents double processing
6. **Automatic retry**: Built-in attempt tracking and failure handling
7. **Priority support**: Different email types can have different priorities
8. **Audit trail**: Completed jobs can be kept for debugging/analytics

### Implementation Steps

1. **Create migration** for `email_jobs` table
2. **Add Go email queue package** with type-safe job creation
3. **Create Laravel command** to process the queue
4. **Add to scheduler** (run every minute like other chat commands)
5. **Add monitoring** via status page API endpoint
6. **Write tests** for both Go and Laravel sides
7. **Gradual migration**: Start with one email type, expand as proven
