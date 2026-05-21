<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use App\Models\ChatMessage;
use OwenIt\Auditing\Contracts\Auditable;

class Group extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    public const TYPE_FREEGLE = 'Freegle';
    public const TYPE_REUSE = 'Reuse';
    public const TYPE_OTHER = 'Other';

    public const DEFAULT_SETTINGS = [
        'showchat' => 1,
        'communityevents' => 1,
        'volunteering' => 1,
        'stories' => 1,
        'includearea' => 1,
        'includepc' => 1,
        'moderated' => 0,
        'allowedits' => [
            'moderated' => 1,
            'group' => 1,
        ],
        'autoapprove' => [
            'members' => 0,
            'messages' => 0,
        ],
        'duplicates' => [
            'check' => 1,
            'offer' => 14,
            'taken' => 14,
            'wanted' => 14,
            'received' => 14,
        ],
        'spammers' => [
            'chatreview' => 1,
            'messagereview' => 1,
        ],
        'joiners' => [
            'check' => 1,
            'threshold' => 5,
        ],
        'keywords' => [
            'OFFER' => 'OFFER',
            'TAKEN' => 'TAKEN',
            'WANTED' => 'WANTED',
            'RECEIVED' => 'RECEIVED',
        ],
        'reposts' => [
            'offer' => 3,
            'wanted' => 7,
            'max' => 5,
            'chaseups' => 5,
        ],
        'relevant' => 1,
        'newsfeed' => 1,
        'newsletter' => 1,
        'businesscards' => 1,
        'autoadmins' => 1,
        'mentored' => 0,
        'nearbygroups' => 5,
        'showjoin' => 0,
        'engagement' => 1,
    ];

    protected $table = 'groups';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate polyindex geometry from lat/lng when creating.
        static::creating(function (Group $group) {
            if (!empty($group->lat) && !empty($group->lng) && empty($group->polyindex)) {
                $srid = config('freegle.srid');
                $group->polyindex = \DB::raw("ST_GeomFromText('POINT({$group->lng} {$group->lat})', {$srid})");
            }
        });
    }

    protected $casts = [
        'settings' => 'array',
        'microvolunteeringoptions' => 'array',
        'rules' => 'array',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'founded' => 'date',
        'onhere' => 'boolean',
        'publish' => 'boolean',
        'listable' => 'boolean',
        'onmap' => 'boolean',
        'mentored' => 'boolean',
        'seekingmods' => 'boolean',
        'privategroup' => 'boolean',
        'microvolunteering' => 'boolean',
        'onlovejunk' => 'boolean',
    ];

    /**
     * Get group's memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'groupid');
    }

    /**
     * Get group's messages via message_groups pivot.
     */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'messages_groups', 'groupid', 'msgid')
            ->withPivot(['collection', 'arrival', 'approved_by', 'deleted']);
    }

    /**
     * Get group's digest records.
     */
    public function digests(): HasMany
    {
        return $this->hasMany(GroupDigest::class, 'groupid');
    }

    /**
     * Scope to only Freegle groups.
     */
    public function scopeFreegle(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_FREEGLE);
    }

    /**
     * Scope to only groups that are on the platform.
     */
    public function scopeOnHere(Builder $query): Builder
    {
        return $query->where('onhere', 1);
    }

    /**
     * Scope to only published groups.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish', 1);
    }

    /**
     * Scope to active Freegle groups (excludes closed groups).
     */
    public function scopeActiveFreegle(Builder $query): Builder
    {
        return $query->freegle()->onHere()->published()->notClosed();
    }

    /**
     * Scope to exclude closed groups.
     *
     * Note: Must check for both boolean false AND integer 0, as some groups
     * have "closed": 0 (integer) rather than "closed": false (boolean).
     * In MySQL JSON comparisons, 0 != false.
     */
    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('settings')
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = false")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");
        });
    }

    /**
     * Check if the group is closed.
     */
    public function isClosed(): bool
    {
        $settings = $this->settings ?? [];
        return !empty($settings['closed']);
    }

    /**
     * Get a setting value.
     */
    public function getSetting(string $key, mixed $default = NULL): mixed
    {
        $settings = $this->settings ?? [];
        return $settings[$key] ?? $default;
    }

    /**
     * Get approved members.
     */
    public function approvedMembers(): HasMany
    {
        return $this->memberships()->where('collection', 'Approved');
    }

    /**
     * Get moderators.
     */
    public function moderators(): HasMany
    {
        return $this->memberships()->whereIn('role', ['Moderator', 'Owner']);
    }

    /**
     * Get the automated sender address for this group.
     *
     * Returns contactmail if set, otherwise {nameshort}-auto@{group_domain}.
     */
    public function getAutoEmail(): string
    {
        if (!empty($this->contactmail)) {
            return $this->contactmail;
        }

        return $this->nameshort . '-auto@' . config('freegle.mail.group_domain');
    }

    /**
     * Get the moderators contact address for this group.
     *
     * Returns contactmail if set, otherwise {nameshort}-volunteers@{group_domain}.
     */
    public function getModsEmail(): string
    {
        if (!empty($this->contactmail)) {
            return $this->contactmail;
        }

        return $this->nameshort . '-volunteers@' . config('freegle.mail.group_domain');
    }

    /**
     * Get the group's posting address.
     */
    public function getGroupEmail(): string
    {
        return $this->nameshort . '@' . config('freegle.mail.group_domain');
    }

    /**
     * Get work counts for a set of groups.
     *
     * Ported from iznik-server Group::getWorkCounts().
     *
     * @param  User   $me          The moderator user requesting work counts.
     * @param  array  $mysettings  Per-group settings indexed by groupid; each entry may have an 'active' boolean.
     * @param  array  $groupids    Group IDs to get counts for.
     * @return array  Work counts indexed by groupid.
     */
    public static function getWorkCounts(User $me, array $mysettings, array $groupids): array
    {
        $ret = [];

        if (empty($groupids)) {
            return $ret;
        }

        $earliestmsg = now()->startOfDay()->subDays(31);
        $eventsqltime = now();

        # Exclude messages routed to system, for which there must be a good reason.
        $pendingspamcounts = MessageGroup::query()
            ->select([
                'messages_groups.groupid',
                DB::raw('COUNT(*) AS count'),
                'messages_groups.collection',
                DB::raw('messages_groups.heldby IS NOT NULL AS held'),
            ])
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->whereIn('messages_groups.groupid', $groupids)
            ->where('messages_groups.collection', MessageGroup::COLLECTION_PENDING)
            ->where('messages_groups.deleted', 0)
            ->whereNull('messages.deleted')
            ->whereNotNull('messages.fromuser')
            ->where('messages_groups.arrival', '>=', $earliestmsg)
            ->where(function ($q) {
                $q->whereNull('messages.lastroute')
                    ->orWhere('messages.lastroute', '!=', 'ToSystem');
            })
            ->groupBy('messages_groups.groupid', 'messages_groups.collection', 'held')
            ->get();

        # No need to check spam_users as those will be auto-removed by the check_spammers job (in earlier times
        # this wasn't the case for all groups).
        $spammembercounts = Membership::query()
            ->select([
                'groupid',
                DB::raw('COUNT(*) AS count'),
                DB::raw('heldby IS NOT NULL AS held'),
            ])
            ->whereNotNull('reviewrequestedat')
            ->where(function ($q) {
                $q->whereNull('reviewedat')
                    ->orWhereRaw('DATE(reviewedat) < DATE_SUB(NOW(), INTERVAL 31 DAY)');
            })
            ->whereIn('groupid', $groupids)
            ->groupBy('groupid', 'held')
            ->get();

        // Pending community event counts.
        $pendingeventcounts = DB::table('communityevents')
            ->select([
                'communityevents_groups.groupid',
                DB::raw('COUNT(DISTINCT communityevents.id) AS count'),
            ])
            ->join('communityevents_dates', 'communityevents_dates.eventid', '=', 'communityevents.id')
            ->join('communityevents_groups', 'communityevents.id', '=', 'communityevents_groups.eventid')
            ->join('groups', 'groups.id', '=', 'communityevents_groups.groupid')
            ->whereIn('communityevents_groups.groupid', $groupids)
            ->where(function ($q) {
                $q->whereNull('groups.settings')
                    ->orWhereRaw("JSON_EXTRACT(groups.settings, '$.communityevents') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(groups.settings, '$.communityevents') = 1");
            })
            ->where('communityevents.pending', 1)
            ->where('communityevents.deleted', 0)
            ->where('communityevents_dates.end', '>=', $eventsqltime)
            ->groupBy('communityevents_groups.groupid')
            ->get();

        // Pending volunteering counts.
        $pendingvolunteercounts = DB::table('volunteering')
            ->select([
                'volunteering_groups.groupid',
                DB::raw('COUNT(DISTINCT volunteering.id) AS count'),
            ])
            ->leftJoin('volunteering_dates', 'volunteering_dates.volunteeringid', '=', 'volunteering.id')
            ->join('volunteering_groups', 'volunteering.id', '=', 'volunteering_groups.volunteeringid')
            ->join('groups', 'groups.id', '=', 'volunteering_groups.groupid')
            ->whereIn('volunteering_groups.groupid', $groupids)
            ->where('volunteering.pending', 1)
            ->where('volunteering.deleted', 0)
            ->where('volunteering.expired', 0)
            ->where(function ($q) {
                $q->whereNull('groups.settings')
                    ->orWhereRaw("JSON_EXTRACT(groups.settings, '$.volunteering') IS NULL")
                    ->orWhereRaw("JSON_EXTRACT(groups.settings, '$.volunteering') = 1");
            })
            ->where(function ($q) use ($eventsqltime) {
                $q->whereNull('volunteering.applyby')
                    ->orWhere('volunteering.applyby', '>=', $eventsqltime);
            })
            ->where(function ($q) use ($eventsqltime) {
                $q->whereNull('volunteering_dates.end')
                    ->orWhere('volunteering_dates.end', '>=', $eventsqltime);
            })
            ->groupBy('volunteering_groups.groupid')
            ->get();

        // Pending admin counts.
        $pendingadmins = DB::table('admins')
            ->select([
                'groupid',
                DB::raw('COUNT(DISTINCT admins.id) AS count'),
            ])
            ->whereIn('groupid', $groupids)
            ->whereNull('complete')
            ->where('pending', 1)
            ->whereNull('heldby')
            ->where('created', '>=', $earliestmsg)
            ->groupBy('groupid')
            ->get();

        // Related members (possible duplicate accounts, not yet notified).
        $sub1 = DB::table('users_related')
            ->select([
                'users_related.user1',
                'memberships.groupid',
                DB::raw('(SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount'),
            ])
            ->join('memberships', 'users_related.user1', '=', 'memberships.userid')
            ->join('users as u1', function ($join) {
                $join->on('users_related.user1', '=', 'u1.id')
                    ->whereNull('u1.deleted')
                    ->where('u1.systemrole', 'User');
            })
            ->join('users as u2', function ($join) {
                $join->on('users_related.user2', '=', 'u2.id')
                    ->whereNull('u2.deleted')
                    ->where('u2.systemrole', 'User');
            })
            ->whereColumn('users_related.user1', '<', 'users_related.user2')
            ->where('users_related.notified', 0)
            ->whereIn('memberships.groupid', $groupids)
            ->havingRaw('logincount > 0');

        $sub2 = DB::table('users_related')
            ->select([
                'users_related.user1',
                'memberships.groupid',
                DB::raw('(SELECT COUNT(*) FROM users_logins WHERE userid = memberships.userid) AS logincount'),
            ])
            ->join('memberships', 'users_related.user2', '=', 'memberships.userid')
            ->join('users as u3', function ($join) {
                $join->on('users_related.user2', '=', 'u3.id')
                    ->whereNull('u3.deleted')
                    ->where('u3.systemrole', 'User');
            })
            ->join('users as u4', function ($join) {
                $join->on('users_related.user1', '=', 'u4.id')
                    ->whereNull('u4.deleted')
                    ->where('u4.systemrole', 'User');
            })
            ->whereColumn('users_related.user1', '<', 'users_related.user2')
            ->where('users_related.notified', 0)
            ->whereIn('memberships.groupid', $groupids)
            ->havingRaw('logincount > 0');

        $unionQuery = $sub1->union($sub2);
        $relatedmembers = DB::query()
            ->fromSub($unionQuery, 't')
            ->selectRaw('COUNT(*) AS count, groupid')
            ->groupBy('groupid')
            ->get();

        # We only want to show edit reviews upto 7 days old - after that assume they're ok.
        $mysqltime7 = now()->startOfDay()->subDays(7);
        $editreviewcounts = DB::table('messages_edits')
            ->select([
                'messages_groups.groupid',
                DB::raw('COUNT(DISTINCT messages_edits.msgid) AS count'),
            ])
            ->join('messages_groups', 'messages_edits.msgid', '=', 'messages_groups.msgid')
            ->where('messages_edits.timestamp', '>', $mysqltime7)
            ->where('messages_edits.reviewrequired', 1)
            ->whereIn('messages_groups.groupid', $groupids)
            ->where('messages_groups.deleted', 0)
            ->groupBy('messages_groups.groupid')
            ->get();

        # We only want to show happiness upto 31 days old - after that just let it slide.  We're only interested
        # in ones with interesting comments.
        #
        # This code matches the feedback code on the client.
        $happinesscounts = MessageOutcome::query()
            ->select([
                'messages_groups.groupid',
                DB::raw('COUNT(DISTINCT messages_groups.msgid) AS count'),
            ])
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_outcomes.msgid')
            ->join('messages', 'messages.id', '=', 'messages_outcomes.msgid')
            ->where('messages_outcomes.timestamp', '>', $earliestmsg)
            ->where('messages_groups.arrival', '>', $earliestmsg)
            ->whereIn('messages_groups.groupid', $groupids)
            ->where('messages_outcomes.reviewed', 0)
            ->whereRaw(self::getHappinessFilter())
            ->groupBy('messages_groups.groupid')
            ->get();

        $c = new ChatMessage();
        $reviewcounts = $c->getReviewCountByGroup($me, FALSE);
        $reviewcountsother = $c->getReviewCountByGroup($me, TRUE);

        # We might be returned counts for groups we were not expecting, because we are using the wider chat
        # review function.  So add any groupids from $reviewcountsother into $groupids so that we process
        # the results below.
        foreach ($reviewcountsother as $count) {
            if (!in_array($count['groupid'], $groupids)) {
                $groupids[] = $count['groupid'];
            }
        }

        foreach ($groupids as $groupid) {
            # Depending on our group settings we might not want to show this work as primary; "other" work is displayed
            # less prominently in the client.
            #
            # If we have the active flag use that; otherwise assume that the legacy showmessages flag tells us.  Default
            # to active.
            # TODO Retire showmessages entirely and remove from user configs.
            $active = $mysettings[$groupid]['active'] ?? FALSE;

            $thisone = [
                'pending'             => 0,
                'pendingother'        => 0,
                'spam'                => 0,
                'pendingmembers'      => 0,
                'pendingmembersother' => 0,
                'pendingevents'       => 0,
                'pendingvolunteering' => 0,
                'spammembers'         => 0,
                'spammembersother'    => 0,
                'editreview'          => 0,
                'pendingadmins'       => 0,
                'happiness'           => 0,
                'relatedmembers'      => 0,
                'chatreview'          => 0,
                'chatreviewother'     => 0,
            ];

            if ($active) {
                foreach ($pendingspamcounts as $count) {
                    if ($count->groupid == $groupid) {
                        if ($count->collection == MessageGroup::COLLECTION_PENDING) {
                            if ($count->held) {
                                $thisone['pendingother'] = $count->count;
                            } else {
                                $thisone['pending'] = $count->count;
                            }
                        } else {
                            $thisone['spam'] = $count->count;
                        }
                    }
                }

                foreach ($spammembercounts as $count) {
                    if ($count->groupid == $groupid) {
                        if ($count->held) {
                            $thisone['spammembersother'] = $count->count;
                        } else {
                            $thisone['spammembers'] = $count->count;
                        }
                    }
                }

                foreach ($pendingeventcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingevents'] = $count->count;
                    }
                }

                foreach ($pendingvolunteercounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingvolunteering'] = $count->count;
                    }
                }

                foreach ($editreviewcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['editreview'] = $count->count;
                    }
                }

                foreach ($pendingadmins as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingadmins'] = $count->count;
                    }
                }

                foreach ($happinesscounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['happiness'] = $count->count;
                    }
                }

                foreach ($relatedmembers as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['relatedmembers'] = $count->count;
                    }
                }

                foreach ($reviewcounts as $count) {
                    if ($count['groupid'] == $groupid) {
                        $thisone['chatreview'] = $count['count'];
                    }
                }

                foreach ($reviewcountsother as $count) {
                    if ($count['groupid'] == $groupid) {
                        $thisone['chatreviewother'] = $count['count'];
                    }
                }
            } else {
                foreach ($pendingspamcounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['pendingother'] = $count->count;
                    }
                }

                foreach ($spammembercounts as $count) {
                    if ($count->groupid == $groupid) {
                        $thisone['spammembersother'] = $count->count;
                    }
                }

                foreach ($reviewcounts as $count) {
                    if ($count['groupid'] == $groupid) {
                        $thisone['chatreviewother'] += $count['count'];
                    }
                }

                foreach ($reviewcountsother as $count) {
                    if ($count['groupid'] == $groupid) {
                        $thisone['chatreviewother'] += $count['count'];
                    }
                }
            }

            $ret[$groupid] = $thisone;
        }

        return $ret;
    }

    /**
     * SQL fragment to filter out auto-generated/boilerplate happiness comments.
     *
     * Ported from iznik-server Group::getHappinessFilter().
     * Note that this does NOT include a leading " AND" since it's intended for use inside a whereRaw() call.
     */
    private static function getHappinessFilter(): string
    {
        return "messages_outcomes.comments IS NOT NULL
              AND messages_outcomes.comments != 'Sorry, this is no longer available.'
              AND messages_outcomes.comments != 'Thanks, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, I\\'m no longer looking for this.'
              AND messages_outcomes.comments != 'Sorry, this has now been taken.'
              AND messages_outcomes.comments != 'Thanks for the interest, but this has now been taken.'
              AND messages_outcomes.comments != 'Thanks, these have now been taken.'
              AND messages_outcomes.comments != 'Thanks, this has now been received.'
              AND messages_outcomes.comments != 'Withdrawn on user unsubscribe'
              AND messages_outcomes.comments != 'Auto-Expired'";
    }

    // Fields exposed by getPublic() - mirrors iznik-server Group::$publicatts.
    private const PUBLIC_ATTS = [
        'id',
        'nameshort',
        'namefull',
        'nameabbr',
        'namedisplay',
        'settings',
        'rules',
        'type',
        'region',
        'logo',
        'publish',
        'onhere',
        'ontn',
        'membercount',
        'modcount',
        'lat',
        'lng',
        'profile',
        'cover',
        'onmap',
        'tagline',
        'legacyid',
        'external',
        'welcomemail',
        'description',
        'contactmail',
        'fundingtarget',
        'affiliationconfirmed',
        'affiliationconfirmedby',
        'mentored',
        'privategroup',
        'defaultlocation',
        'moderationstatus',
        'maxagetoshow',
        'nearbygroups',
        'microvolunteering',
        'microvolunteeringoptions',
        'autofunctionoverride',
        'overridemoderation',
        'precovidmoderated',
        'onlovejunk',
    ];

    /**
     * Get the public representation of this group.
     *
     * Ported from iznik-server Group::getPublic().
     *
     * @param  bool  $summary  If true, omits settings, description, and welcomemail.
     */
    public function getPublic(bool $summary = FALSE): array
    {
        $atts = $this->only(self::PUBLIC_ATTS);

        // Email addresses.
        $atts['modsemail'] = $this->getModsEmail();
        $atts['autoemail'] = $this->getAutoEmail();
        $atts['groupemail'] = $this->getGroupEmail();

        // Derived display name.
        $atts['namedisplay'] = !empty($atts['namefull']) ? $atts['namefull'] : $atts['nameshort'];

        // Merge settings with defaults.
        $settings = $atts['settings'] ?? [];
        $atts['settings'] = array_replace_recursive(self::DEFAULT_SETTINGS, $settings ?: []);

        // ISO date fields.
        $atts['founded'] = $this->founded ? Carbon::parse($this->founded)->toIso8601String() : NULL;

        $atts['affiliationconfirmed'] = !empty($atts['affiliationconfirmed'])
            ? Carbon::parse($atts['affiliationconfirmed'])->toIso8601String()
            : NULL;

        # Images.  We pass those ids in to get the paths.  This removes the DB operations for constructing the
        # Attachment, which is valuable for people on many groups.
        $img = new GroupAttachment();
        $atts['profile'] = $atts['profile'] ? $img->getPath(false, (int) $atts['profile']) : NULL;
        $atts['cover']   = $atts['cover']   ? $img->getPath(false, (int) $atts['cover'])   : NULL;

        // Group URL.
        $userSite = config('freegle.sites.user');
        $atts['url'] = $this->onhere
            ? ($userSite . '/explore/' . $atts['nameshort'])
            : ('https://groups.yahoo.com/neo/groups/' . $atts['nameshort'] . '/info');

        if ($summary) {
            unset($atts['settings'], $atts['description'], $atts['welcomemail']);
        } else {
            if (!empty($atts['defaultlocation'])) {
                $location = Location::find($atts['defaultlocation']);
                $atts['defaultlocation'] = $location ? $location->getPublic() : NULL;
            }
        }

        // Microvolunteering options - already cast to array by Eloquent; apply defaults if absent.
        $atts['microvolunteeringoptions'] = $atts['microvolunteeringoptions'] ?? [
            'approvedmessages' => 1,
            'wordmatch' => 1,
            'photorotate' => 1,
        ];

        return $atts;
    }
}
