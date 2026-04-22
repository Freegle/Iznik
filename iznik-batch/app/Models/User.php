<?php

namespace App\Models;

use App\Support\EloquentUtils;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log as Logger;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_table.php
 * @property int $id
 * @property string|null $yahooUserId Unique ID of user on Yahoo if known
 * @property string|null $firstname
 * @property string|null $lastname
 * @property string|null $fullname
 * @property string $systemrole System-wide roles
 * @property \Illuminate\Support\Carbon $added
 * @property \Illuminate\Support\Carbon $lastaccess
 * @property array<array-key, mixed>|null $settings JSON-encoded settings
 * @property int $gotrealemail Until migrated, whether polled FD/TN to get real email
 * @property string|null $yahooid Any known YahooID for this user
 * @property int $licenses Any licenses not added to groups
 * @property int $newslettersallowed Central mails
 * @property int $relevantallowed
 * @property string|null $onholidaytill
 * @property int $marketingconsent Whether we have PECR consent
 * @property int $publishconsent Can we republish posts to non-members
 * @property int|null $lastlocation
 * @property string|null $lastrelevantcheck
 * @property string|null $lastidlechaseup
 * @property int $bouncing Whether preferred email has been determined to be bouncing
 * @property string|null $permissions
 * @property int|null $invitesleft
 * @property string|null $source
 * @property string $chatmodstatus
 * @property \Illuminate\Support\Carbon|null $deleted
 * @property int $inventedname
 * @property string $newsfeedmodstatus
 * @property int $replyambit
 * @property string|null $engagement
 * @property string|null $trustlevel
 * @property string|null $lastupdated
 * @property int|null $tnuserid
 * @property int|null $ljuserid
 * @property \Illuminate\Support\Carbon|null $forgotten
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatMessage> $chatMessages
 * @property-read int|null $chat_messages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatRoom> $chatRoomsAsUser1
 * @property-read int|null $chat_rooms_as_user1_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatRoom> $chatRoomsAsUser2
 * @property-read int|null $chat_rooms_as_user2_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserDonation> $donations
 * @property-read int|null $donations_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\EmailTracking> $emailTracking
 * @property-read int|null $email_tracking_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\UserEmail> $emails
 * @property-read int|null $emails_count
 * @property-read string $display_name
 * @property-read string|null $email_preferred
 * @property-read string|null $first_name
 * @property-read \App\Models\GiftAid|null $giftAid
 * @property-read \App\Models\Location|null $lastLocation
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Membership> $memberships
 * @property-read int|null $memberships_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Message> $messages
 * @property-read int|null $messages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Notification> $notifications
 * @property-read int|null $notifications_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBouncing($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereChatmodstatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEngagement($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFirstname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereForgotten($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFullname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereGotrealemail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereInventedname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereInvitesleft($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastaccess($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastidlechaseup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastlocation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastrelevantcheck($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastupdated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLicenses($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLjuserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMarketingconsent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNewsfeedmodstatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNewslettersallowed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereOnholidaytill($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePermissions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePublishconsent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRelevantallowed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereReplyambit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSettings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereSystemrole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTnuserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTrustlevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereYahooUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereYahooid($value)
 * @mixin \Eloquent
 */
class User extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    // Group membership roles.
    public const ROLE_NONMEMBER = 'Non-member';
    public const ROLE_MEMBER = 'Member';
    public const ROLE_MODERATOR = 'Moderator';
    public const ROLE_OWNER = 'Owner';

    // System-wide roles.
    public const SYSTEMROLE_USER = 'User';
    public const SYSTEMROLE_MODERATOR = 'Moderator';
    public const SYSTEMROLE_SUPPORT = 'Support';
    public const SYSTEMROLE_ADMIN = 'Admin';

    // Login types.
    public const LOGIN_NATIVE = 'Native';

    // Inactivity threshold — matches Engage::USER_INACTIVE (365 * 24 * 60 * 60 / 2 = ~182.5 days).
    // (Could move this to Engage if it gets ported to Laravel.)
    public const USER_INACTIVE = 365 * 24 * 60 * 60 / 2;

    // Gift aid period weights for merge comparison (lower = more favourable).
    public const GIFTAID_PERIOD_PAST_4_YEARS_AND_FUTURE = 'Past4YearsAndFuture';
    public const GIFTAID_PERIOD_SINCE = 'Since';
    public const GIFTAID_PERIOD_FUTURE = 'Future';
    public const GIFTAID_PERIOD_THIS = 'This';
    public const GIFTAID_PERIOD_DECLINED = 'Declined';

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'deleted' => 'datetime',
        'forgotten' => 'datetime',
        'settings' => 'array',
    ];

    /**
     * Get user's email addresses.
     */
    public function emails(): HasMany
    {
        return $this->hasMany(UserEmail::class, 'userid');
    }

    /**
     * Get user's memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'userid');
    }

    /**
     * Get chat rooms where user is user1.
     */
    public function chatRoomsAsUser1(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user1');
    }

    /**
     * Get chat rooms where user is user2.
     */
    public function chatRoomsAsUser2(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'user2');
    }

    /**
     * Get user's donations.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(UserDonation::class, 'userid');
    }

    /**
     * Get user's gift aid declaration.
     */
    public function giftAid(): HasOne
    {
        return $this->hasOne(GiftAid::class, 'userid');
    }

    /**
     * Get user's messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'fromuser');
    }

    /**
     * Get user's notifications.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'userid');
    }

    /**
     * Get user's chat messages.
     */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'userid');
    }

    /**
     * Get user's email tracking records.
     */
    public function emailTracking(): HasMany
    {
        return $this->hasMany(EmailTracking::class, 'userid');
    }

    /**
     * Get the user's preferred email address.
     *
     * Excludes internal Freegle domains (users.ilovefreegle.org, groups.ilovefreegle.org, etc.)
     * and Yahoo Groups addresses, matching iznik-server's getEmailPreferred() behavior.
     */
    public function getEmailPreferredAttribute(): ?string
    {
        $emails = $this->emails()
            ->orderByRaw('preferred DESC, validated DESC')
            ->pluck('email');

        foreach ($emails as $email) {
            if (!self::isInternalEmail($email)) {
                return $email;
            }
        }

        return NULL;
    }

    /**
     * Check if an email address is an internal Freegle domain that shouldn't receive external mail.
     *
     * Matches iznik-server's Mail::ourDomain() + GROUP_DOMAIN + yahoogroups filtering.
     */
    public static function isInternalEmail(string $email): bool
    {
        $email = strtolower($email);

        // Check against internal domains (users.ilovefreegle.org, groups.ilovefreegle.org, etc.)
        $internalDomains = config('freegle.mail.internal_domains', [
            'users.ilovefreegle.org',
            'groups.ilovefreegle.org',
            'direct.ilovefreegle.org',
            'republisher.freegle.in',
        ]);

        foreach ($internalDomains as $domain) {
            if (str_contains($email, '@' . strtolower($domain))) {
                return TRUE;
            }
        }

        // Check against excluded domain patterns (e.g., @yahoogroups.)
        $excludedPatterns = config('freegle.mail.excluded_domain_patterns', [
            '@yahoogroups.',
        ]);

        foreach ($excludedPatterns as $pattern) {
            if (str_contains($email, strtolower($pattern))) {
                return TRUE;
            }
        }

        return FALSE;
    }

    /**
     * Get full name or display name.
     * Strips the "-gXXX" suffix from TrashNothing user names.
     */
    public function getDisplayNameAttribute(): string
    {
        $name = null;

        if ($this->fullname) {
            $name = $this->fullname;
        } elseif ($this->firstname || $this->lastname) {
            $name = trim("{$this->firstname} {$this->lastname}");
        }

        if (!$name) {
            return 'Freegle User';
        }

        // Strip the "-gXXX" suffix from TrashNothing user names.
        return self::removeTNGroup($name);
    }

    /**
     * Remove TrashNothing group suffix from a name.
     * TN users often have names like "Alice-g298" - we hide the "-gXXX" part.
     */
    public static function removeTNGroup(string $name): string
    {
        return preg_replace('/^([\s\S]+?)-g[0-9]+$/', '$1', $name);
    }

    /**
     * Check if user is a moderator of any group.
     */
    public function isModerator(): bool
    {
        return $this->memberships()
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    /**
     * Check if user is a moderator of a specific group.
     */
    public function isModeratorOf(int $groupId): bool
    {
        return $this->memberships()
            ->where('groupid', $groupId)
            ->whereIn('role', ['Moderator', 'Owner'])
            ->exists();
    }

    /**
     * Get user's last known location.
     */
    public function lastLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'lastlocation');
    }

    /**
     * Get user's first name for personalization.
     */
    public function getFirstNameAttribute(): ?string
    {
        return $this->attributes['firstname'] ?? NULL;
    }

    /**
     * Check if this user is from TrashNothing.
     * TN users have email addresses ending in @user.trashnothing.com
     */
    public function isTN(): bool
    {
        $email = $this->email_preferred;
        return $email && str_ends_with($email, '@user.trashnothing.com');
    }

    /**
     * Remove an email address from this user.
     */
    public function removeEmail(string $email): void
    {
        UserEmail::where('userid', $this->id)
            ->where('email', $email)
            ->first()
            ?->delete();
    }

    /**
     * Canonical form of an email address for duplicate detection.
     * Mirrors User::canonMail() in iznik-server.
     */
    public static function canonMail(string $email): string
    {
        # Googlemail is Gmail really in US and UK.
        $email = str_replace('@googlemail.', '@gmail.', $email);
        $email = str_replace('@googlemail.co.uk', '@gmail.co.uk', $email);

        # Canonicalise TN addresses.
        if (preg_match('/(.*)\-(.*)(@user.trashnothing.com)/', $email, $matches)) {
            $email = $matches[1] . $matches[3];
        }

        # Remove plus addressing, which is sometimes used by spammers as a trick, except for Facebook where it
        # appears to be genuinely used for routing to distinct users.
        #
        # O2 puts a + at the start of an email address.  That would lead to us canonicalising all emails the same.
        if (substr($email, 0, 1) !== '+' && preg_match('/(.*)\+(.*)(@.*)/', $email, $matches) && strpos($email, '@proxymail.facebook.com') === FALSE) {
            $email = $matches[1] . $matches[3];
        }

        # Remove dots in LHS, which are ignored by gmail and can therefore be used to give the appearance of separate
        # emails.
        $p = strpos($email, '@');

        if ($p !== FALSE) {
            $lhs = substr($email, 0, $p);
            $rhs = substr($email, $p);

            if (stripos($rhs, '@gmail') !== FALSE || stripos($rhs, '@googlemail') !== FALSE) {
                $lhs = str_replace('.', '', $lhs);
            }

            # Remove dots from the RHS - saves a little space and is the format we have historically used.
            # Very unlikely to introduce ambiguity.
            $email = $lhs . str_replace('.', '', $rhs);
        }

        return $email;
    }

    public function addEmail(string $email, int $primary = 1, bool $changeprimary = TRUE): ?int
    {
        $email = trim($email);

        $groupDomain = config('freegle.group_domain');

        if (
            stripos($email, '-owner@yahoogroups.co') !== FALSE ||
            stripos($email, "-volunteers@{$groupDomain}") !== FALSE ||
            stripos($email, "-auto@{$groupDomain}") !== FALSE
        ) {
            # We don't allow people to add Yahoo owner addresses as the address of an individual user, or
            # the volunteer addresses.
            $rc = NULL;
        } else if (stripos($email, 'replyto-') !== FALSE || stripos($email, 'notify-') !== FALSE) {
            # This can happen because of dodgy email clients replying to the wrong place.  We don't want to end up
            # with this getting added to the user.
            $rc = NULL;
        } else {
            # If the email already exists in the table, then that's fine.  But we don't want to use INSERT IGNORE as
            # that scales badly for clusters.
            $canon = self::canonMail($email);

            $emails = UserEmail::select('id', 'preferred')
                ->where('userid', $this->id)
                ->where('email', $email)
                ->get();

            if ($emails->isEmpty()) {
                $newEmail = UserEmail::create([
                    'userid' => $this->id,
                    'email' => $email,
                    'preferred' => $primary,
                    'canon' => $canon,
                    'backwards' => strrev($canon),
                ]);
                $rc = $newEmail->id;

                if ($rc && $primary) {
                    # Make sure no other email is flagged as primary
                    UserEmail::where('userid', $this->id)
                        ->where('id', '!=', $rc)
                        ->where('preferred', '!=', 0)
                        ->get()
                        ->each(function ($other) {
                            $other->preferred = 0;
                            $other->save();
                        });
                }
            } else {
                $rc = $emails[0]->id;

                if ($changeprimary && $primary != $emails[0]->preferred) {
                    # Change in status.
                    $existing = UserEmail::find($rc);
                    if ($existing) {
                        $existing->preferred = $primary;
                        $existing->save();
                    }
                }

                if ($primary) {
                    # Make sure no other email is flagged as primary
                    UserEmail::where('userid', $this->id)
                        ->where('id', '!=', $rc)
                        ->where('preferred', '!=', 0)
                        ->get()
                        ->each(function ($other) {
                            $other->preferred = 0;
                            $other->save();
                        });

                    # If we've set an email we might no longer be bouncing.
                    $this->unbounce($rc);
                }
            }
        }

        $this->assignUserToToDonation($email, $this->id);

        return $rc;
    }

    /**
     * Haven't ported over logging behavior, add that if needed later.
     */
    public function unbounce(int $emailid): void
    {
        if ($emailid) {
            BounceEmail::where('emailid', $emailid)
                ->where('reset', '!=', 1)
                ->get()
                ->each(function ($bounce) {
                    $bounce->reset = 1;
                    $bounce->save();
                });
        }

        if ($this->bouncing != 0) {
            $this->bouncing = 0;
            $this->save();
        }
    }

    public function assignUserToToDonation(string $email, int $userid): void
    {
        $email = trim($email);

        if (strlen($email)) {
            # We might have donations made via PayPal using this email address which we can now link to this user.  Do
            # SELECT first to avoid this having to replicate in the cluster.
            $donations = UserDonation::where('Payer', $email)
                ->whereNull('userid')
                ->get();

            foreach ($donations as $donation) {
                // Check if user exists before updating to avoid foreign key constraint violations
                $userExists = User::where('id', $userid)->exists();
                if ($userExists) {
                    $donation->userid = $userid;
                    $donation->save();
                }
            }
        }
    }

    /**
     * Check if a notification type is enabled for this user.
     *
     * @param string $type The notification type (email, emailmine, push)
     * @param int|null $groupId Optional group ID for mod-specific checks
     */
    public function notifsOn(string $type, ?int $groupId = NULL): bool
    {
        // Default values for notification types.
        $defaults = [
            'email' => TRUE,
            'emailmine' => FALSE,
            'push' => TRUE,
        ];

        $settings = $this->settings ?? [];
        $notifs = $settings['notifications'] ?? [];

        $result = isset($notifs[$type]) ? (bool) $notifs[$type] : ($defaults[$type] ?? TRUE);

        // For group-specific checks, verify user is an active mod.
        if ($result && $groupId) {
            $result = $this->isModeratorOf($groupId);
        }

        return $result;
    }

    /**
     * Notification type constants matching iznik-server.
     */
    public const NOTIFS_EMAIL = 'email';
    public const NOTIFS_EMAIL_MINE = 'emailmine';
    public const NOTIFS_PUSH = 'push';

    /**
     * Simple mail setting constants matching iznik-server.
     *
     * SIMPLE_MAIL_NONE: Completely disables all emails.
     * SIMPLE_MAIL_BASIC: Daily digest, chat replies only.
     * SIMPLE_MAIL_FULL: Immediate notifications, all email types.
     */
    public const SIMPLE_MAIL_NONE = 'None';
    public const SIMPLE_MAIL_BASIC = 'Basic';
    public const SIMPLE_MAIL_FULL = 'Full';

    /**
     * Get the user's simple mail setting.
     *
     * @return string|null One of SIMPLE_MAIL_* constants or null if not set
     */
    public function getSimpleMail(): ?string
    {
        $settings = $this->settings ?? [];
        return $settings['simplemail'] ?? null;
    }

    /**
     * Determine if user wants digest emails based on their simplemail setting.
     * Falls back to checking per-group emailfrequency if simplemail is not set.
     *
     * @return bool True if user should receive digest emails
     */
    public function wantsDigestEmails(): bool
    {
        $simpleMail = $this->getSimpleMail();

        if ($simpleMail !== null) {
            // User has a simplemail preference.
            return $simpleMail !== self::SIMPLE_MAIL_NONE;
        }

        // Fall back to checking if any membership has a non-zero email frequency.
        return $this->memberships()
            ->where('emailfrequency', '!=', 0)
            ->exists();
    }

    /**
     * Whether we should send our mails to this user.
     *
     * Ported from iznik-server/include/user/User.php::sendOurMails().
     *
     * @param bool $checkHoliday Whether to check if user is on holiday
     * @param bool $checkBouncing Whether to check if user's email is bouncing
     * @return bool TRUE if this user should receive emails
     */
    public function sendOurMails(bool $checkHoliday = TRUE, bool $checkBouncing = TRUE): bool
    {
        if ($this->deleted) {
            return FALSE;
        }

        // We have two kinds of email settings - the top-level Simple one, and more detailed per group ones.
        // Where present the Simple one overrides the group ones, so check that first.
        $simpleMail = $this->getSimpleMail();
        if ($simpleMail === self::SIMPLE_MAIL_NONE) {
            return FALSE;
        }

        // We don't want to send emails to people who haven't been active for more than six months.  This improves
        // our spam reputation, by avoiding honeytraps.
        // This time is also present on the client in ModMember, and in Engage.
        $sendIt = FALSE;
        $lastaccess = $this->lastaccess;

        if ($lastaccess !== NULL && $lastaccess->timestamp >= (time() - self::USER_INACTIVE)) {
            $sendIt = TRUE;

            if ($sendIt && $checkHoliday) {
                // We might be on holiday.
                $hol = $this->onholidaytill;
                $till = $hol ? strtotime($hol) : 0;

                $sendIt = time() > $till;
            }

            if ($sendIt && $checkBouncing) {
                // And don't send if we're bouncing.
                $sendIt = !$this->bouncing;
            }
        }

        return $sendIt;
    }

    /**
     * Determine if user wants immediate notifications based on their simplemail setting.
     *
     * @return bool True if user should receive immediate notifications
     */
    public function wantsImmediateNotifications(): bool
    {
        $simpleMail = $this->getSimpleMail();

        if ($simpleMail !== null) {
            return $simpleMail === self::SIMPLE_MAIL_FULL;
        }

        // Fall back to checking if any membership has immediate frequency (-1).
        return $this->memberships()
            ->where('emailfrequency', -1)
            ->exists();
    }

    /**
     * Get user's latitude and longitude from their last location.
     *
     * @return array [lat, lng] or [null, null] if not available
     */
    public function getLatLng(): array
    {
        $location = $this->lastLocation;
        if (!$location) {
            return [NULL, NULL];
        }

        // Locations have a geometry column with a POINT.
        if ($location->lat && $location->lng) {
            return [$location->lat, $location->lng];
        }

        return [NULL, NULL];
    }

    /**
     * Get job ads for this user based on their location.
     *
     * @return array ['jobs' => Collection, 'location' => string|null]
     */
    public function getJobAds(): array
    {
        [$lat, $lng] = $this->getLatLng();

        if (!$lat || !$lng) {
            return [
                'jobs' => collect(),
                'location' => NULL,
            ];
        }

        $jobs = Job::nearLocation($lat, $lng, 4);

        return [
            'jobs' => $jobs,
            'location' => NULL,  // Not currently used.
        ];
    }

    /**
     * Get the user's profile image URL.
     *
     * Profile images are stored in users_images table with the image ID used in the URL.
     * Format: https://{IMAGE_DOMAIN}/tuimg_{image_id}.jpg (thumbnail)
     *
     * @param bool $thumbnail Whether to get thumbnail (tuimg_) or full size (uimg_)
     * @return string|null The profile image URL or null if no profile image
     */
    public function getProfileImageUrl(bool $thumbnail = TRUE): ?string
    {
        // Find the user's profile image, preferring the default one.
        $profileImage = UserImage::where('userid', $this->id)
            ->orderByDesc('default')
            ->orderBy('id')
            ->first(['id', 'url']);

        if (!$profileImage) {
            return NULL;
        }

        // If there's an external URL, use it directly.
        if (!empty($profileImage->url)) {
            return $profileImage->url;
        }

        // Build URL from image domain.
        $imagesDomain = config('freegle.images.domain', 'https://images.ilovefreegle.org');
        $prefix = $thumbnail ? 'tuimg_' : 'uimg_';

        return "{$imagesDomain}/{$prefix}{$profileImage->id}.jpg";
    }

    /**
     * Login type for one-click unsubscribe links.
     */
    public const LOGIN_LINK = 'Link';

    /**
     * Get user's key for one-click unsubscribe/login links.
     * Creates one if it doesn't exist.
     *
     * @return string The user key
     */
    public function getUserKey(): string
    {
        // Check for existing LOGIN_LINK credential.
        $login = UserLogin::where('userid', $this->id)
            ->where('type', self::LOGIN_LINK)
            ->first(['credentials']);

        if ($login && $login->credentials) {
            return $login->credentials;
        }

        // Create a new key.
        $key = bin2hex(random_bytes(16));

        UserLogin::create([
            'userid' => $this->id,
            'type' => self::LOGIN_LINK,
            'credentials' => $key,
        ]);

        return $key;
    }

    /**
     * Generate List-Unsubscribe header value for RFC 8058 one-click unsubscribe.
     *
     * @return string The unsubscribe URL in angle brackets
     */
    public function listUnsubscribe(): string
    {
        return "<{$this->listUnsubscribeUrl()}>";
    }

    /**
     * Generate the one-click unsubscribe URL for use in email links.
     *
     * @return string The unsubscribe URL
     */
    public function listUnsubscribeUrl(): string
    {
        $key = $this->getUserKey();
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return "{$userSite}/one-click-unsubscribe/{$this->id}/{$key}";
    }

    /**
     * Generate the marketing opt-out URL for marketing/non-essential admin emails.
     *
     * Uses the same getUserKey() mechanism as unsubscribe for authentication.
     *
     * @return string The marketing opt-out URL
     */
    public function marketingOptOutUrl(): string
    {
        $key = $this->getUserKey();
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');

        return "{$userSite}/marketing-optout?u={$this->id}&k={$key}";
    }

    /**
     * Check if this user allows merging.
     * Users can set canmerge=false in their settings to prevent being merged.
     */
    public function canMerge(): bool
    {
        $settings = $this->settings ?? [];
        return $settings['canmerge'] ?? TRUE;
    }

    /**
     * Return the highest privilege group role between two roles.
     * Order: Owner > Moderator > Member > Non-member
     */
    public static function roleMax(string $role1, string $role2): string
    {
        $role = self::ROLE_NONMEMBER;

        if ($role1 === self::ROLE_MEMBER || $role2 === self::ROLE_MEMBER) {
            $role = self::ROLE_MEMBER;
        }

        if ($role1 === self::ROLE_MODERATOR || $role2 === self::ROLE_MODERATOR) {
            $role = self::ROLE_MODERATOR;
        }

        if ($role1 === self::ROLE_OWNER || $role2 === self::ROLE_OWNER) {
            $role = self::ROLE_OWNER;
        }

        return $role;
    }

    /**
     * Return the highest system role between two roles.
     * Order: Admin > Support > Moderator > User
     */
    public static function systemRoleMax(string $role1, string $role2): string
    {
        $role = self::SYSTEMROLE_USER;

        if ($role1 === self::SYSTEMROLE_MODERATOR || $role2 === self::SYSTEMROLE_MODERATOR) {
            $role = self::SYSTEMROLE_MODERATOR;
        }

        if ($role1 === self::SYSTEMROLE_SUPPORT || $role2 === self::SYSTEMROLE_SUPPORT) {
            $role = self::SYSTEMROLE_SUPPORT;
        }

        if ($role1 === self::SYSTEMROLE_ADMIN || $role2 === self::SYSTEMROLE_ADMIN) {
            $role = self::SYSTEMROLE_ADMIN;
        }

        return $role;
    }

    /**
     * Merge two user accounts, consolidating $id2 into $id1.
     *
     * Merges memberships (taking highest role, oldest join date), emails,
     * chat rooms, user attributes, logs, gift aid, and 40+ foreign key tables.
     * The secondary user ($id2) is deleted after a successful merge.
     *
     * Ported from iznik-server/include/user/User.php::merge().
     *
     * @param int $id1 The user ID to keep (merge target)
     * @param int $id2 The user ID to absorb and delete
     * @param string $reason Human-readable reason for the merge
     * @param bool $forceMerge If TRUE, bypass canMerge() checks
     * @param int|null $byUserId The user performing the merge (for logging)
     * @return bool TRUE on success, FALSE on failure or if merge is blocked
     */
    public static function merge(int $id1, int $id2, string $reason, bool $forceMerge = FALSE, ?int $byUserId = NULL): bool
    {
        Logger::info("Merge {$id2} into {$id1}, {$reason}");

        if ($id1 === $id2) {
            return FALSE;
        }

        $u1 = self::find($id1);
        $u2 = self::find($id2);

        if (!$u1 || !$u2) {
            return FALSE;
        }

        if (!$forceMerge && (!$u1->canMerge() || !$u2->canMerge())) {
            return FALSE;
        }

        try {

            # We want to merge two users.  At present we just merge the memberships, comments, emails and logs; we don't try to
            # merge any conflicting settings.
            #
            # Both users might have membership of the same group, including at different levels.
            #
            # A useful query to find foreign key references is of this form:
            #
            # USE information_schema; SELECT * FROM KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'iznik' AND REFERENCED_TABLE_NAME = 'users';
            #
            # We avoid too much use of quoting in preQuery/preExec because quoted numbers can't use a numeric index and therefore
            # perform slowly.

            DB::beginTransaction();

            // --- Merge memberships ---
            $id2Memberships = Membership::where('userid', $id2)->get();

            # Merge the top-level memberships
            foreach ($id2Memberships as $id2Memb) {
                $id1Memb = Membership::where('userid', $id1)
                    ->where('groupid', $id2Memb->groupid)
                    ->first();

                if (!$id1Memb) {
                    // id1 is not already a member — just reassign the membership.
                    $id2Memb->userid = $id1;
                    Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=update where=userid={$id2},groupid={$id2Memb->groupid} set=userid={$id1}");
                    // $id2Memb->save(); // TRACE: commented out for port testing
                } else {
                    // Both are members — merge: take highest role, oldest date, non-NULL attributes.
                    $role = self::roleMax($id1Memb->role, $id2Memb->role);

                    if ($role !== $id1Memb->role) {
                        $id1Memb->role = $role;
                        Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=update where=userid={$id1},groupid={$id2Memb->groupid} set=role={$role}");
                    }

                    // Keep the older added date.
                    $date = min(strtotime($id1Memb->added), strtotime($id2Memb->added));
                    $id1Memb->added = date('Y-m-d H:i:s', $date);
                    Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=update where=userid={$id1},groupid={$id2Memb->groupid} set=added={$id1Memb->added}");

                    // Take non-NULL values from id2 for these attributes.
                    foreach (['configid', 'settings', 'heldby'] as $key) {
                        if ($id2Memb->$key !== NULL) {
                            $id1Memb->$key = $id2Memb->$key;
                            Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=update where=userid={$id1},groupid={$id2Memb->groupid} set={$key}=" . (is_string($id2Memb->$key) && strlen($id2Memb->$key) > 50 ? ('len=' . strlen($id2Memb->$key)) : $id2Memb->$key));
                        }
                    }

                    // $id1Memb->save(); // TRACE: commented out for port testing

                    // Remove the now-redundant id2 membership.
                    // $id2Memb->delete(); // TRACE: commented out for port testing
                }
            }

            // --- Merge emails ---
            // Both might have a primary (preferred) address; id1 wins.
            $primary = NULL;
            $foundPrim = FALSE;

            $id2PrimaryEmail = UserEmail::where('userid', $id2)
                ->where('preferred', 1)
                ->first();

            if ($id2PrimaryEmail) {
                $primary = $id2PrimaryEmail->id;
                $foundPrim = TRUE;
            }

            $id1PrimaryEmail = UserEmail::where('userid', $id1)
                ->where('preferred', 1)
                ->first();

            if ($id1PrimaryEmail) {
                $primary = $id1PrimaryEmail->id;
                $foundPrim = TRUE;
            }

            if (!$foundPrim) {
                // No primary — use whatever getEmailPreferred would choose for id1.
                $preferredEmail = $u1->email_preferred;
                if ($preferredEmail) {
                    $emailRow = UserEmail::where('email', $preferredEmail)
                        ->first();
                    if ($emailRow) {
                        $primary = $emailRow->id;
                    }
                }
            }

            // Move all id2 emails to id1, clearing preferred.
            Logger::info("TN-SYNC-TRACE [WRITE] table=users_emails op=update where=userid={$id2} set=userid={$id1},preferred=0");
            UserEmail::where('userid', $id2)->get()->each(function ($emailRow) use ($id1) {
                $emailRow->userid = $id1;
                $emailRow->preferred = 0;
                // $emailRow->save(); // TRACE: commented out for port testing
            });

            if ($primary) {
                Logger::info("TN-SYNC-TRACE [WRITE] table=users_emails op=update where=id={$primary} set=preferred=1");
                $primaryRow = UserEmail::find($primary);
                if ($primaryRow) {
                    $primaryRow->preferred = 1;
                    // $primaryRow->save(); // TRACE: commented out for port testing
                }
            }

            // --- Merge foreign keys (less critical — use IGNORE equivalent) ---
            // For tables with unique constraints, per-row conflicts trigger a delete of the
            // offending id2 row (matches net effect of the original UPDATE IGNORE + cascade).
            EloquentUtils::reparentRowIgnore(LocationExcluded::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(ChatRoster::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserSession::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(SpamUser::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(SpamUser::class, 'byuserid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserAddress::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserDonation::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserImage::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserInvitation::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserNearby::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Notification::class, 'fromuser', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Notification::class, 'touser', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserNudge::class, 'fromuser', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserNudge::class, 'touser', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserPushNotification::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserRequest::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserRequest::class, 'completedby', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserSearch::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Newsfeed::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(MessageReneged::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserStory::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserStoryLike::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserStoryRequested::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserThanks::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(ModNotif::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(TeamMember::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserAboutMe::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Rating::class, 'rater', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Rating::class, 'ratee', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserReplyTime::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(MessagePromise::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(MessageBy::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Tryst::class, 'user1', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Tryst::class, 'user2', $id2, $id1);
            EloquentUtils::reparentRowIgnore(IsochroneUser::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(Microaction::class, 'userid', $id2, $id1);

            // Non-IGNORE updates (no unique constraint conflicts expected).
            EloquentUtils::reparentRow(UserComment::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRow(UserComment::class, 'byuserid', $id2, $id1);
            EloquentUtils::reparentRow(UserLogin::class, 'userid', $id2, $id1);

            // Update Native login uid to match new userid.
            Logger::info("TN-SYNC-TRACE [WRITE] table=users_logins op=update where=userid={$id1},type=" . self::LOGIN_NATIVE . " set=uid={$id1}");
            UserLogin::where('userid', $id1)
                ->where('type', self::LOGIN_NATIVE)
                ->where('uid', '!=', (string) $id1)
                ->get()
                ->each(function ($nativeLogin) use ($id1) {
                    try {
                        $nativeLogin->uid = (string) $id1;
                        // $nativeLogin->save(); // TRACE: commented out for port testing
                    } catch (QueryException $e) {
                        Logger::warning("Native login uid update conflict for {$nativeLogin->getKey()}: " . $e->getMessage());
                    }
                });

            // --- Handle bans ---
            EloquentUtils::reparentRowIgnore(UserBanned::class, 'userid', $id2, $id1);
            EloquentUtils::reparentRowIgnore(UserBanned::class, 'byuser', $id2, $id1);

            // Remove memberships for groups the merged user is banned from.
            $bans = UserBanned::where('userid', $id1)->get();
            foreach ($bans as $ban) {
                Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=delete where=userid={$id1},groupid={$ban->groupid}");
                Membership::where('userid', $id1)
                    ->where('groupid', $ban->groupid)
                    ->first();
                    // ?->delete(); // TRACE: commented out for port testing
            }

            // --- Merge chat rooms ---
            $rooms = ChatRoom::where(function ($q) use ($id2) {
                $q->where('user1', $id2)->orWhere('user2', $id2);
            })
                ->whereIn('chattype', [ChatRoom::TYPE_USER2MOD, ChatRoom::TYPE_USER2USER])
                ->get();

            foreach ($rooms as $room) {
                $existing = NULL;

                if ($room->chattype === ChatRoom::TYPE_USER2MOD) {
                    $existing = ChatRoom::where('user1', $id1)
                        ->where('groupid', $room->groupid)
                        ->first();
                } elseif ($room->chattype === ChatRoom::TYPE_USER2USER) {
                    $other = ($room->user1 == $id2) ? $room->user2 : $room->user1;
                    $existing = ChatRoom::where(function ($q) use ($id1, $other) {
                        $q->where(function ($q2) use ($id1, $other) {
                            $q2->where('user1', $id1)->where('user2', $other);
                        })->orWhere(function ($q2) use ($id1, $other) {
                            $q2->where('user2', $id1)->where('user1', $other);
                        });
                    })
                        ->first();
                }

                if ($existing) {
                    // Room already exists for id1 — move messages into it.
                    Logger::info("TN-SYNC-TRACE [WRITE] table=chat_messages op=update where=chatid={$room->id} set=chatid={$existing->id}");
                    ChatMessage::where('chatid', $room->id)->get()->each(function ($chatMessage) use ($existing) {
                        $chatMessage->chatid = $existing->id;
                        // $chatMessage->save(); // TRACE: commented out for port testing
                    });

                    // Keep the latest message timestamp.
                    Logger::info("TN-SYNC-TRACE [WRITE] table=chat_rooms op=update where=id={$existing->id} set=latestmessage=" . (is_string($room->latestmessage) && strlen($room->latestmessage) > 50 ? ('len=' . strlen($room->latestmessage)) : $room->latestmessage));
                    if ($room->latestmessage && (!$existing->latestmessage || $room->latestmessage > $existing->latestmessage)) {
                        $existing->latestmessage = $room->latestmessage;
                        // $existing->save(); // TRACE: commented out for port testing
                    }
                } else {
                    // No existing room — just reassign user reference.
                    $col = ($room->user1 == $id2) ? 'user1' : 'user2';
                    $room->$col = $id1;
                    Logger::info("TN-SYNC-TRACE [WRITE] table=chat_rooms op=update where=id={$room->id} set={$col}={$id1}");
                    // $room->save(); // TRACE: commented out for port testing
                }
            }

            // Move all remaining chat messages from id2.
            EloquentUtils::reparentRow(ChatMessage::class, 'userid', $id2, $id1);

            // --- Merge user attributes (keep non-NULL from id2 if id1 is NULL) ---
            // Refresh models after membership changes.
            $u1->refresh();
            $u2->refresh();

            foreach (['fullname', 'firstname', 'lastname', 'yahooid'] as $att) {
                $id2Value = $u2->$att;
                if ($id2Value === NULL) {
                    continue;
                }

                // Clear id2's attribute first (unique key safety for yahooid).
                $u2->$att = NULL;
                Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id2} set={$att}=NULL");
                // $u2->save(); // TRACE: commented out for port testing

                // Don't overwrite a name with FBUser or a -owner address.
                $isDodgyName = $att === 'fullname'
                    && (stripos($id2Value, 'fbuser') !== FALSE || stripos($id2Value, '-owner') !== FALSE);

                if ($u1->$att === NULL && !$isDodgyName) {
                    $u1->$att = $id2Value;
                    Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=" . ($att !== 'fullname' ? "id={$id1},{$att}=NULL" : "id={$id1}") . " set={$att}=" . (is_string($id2Value) && strlen($id2Value) > 50 ? ('len=' . strlen($id2Value)) : $id2Value));
                    // $u1->save(); // TRACE: commented out for port testing
                }

                $u1->refresh();
            }

            // --- Merge logs ---
            EloquentUtils::reparentRow(Log::class, 'user', $id2, $id1);
            EloquentUtils::reparentRow(Log::class, 'byuser', $id2, $id1);

            // --- Merge messages ---
            EloquentUtils::reparentRow(Message::class, 'fromuser', $id2, $id1);

            // --- Merge history ---
            EloquentUtils::reparentRow(MessageHistory::class, 'fromuser', $id2, $id1);
            EloquentUtils::reparentRow(MembershipHistory::class, 'userid', $id2, $id1);

            // --- Merge system role (take highest) ---
            $u1->refresh();
            $u2->refresh();

            $mergedSystemRole = self::systemRoleMax($u1->systemrole, $u2->systemrole);
            $u1->systemrole = $mergedSystemRole;
            Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id1} set=systemrole={$mergedSystemRole}");
            // $u1->save(); // TRACE: commented out for port testing

            // --- Merge added date (keep oldest) ---
            $earlierAdded = ($u1->added < $u2->added) ? $u1->added : $u2->added;
            $u1->added = $earlierAdded;
            Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id1} set=added={$earlierAdded}");
            $u1->lastupdated = now();
            Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id1} set=lastupdated=NOW()");
            // $u1->save(); // TRACE: commented out for port testing

            // --- Merge TN user ID ---
            $tnId1 = $u1->tnuserid;
            $tnId2 = $u2->tnuserid;

            if (!$tnId1 && $tnId2) {
                $u2->tnuserid = NULL;
                Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id2} set=tnuserid=NULL");
                // $u2->save(); // TRACE: commented out for port testing
                $u1->tnuserid = $tnId2;
                Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$id1} set=tnuserid={$tnId2}");
                // $u1->save(); // TRACE: commented out for port testing
            }

            // --- Merge gift aid (keep most favourable declaration) ---
            $giftAids = GiftAid::whereIn('userid', [$id1, $id2])
                ->orderBy('id')
                ->get();

            if ($giftAids->isNotEmpty()) {
                $weights = [
                    self::GIFTAID_PERIOD_PAST_4_YEARS_AND_FUTURE => 0,
                    self::GIFTAID_PERIOD_SINCE => 1,
                    self::GIFTAID_PERIOD_FUTURE => 2,
                    self::GIFTAID_PERIOD_THIS => 3,
                    self::GIFTAID_PERIOD_DECLINED => 4,
                ];

                $best = NULL;
                foreach ($giftAids as $giftAid) {
                    $weight = $weights[$giftAid->period] ?? 999;
                    $bestWeight = $best ? ($weights[$best->period] ?? 999) : 999;

                    if ($best === NULL || $weight < $bestWeight) {
                        $best = $giftAid;
                    }
                }

                // Delete all except the best.
                foreach ($giftAids as $giftAid) {
                    if ($giftAid->id !== $best->id) {
                        Logger::info("TN-SYNC-TRACE [WRITE] table=giftaid op=delete where=id={$giftAid->id}");
                        // $giftAid->delete(); // TRACE: commented out for port testing
                    }
                }

                // Assign the best to id1.
                Logger::info("TN-SYNC-TRACE [WRITE] table=giftaid op=update where=id={$best->id} set=userid={$id1}");
                if ($best->userid !== $id1) {
                    $best->userid = $id1;
                    // $best->save(); // TRACE: commented out for port testing
                }
            }

            // --- Log the merge (before deleting id2) ---
            $mergeText = "Merged {$id2} into {$id1} ({$reason})";

            $logId2 = new Log();
            $logId2->timestamp = now();
            $logId2->type = 'User';
            $logId2->subtype = 'Merged';
            $logId2->user = $id2;
            $logId2->byuser = $byUserId;
            $logId2->text = $mergeText;
            Logger::info("TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=User,subtype=Merged,user={$id2},byuser=" . ($byUserId ?? 'NULL') . ",text=len=" . strlen($mergeText));
            // $logId2->save(); // TRACE: commented out for port testing

            $logId1 = new Log();
            $logId1->timestamp = now();
            $logId1->type = 'User';
            $logId1->subtype = 'Merged';
            $logId1->user = $id1;
            $logId1->byuser = $byUserId;
            $logId1->text = $mergeText;
            Logger::info("TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=User,subtype=Merged,user={$id1},byuser=" . ($byUserId ?? 'NULL') . ",text=len=" . strlen($mergeText));
            // $logId1->save(); // TRACE: commented out for port testing

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Logger::error("Merge exception: " . $e->getMessage());
            return FALSE;
        }

        # Finally, delete id2.  We used to this inside the transaction, but the result was that
        # fromuser sometimes got set to NULL on messages owned by id2, despite them having been set to
        # id1 earlier on.  Either we're dumb, or there's a subtle interaction between transactions,
        # foreign keys and Percona clusters.  This is safer and proves to be more reliable.
        #
        # Make sure we don't pick up an old cached version, as we've just changed it quite a bit.
        try {
            Membership::where('userid', $id2)->get()->each(function ($m) {
                Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=delete where=userid={$m->userid},groupid={$m->groupid}");
                // $m->delete(); // TRACE: commented out for port testing
            });
            Logger::info("TN-SYNC-TRACE [WRITE] table=users op=delete where=id={$id2}");
            // User::find($id2)?->delete(); // TRACE: commented out for port testing
            Logger::info("Merged {$id1} < {$id2}, {$reason}");
        } catch (\Exception $e) {
            Logger::error("Failed to delete merged user {$id2}: " . $e->getMessage());
            // The merge itself succeeded — the user data is consolidated in id1.
            // A dangling id2 row is less harmful than rolling back the entire merge.
        }

        return TRUE;
    }

    /**
     * Wipe a user of personal data for the GDPR right to be forgotten.
     *
     * The user record itself is retained (marked as forgotten) so that message
     * statistics remain accurate.  All identifiable content is removed:
     * - Name, settings and Yahoo ID cleared
     * - External email addresses deleted (internal Freegle addresses kept)
     * - All login credentials deleted
     * - Message content cleared; messages without an outcome are withdrawn
     * - Chat message content cleared
     * - Community events, volunteering, newsfeed posts, stories, searches,
     *   about-me entries and ratings deleted
     * - All group memberships removed
     * - Postal addresses and profile images deleted
     * - Message promises deleted
     * - Sessions deleted
     * - Deletion logged
     *
     * Ported from iznik-server/include/user/User.php::forget().
     *
     * @param string $reason Human-readable reason for the deletion (e.g. 'GDPR request')
     */
    public function forget(string $reason): void
    {
        // --- Clear personal attributes ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=firstname=NULL");
        $this->firstname = NULL;
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=lastname=NULL");
        $this->lastname = NULL;
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=fullname=Deleted User #{$this->id}");
        $this->fullname = 'Deleted User #' . $this->id;
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=settings=NULL");
        $this->settings = NULL;
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=yahooid=NULL");
        $this->yahooid = NULL;
        // $this->save();  // TRACE: commented out for port testing

        // --- Delete external emails (keep internal Freegle addresses) ---
        foreach ($this->emails()->get() as $email) {
            if (!self::isInternalEmail($email->email)) {
                Logger::info("TN-SYNC-TRACE [WRITE] table=users_emails op=delete where=userid={$this->id},email={$email->email}");
                // $email->delete();  // TRACE: commented out for port testing
            }
        }

        // --- Delete all login credentials ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_logins op=delete where=userid={$this->id}");
        // UserLogin::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        // --- Clear message content and withdraw messages without an outcome ---
        $msgIds = Message::where('fromuser', $this->id)
            ->whereIn('type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
            ->pluck('id');

        foreach ($msgIds as $msgId) {
            // Update the field of the message
            $message = Message::find($msgId);
            $message->fromip = NULL;
            $message->message = NULL;
            $message->envelopefrom = NULL;
            $message->fromname = NULL;
            $message->fromaddr = NULL;
            $message->messageid = NULL;
            $message->textbody = NULL;
            $message->htmlbody = NULL;
            $message->deleted = now();
            Logger::info("TN-SYNC-TRACE [WRITE] table=messages op=update where=id={$msgId} set=fromip=NULL,message=NULL,envelopefrom=NULL,fromname=NULL,fromaddr=NULL,messageid=NULL,textbody=NULL,htmlbody=NULL,deleted=NOW()");
            // $message->save();  // TRACE: commented out for port testing

            // Mark the message group as deleted
            $messageGroup = MessageGroup::find($msgId);
            $messageGroup->deleted = 1;
            Logger::info("TN-SYNC-TRACE [WRITE] table=messages_groups op=update where=msgid={$msgId} set=deleted=1");
            // $messageGroup->save();  // TRACE: commented out for port testing

            // Clear any outcome comments that might contain personal data.
            foreach ($message->outcomes()->get() as $messageOutcome) {
                $messageOutcome->comments = NULL;
                Logger::info("TN-SYNC-TRACE [WRITE] table=messages_outcomes op=update where=msgid={$msgId} set=comments=NULL");
                // $messageOutcome->save();  // TRACE: commented out for port testing
            }

            // Withdraw if no outcome has been recorded yet.
            if (!$message->hasOutcome()) {
                $message->withdraw('Withdrawn on user unsubscribe', NULL);
            }
        }

        // --- Clear chat message content ---
        foreach ($this->chatMessages()->get() as $chatMessage) {
            $chatMessage->message = NULL;
            Logger::info("TN-SYNC-TRACE [WRITE] table=chat_messages op=update where=id={$chatMessage->id} set=message=NULL");
            // $chatMessage->save();  // TRACE: commented out for port testing
        }

        // --- Delete user-generated content ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=communityevents op=delete where=userid={$this->id}");
        // CommunityEvent::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=volunteering op=delete where=userid={$this->id}");
        // Volunteering::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=newsfeed op=delete where=userid={$this->id}");
        // Newsfeed::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_stories op=delete where=userid={$this->id}");
        // UserStory::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_searches op=delete where=userid={$this->id}");
        // UserSearch::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_aboutme op=delete where=userid={$this->id}");
        // UserAboutMe::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=ratings op=delete where=rater={$this->id}");
        // Rating::where('rater', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=ratings op=delete where=ratee={$this->id}");
        // Rating::where('ratee', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        // --- Remove all group memberships ---
        $groupIds = collect($this->getMembershipList())->pluck('id');
        foreach ($groupIds as $groupId) {
            Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=delete where=userid={$this->id},groupid={$groupId}");
            $this->removeMembership($groupId);
        }

        // --- Delete postal addresses and profile images ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_addresses op=delete where=userid={$this->id}");
        // UserAddress::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing
        Logger::info("TN-SYNC-TRACE [WRITE] table=users_images op=delete where=userid={$this->id}");
        // UserImage::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        // --- Delete message promises ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=messages_promises op=delete where=userid={$this->id}");
        // MessagePromise::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        // --- Mark user as forgotten ---
        $this->forgotten = now();
        $this->tnuserid = NULL;
        Logger::info("TN-SYNC-TRACE [WRITE] table=users op=update where=id={$this->id} set=forgotten=NOW(),tnuserid=NULL");
        // $this->save();  // TRACE: commented out for port testing

        // --- Delete sessions ---
        Logger::info("TN-SYNC-TRACE [WRITE] table=sessions op=delete where=userid={$this->id}");
        // UserSession::where('userid', $this->id)->get()->each->delete(); // TRACE: commented out for port testing

        // --- Log the deletion ---
        $log = new Log();
        $log->timestamp = now();
        $log->type = 'User';
        $log->subtype = 'Deleted';
        $log->user = $this->id;
        $log->text = $reason;
        Logger::info("TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=User,subtype=Deleted,user={$this->id},text=len=" . strlen($reason));
        // $log->save();  // TRACE: commented out for port testing
    }

    /**
     * Remove a user's membership from a group, optionally banning them.
     *
     * When banning, also inserts into users_banned and withdraws any active
     * Offer/Wanted messages the user has on the group.
     *
     * Ported from iznik-server/include/user/User.php::removeMembership().
     *
     * @param int $groupId The group to remove the user from
     * @param bool $ban If TRUE, also ban the user from the group and withdraw their messages
     * @param bool $spam If TRUE, log the removal as an automated spammer removal
     * @param int|null $byUserId The user performing the removal (for logging)
     * @param bool $byEmail If TRUE, send a farewell email to the user (also sent to TN users automatically)
     * @return bool TRUE if the membership was deleted (or a ban was recorded)
     */
    public function removeMembership(int $groupId, bool $ban = FALSE, bool $spam = FALSE, ?int $byUserId = NULL, bool $byEmail = FALSE): bool
    {
        // Notify TN users or email-triggered removals so they know they can no longer see messages.
        if ($byEmail || $this->isTN()) {
            Logger::info("TN-SYNC-TRACE [EMAIL] action=send-farewell user={$this->id} groupid={$groupId} to=" . ($this->email_preferred ?? 'NULL'));
            // TRACE: commented out for port testing
            // $group = Group::find($groupId);
            // $preferredEmail = $this->email_preferred;
            //
            // if ($group && $preferredEmail) {
            //     try {
            //         \Illuminate\Support\Facades\Mail::raw('Parting is such sweet sorrow.', function ($message) use ($group, $preferredEmail) {
            //             $message->subject('Farewell from ' . $group->nameshort)
            //                 ->from($group->getAutoEmail())
            //                 ->replyTo($group->getModsEmail())
            //                 ->to($preferredEmail);
            //         });
            //     } catch (\Exception $e) {
            //         Logger::warning("Failed to send farewell email for user {$this->id} on group {$groupId}: " . $e->getMessage());
            //     }
            // }
        }

        if ($ban) {
            // Record the ban.
            $userBanned = new UserBanned();
            $userBanned->userid = $this->id;
            $userBanned->groupid = $groupId;
            $userBanned->byuser = $byUserId;
            Logger::info("TN-SYNC-TRACE [WRITE] table=users_banned op=insert where=userid={$this->id},groupid={$groupId},byuser=" . ($byUserId ?? 'NULL'));
            // $userBanned->save();  // TRACE: commented out for port testing

            // Withdraw active Offer/Wanted messages on this group that have no outcome yet.
            $msgIds = MessageGroup::join('messages', 'messages_groups.msgid', '=', 'messages.id')
                ->where('messages.fromuser', $this->id)
                ->where('messages_groups.groupid', $groupId)
                ->whereIn('messages.type', [Message::TYPE_OFFER, Message::TYPE_WANTED])
                ->pluck('messages_groups.msgid');

            foreach ($msgIds as $msgId) {
                $m = Message::find($msgId);

                if ($m && !$m->hasOutcome()) {
                    $m->withdraw('Marked as withdrawn by ban', NULL, $byUserId);
                }
            }
        }

        // Remove the membership.
        Logger::info("TN-SYNC-TRACE [WRITE] table=memberships op=delete where=userid={$this->id},groupid={$groupId}");
        $membership = Membership::where('userid', $this->id)
            ->where('groupid', $groupId)
            ->first();
        $deleted = $membership ? 1 : 0;
        // $membership?->delete();  // TRACE: commented out for port testing

        if ($deleted || $ban) {
            $log = new Log();
            $log->timestamp = now();
            $log->type = 'Group';
            $log->subtype = 'Left';
            $log->user = $this->id;
            $log->byuser = $byUserId;
            $log->groupid = $groupId;
            $log->text = $spam ? 'Autoremoved spammer' : ($ban ? 'via ban' : NULL);
            Logger::info("TN-SYNC-TRACE [WRITE] table=logs op=insert set=type=Group,subtype=Left,user={$this->id},byuser=" . ($byUserId ?? 'NULL') . ",groupid={$groupId},text=" . ($spam ? 'Autoremoved spammer' : ($ban ? 'via ban' : 'NULL')));
            // $log->save();  // TRACE: commented out for port testing
        }

        return $deleted > 0 || $ban;
    }

    /**
     * Return the group IDs where this user is a Moderator or Owner.
     *
     * Ported from iznik-server/include/user/User.php::getModeratorships().
     *
     * @param bool $activeOnly When TRUE, only include groups where the user is actively modding
     *                         (i.e. their membership settings have active=1 or showmessages=1).
     * @return array<int> Array of group IDs
     */
    public function getModeratorships(bool $activeOnly = false): array
    {
        $ret = [];

        foreach ($this->memberships()->get() as $membership) {
            if ($membership->role === self::ROLE_OWNER || $membership->role === self::ROLE_MODERATOR) {
                if (!$activeOnly || $this->activeModForGroup($membership->groupid)) {
                    $ret[] = $membership->groupid;
                }
            }
        }

        return $ret;
    }

    /**
     * Check whether this user is actively modding a given group.
     *
     * Uses the 'active' flag in membership settings if present; falls back to the legacy
     * 'showmessages' flag; defaults to TRUE (active) if neither is set.
     *
     * Ported from iznik-server/include/user/User.php::activeModForGroup().
     *
     * @param int $groupId
     * @return bool
     */
    public function activeModForGroup(int $groupId): bool
    {
        $settings = $this->getGroupSettings($groupId);

        if (array_key_exists('active', $settings)) {
            return (bool) $settings['active'];
        }

        // Legacy fallback: showmessages=0 means inactive; absent or 1 means active.
        return !array_key_exists('showmessages', $settings) || (bool) $settings['showmessages'];
    }

    /**
     * Check whether this user participates in wider chat review.
     *
     * Returns TRUE if the user is an active moderator on at least one group that has
     * the 'widerchatreview' group setting enabled.
     *
     * Ported from iznik-server/include/user/User.php::widerReview().
     *
     * @return bool
     */
    public function widerReview(): bool
    {
        foreach ($this->getModeratorships() as $groupId) {
            if ($this->activeModForGroup($groupId)) {
                $group = Group::find($groupId);

                if ($group && $group->getSetting('widerchatreview', false)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the user's per-group membership settings.
     *
     * Ported from iznik-server/include/user/User.php::getGroupSettings().
     *
     * @param int $groupId
     * @param int|null $configId Optional mod config ID (used for mod config lookup)
     * @return array
     */
    public function getGroupSettings(int $groupId, ?int $configId = NULL): array
    {
        $defaults = [
            'active' => 1,
            'showchat' => 1,
            'pushnotify' => 1,
            'eventsallowed' => 1,
            'volunteeringallowed' => 1,
        ];

        $membership = $this->memberships()->where('groupid', $groupId)->first();

        if (!$membership) {
            return $defaults;
        }

        $settings = $membership->settings ?? [];

        if (!empty($settings) && !$configId && in_array($membership->role, [self::ROLE_OWNER, self::ROLE_MODERATOR])) {
            $settings['configid'] = $membership->configid ?? ModConfig::getForGroup($this->id, $groupId);
        }

        // Base active setting on legacy showmessages setting if not present.
        $settings['active'] = array_key_exists('active', $settings)
            ? $settings['active']
            : (!array_key_exists('showmessages', $settings) || $settings['showmessages']);
        $settings['active'] = $settings['active'] ? 1 : 0;

        // Merge defaults for missing keys.
        foreach ($defaults as $key => $val) {
            if (!array_key_exists($key, $settings)) {
                $settings[$key] = $val;
            }
        }

        $settings['emailfrequency'] = $membership->emailfrequency;
        $settings['eventsallowed'] = $membership->eventsallowed;
        $settings['volunteeringallowed'] = $membership->volunteeringallowed ?? 1;

        return $settings;
    }

    /**
     * Get this user's group memberships with group details.
     *
     * Returns an array of group data enriched with membership info (role, collection,
     * configid, mysettings). This is distinct from the memberships() Eloquent relationship
     * which returns Membership models.
     *
     * Ported from iznik-server/include/user/User.php::getMemberships().
     *
     * @param bool $modOnly Only return groups where user is Moderator or Owner
     * @param string|null $groupType Filter by group type (e.g. Group::TYPE_FREEGLE)
     * @param bool $getWork Include work counts for moderator groups
     * @param bool $isModTools Whether this is a ModTools context (affects publish filtering)
     * @return array Array of group data with membership details
     */
    public function getMembershipList(bool $modOnly = FALSE, ?string $groupType = NULL, bool $getWork = FALSE, bool $isModTools = FALSE): array
    {
        $query = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $this->id);

        if ($modOnly) {
            $query->whereIn('memberships.role', [self::ROLE_MODERATOR, self::ROLE_OWNER]);
        }

        if ($groupType) {
            $query->where('groups.type', $groupType);
        }

        if (!$isModTools) {
            $query->where('groups.publish', 1);
        }

        $rows = $query->select([
            'groups.type',
            'memberships.heldby',
            'memberships.settings AS membership_settings',
            'memberships.collection',
            'memberships.emailfrequency',
            'memberships.eventsallowed',
            'memberships.volunteeringallowed',
            'memberships.groupid',
            'memberships.role',
            'memberships.configid',
            'memberships.ourPostingStatus',
            DB::raw("CASE WHEN groups.namefull IS NOT NULL THEN groups.namefull ELSE groups.nameshort END AS namedisplay"),
        ])
            ->orderByRaw('LOWER(namedisplay) ASC')
            ->get();

        $ret = [];
        $getWorkIds = [];
        $groupSettings = [];

        // Eager-load Group models for all membership group IDs.
        $groupIdList = $rows->pluck('groupid')->filter()->all();
        $groups = Group::whereIn('id', $groupIdList)->get()->keyBy('id');

        foreach ($rows as $row) {
            $group = $groups->get($row->groupid);

            if (!$group) {
                continue;
            }

            $one = $group->getPublic();

            $one['role'] = $row->role;
            $one['collection'] = $row->collection;
            $amod = ($one['role'] === self::ROLE_MODERATOR || $one['role'] === self::ROLE_OWNER);
            $one['configid'] = $row->configid;

            if ($amod && !$one['configid']) {
                # Get a config using defaults.
                $one['configid'] = ModConfig::getForGroup($this->id, $row->groupid);
            }

            $one['mysettings'] = $this->getGroupSettings($row->groupid, $row->configid);

            $one['mysettings']['emailfrequency'] = ($row->type === Group::TYPE_FREEGLE && $this->sendOurMails(false, false))
                ? ($one['mysettings']['emailfrequency'] ?? 24)
                : 0;

            $groupSettings[$row->groupid] = $one['mysettings'];

            if ($getWork && $amod) {
                $getWorkIds[] = $row->groupid;
            }

            $ret[] = $one;
        }

        if ($getWork && !empty($getWorkIds)) {
            $workcounts = Group::getWorkCounts($this, $groupSettings, $getWorkIds);
            foreach ($ret as &$one) {
                $gid = $one['id'];
                if (isset($workcounts[$gid])) {
                    $one = array_merge($one, $workcounts[$gid]);
                }
            }
            unset($one);
        }

        return $ret;
    }
}
