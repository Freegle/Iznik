<?php

namespace App\Models;

use App\Support\NameSanitiser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    protected $table = 'users';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'deleted' => 'datetime',
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
     * Rewrites misleading brand/authority names for non-mods on display
     * (Discourse #9587) — storage is untouched.
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

        $name = self::removeTNGroup($name);

        return NameSanitiser::sanitize($name, $this->isNameExempt());
    }

    /**
     * A user is exempt from the display-name sanitiser when they are a
     * platform mod/support/admin or Owner/Moderator on any group.
     */
    public function isNameExempt(): bool
    {
        if (in_array($this->systemrole, ['Moderator', 'Support', 'Admin'], TRUE)) {
            return TRUE;
        }
        return $this->isModerator();
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
     * Check if this user is a LoveJunk proxy account.
     * LJ users have the ljuserid column set.
     */
    public function isLJ(): bool
    {
        return ! empty($this->attributes['ljuserid']);
    }

    /**
     * Check if a notification type is enabled for this user.
     *
     * @param string $type The notification type (email, emailmine, push)
     * @param int|null $groupId Optional group ID for mod-specific checks
     */
    public function notifsOn(string $type, ?int $groupId = NULL): bool
    {
        // emailmine is never honoured for TN or LJ proxy users. Their "real"
        // inbox is the partner's proxy address, so a self-copy is delivered
        // back to themselves and looks like someone else is sending their
        // own words at them.
        if ($type === self::NOTIFS_EMAIL_MINE && ($this->isTN() || $this->isLJ())) {
            return FALSE;
        }

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
        $profileImage = \DB::table('users_images')
            ->where('userid', $this->id)
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
        $login = \DB::table('users_logins')
            ->where('userid', $this->id)
            ->where('type', self::LOGIN_LINK)
            ->first(['credentials']);

        if ($login && $login->credentials) {
            return $login->credentials;
        }

        // Create a new key.
        $key = bin2hex(random_bytes(16));

        \DB::table('users_logins')->insert([
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
}
