<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Applies a targeted email opt-out for a user.
 *
 * Every mailable declares which category of email it belongs to (see
 * MjmlMailable::unsubscribeType()). Both arms of the List-Unsubscribe header carry
 * that category, so "Unsubscribe" in a mail client turns off the kind of email the
 * member actually received rather than deleting their account - which is what the
 * previous one-click handler did.
 *
 * The same category map is implemented in the Go API (user/unsubscribe.go) for the
 * HTTPS one-click arm. apiv2 and batch-prod run on different hosts and batch-prod is
 * outside the compose network, so neither can call the other; TYPES is asserted
 * against the Go list by test so the two cannot drift apart silently.
 */
class UnsubscribeService
{
    public const TYPE_DIGEST = 'digest';

    public const TYPE_EVENTS = 'events';

    public const TYPE_VOLUNTEERING = 'volunteering';

    public const TYPE_NEWSLETTER = 'newsletter';

    public const TYPE_RELEVANT = 'relevant';

    public const TYPE_CHAT = 'chat';

    public const TYPE_NOTIFICATIONS = 'notifications';

    public const TYPE_ENGAGEMENT = 'engagement';

    /** Turns off every category above. */
    public const TYPE_ALL = 'all';

    /**
     * Everything except chat - i.e. stop the bulk mail but keep hearing when someone
     * replies to your posts.
     *
     * This is what most people mean by "stop emailing me". Turning off chat as well means
     * someone offers a sofa, a neighbour replies, and they never find out - so it is worth
     * having as a distinct choice rather than folding it into TYPE_ALL. TYPE_ALL still
     * means all, because one-clicking Unsubscribe on a chat notification has to stop chat
     * notifications.
     */
    public const TYPE_ALL_EXCEPT_REPLIES = 'allexceptreplies';

    public const TYPES = [
        self::TYPE_DIGEST,
        self::TYPE_EVENTS,
        self::TYPE_VOLUNTEERING,
        self::TYPE_NEWSLETTER,
        self::TYPE_RELEVANT,
        self::TYPE_CHAT,
        self::TYPE_NOTIFICATIONS,
        self::TYPE_ENGAGEMENT,
        self::TYPE_ALL,
        self::TYPE_ALL_EXCEPT_REPLIES,
    ];

    /**
     * Member-facing description of what each category covers, used in the
     * acknowledgement email so people can see what they turned off and what is
     * still switched on.
     *
     * @var array<string,string>
     */
    public const DESCRIPTIONS = [
        self::TYPE_DIGEST => 'emails about new posts in your communities',
        self::TYPE_EVENTS => 'emails about community events',
        self::TYPE_VOLUNTEERING => 'emails about volunteer opportunities',
        self::TYPE_NEWSLETTER => 'newsletters and community news',
        self::TYPE_RELEVANT => 'emails suggesting posts that match what you are looking for',
        self::TYPE_CHAT => 'emails telling you about new chat messages',
        self::TYPE_NOTIFICATIONS => 'emails about replies and notifications',
        self::TYPE_ENGAGEMENT => 'occasional emails asking how we are doing',
        self::TYPE_ALL => 'all our non-essential emails',
        self::TYPE_ALL_EXCEPT_REPLIES => 'everything except replies to your posts',
    ];

    public static function isValidType(?string $type): bool
    {
        return $type !== null && in_array($type, self::TYPES, true);
    }

    /**
     * The categories that name one kind of email, i.e. everything except the two
     * combinations (TYPE_ALL and TYPE_ALL_EXCEPT_REPLIES) that expand into them.
     *
     * @return string[]
     */
    public static function singleCategories(): array
    {
        return array_values(array_diff(self::TYPES, [self::TYPE_ALL, self::TYPE_ALL_EXCEPT_REPLIES]));
    }

    /**
     * Turn off one category of email for a user.
     *
     * @return string[] The categories actually switched off, in TYPES order. Empty when
     *                  everything in scope was already off, which the caller uses to keep
     *                  the acknowledgement honest ("these were already off").
     */
    public function apply(User $user, string $type): array
    {
        if (! self::isValidType($type)) {
            throw new \InvalidArgumentException("Unknown unsubscribe type: $type");
        }

        $wanted = match ($type) {
            self::TYPE_ALL => self::singleCategories(),
            self::TYPE_ALL_EXCEPT_REPLIES => array_values(array_diff(self::singleCategories(), [self::TYPE_CHAT])),
            default => [$type],
        };

        $changed = [];

        foreach ($wanted as $one) {
            if ($this->applyOne($user, $one)) {
                $changed[] = $one;
            }
        }

        Log::info('Applied unsubscribe', [
            'user_id' => $user->id,
            'type' => $type,
            'changed' => $changed,
        ]);

        return $changed;
    }

    /**
     * @return bool True if this actually changed something.
     */
    private function applyOne(User $user, string $type): bool
    {
        return match ($type) {
            self::TYPE_DIGEST => $this->membershipsOff($user, 'emailfrequency', 0),
            self::TYPE_EVENTS => $this->membershipsOff($user, 'eventsallowed', 0),
            self::TYPE_VOLUNTEERING => $this->membershipsOff($user, 'volunteeringallowed', 0),
            self::TYPE_NEWSLETTER => $this->userColumnOff($user, 'newslettersallowed'),
            self::TYPE_RELEVANT => $this->userColumnOff($user, 'relevantallowed'),
            self::TYPE_CHAT => $this->settingOff($user, ['notifications', 'email']),
            self::TYPE_NOTIFICATIONS => $this->settingOff($user, ['notificationmails']),
            self::TYPE_ENGAGEMENT => $this->settingOff($user, ['engagement']),
            default => false,
        };
    }

    /**
     * Digests, events and volunteering are per-membership settings. An unsubscribe from
     * a mail that spans communities (the unified digest does) has to cover all of them,
     * otherwise the member keeps getting the same email from their other groups and
     * reasonably concludes unsubscribe is broken.
     */
    private function membershipsOff(User $user, string $column, int $value): bool
    {
        $affected = Membership::where('userid', $user->id)
            ->where($column, '!=', $value)
            ->update([$column => $value]);

        return $affected > 0;
    }

    private function userColumnOff(User $user, string $column): bool
    {
        if ((int) ($user->$column ?? 1) === 0) {
            return false;
        }

        $user->$column = 0;
        $user->save();

        return true;
    }

    /**
     * Some opt-outs live in the users.settings JSON rather than a column. Read-modify-write
     * the whole array so the Eloquent 'array' cast persists it, and leave every other
     * setting untouched.
     *
     * @param  string[]  $path  Key path within settings, e.g. ['notifications','email'].
     */
    private function settingOff(User $user, array $path): bool
    {
        $settings = $user->settings ?? [];

        // Absent means "on" for all of these, so an absent key still needs writing.
        $cursor = $settings;
        foreach ($path as $key) {
            if (! is_array($cursor) || ! array_key_exists($key, $cursor)) {
                $cursor = null;
                break;
            }
            $cursor = $cursor[$key];
        }

        if ($cursor === false) {
            return false;
        }

        $settings = $this->setPath($settings, $path, false);
        $user->settings = $settings;
        $user->save();

        return true;
    }

    /**
     * @param  array<mixed>  $target
     * @param  string[]  $path
     * @return array<mixed>
     */
    private function setPath(array $target, array $path, mixed $value): array
    {
        $key = array_shift($path);

        if (empty($path)) {
            $target[$key] = $value;

            return $target;
        }

        $child = $target[$key] ?? [];
        $target[$key] = $this->setPath(is_array($child) ? $child : [], $path, $value);

        return $target;
    }

    /**
     * The categories still switched on for this user, so the acknowledgement can say what
     * they will still hear from us about instead of implying we have gone silent.
     *
     * @return string[]
     */
    public function stillOn(User $user): array
    {
        $user->refresh();
        $settings = $user->settings ?? [];
        $notifications = $settings['notifications'] ?? [];

        $on = [];

        if (Membership::where('userid', $user->id)->where('emailfrequency', '!=', 0)->exists()) {
            $on[] = self::TYPE_DIGEST;
        }
        if (Membership::where('userid', $user->id)->where('eventsallowed', '!=', 0)->exists()) {
            $on[] = self::TYPE_EVENTS;
        }
        if (Membership::where('userid', $user->id)->where('volunteeringallowed', '!=', 0)->exists()) {
            $on[] = self::TYPE_VOLUNTEERING;
        }
        if ((int) ($user->newslettersallowed ?? 1) !== 0) {
            $on[] = self::TYPE_NEWSLETTER;
        }
        if ((int) ($user->relevantallowed ?? 1) !== 0) {
            $on[] = self::TYPE_RELEVANT;
        }
        if (($notifications['email'] ?? true) !== false) {
            $on[] = self::TYPE_CHAT;
        }
        if (($settings['notificationmails'] ?? true) !== false) {
            $on[] = self::TYPE_NOTIFICATIONS;
        }
        if (($settings['engagement'] ?? true) !== false) {
            $on[] = self::TYPE_ENGAGEMENT;
        }

        return $on;
    }

    public static function describe(string $type): string
    {
        return self::DESCRIPTIONS[$type] ?? 'these emails';
    }
}
