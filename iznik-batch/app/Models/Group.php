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
        'showjoin' => 0,
        'engagement' => 1,
        // Community News: ON by default — opt-OUT per community (2026-08-07;
        // launched opt-in, flipped once the ChitChat trial and first weekly
        // email proved out). A community that doesn't want the round-up sets
        // the flag to 0 in ModTools.
        'communitynews' => 1,
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
                // keep-raw: ST_GeomFromText is a spatial function the query builder has no
                // method for; the value must be a raw SQL expression in the INSERT.
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
            // whereJsonContains, not ->where(..., 0): JSON_CONTAINS matches the value AND its
            // type, so it distinguishes integer 0 from false, from "0" and from JSON null -
            // exactly as the raw JSON_EXTRACT(...) = 0 did. ->where() would render
            // json_unquote(json_extract(...)) = 0, and json_unquote turns true into the string
            // 'true' and null into 'null', both of which MySQL casts to 0, so that form would
            // report every CLOSED group as open.
            $q->whereNull('settings')
                ->orWhereJsonDoesntContainKey('settings->closed')
                ->orWhere('settings->closed', false)
                ->orWhereJsonContains('settings->closed', 0);
        });
    }

    /**
     * Scope to groups taking part in Community News.
     *
     * Community News is ON by default (opt-OUT per community, like
     * newsletter/newsfeed): a group is in unless the flag is present and
     * explicitly falsy. Mirrors scopeNotClosed's int/bool JSON handling
     * (0 != false in MySQL JSON).
     */
    public function scopeCommunityNewsEnabled(Builder $query): Builder
    {
        return $query->where(function ($q) {
            // Same builder equivalents as scopeNotClosed: whereJsonDoesntContainKey for the
            // IS NULL (key-absence) arm, whereJsonContains for the type-exact integer arm, and
            // the boolean special case Laravel emits unwrapped.
            $q->whereNull('settings')
                ->orWhereJsonDoesntContainKey('settings->communitynews')
                ->orWhereJsonContains('settings->communitynews', 1)
                ->orWhere('settings->communitynews', true);
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
     * Ported from the legacy V1 PHP Group::getWorkCounts().
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
                // keep-raw: an aliased aggregate in a multi-row SELECT list under GROUP BY -
                // no builder method projects one alongside plain columns (see manifest reason
                // recorded for this site).
                DB::raw('COUNT(*) AS count'),
                'messages_groups.collection',
                // keep-raw: a boolean "IS NOT NULL" projected as its own aliased select
                // column - there is no builder method for a boolean-expression projection,
                // only whereNull/whereNotNull for filtering.
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
        $reviewCutoff = now()->subDays(31);

        $spammembercounts = Membership::query()
            ->select([
                'groupid',
                // keep-raw: aliased aggregate in a multi-row SELECT list under GROUP BY -
                // same as pendingspamcounts above.
                DB::raw('COUNT(*) AS count'),
                // keep-raw: boolean "IS NOT NULL" projected as an aliased select column -
                // same as pendingspamcounts above.
                DB::raw('heldby IS NOT NULL AS held'),
            ])
            ->whereNotNull('reviewrequestedat')
            ->where(function ($q) use ($reviewCutoff) {
                $q->whereNull('reviewedat')
                    // DATE(col) < DATE_SUB(NOW(), INTERVAL 31 DAY) compares a DATE against a
                    // DATETIME, so MySQL widens the DATE to midnight: the predicate is
                    // strict only when the cutoff itself lands exactly on midnight, and
                    // inclusive-of-that-date otherwise. These run from cron, which really
                    // can fire at 00:00:00, so the operator is chosen rather than assumed.
                    ->orWhereDate('reviewedat', $reviewCutoff->format('H:i:s') === '00:00:00' ? '<' : '<=', $reviewCutoff->toDateString());
            })
            ->whereIn('groupid', $groupids)
            ->groupBy('groupid', 'held')
            ->get();

        // Pending community event counts.
        $pendingeventcounts = DB::table('communityevents')
            ->select([
                'communityevents_groups.groupid',
                // keep-raw: aliased aggregate (COUNT DISTINCT) in a multi-row SELECT list
                // under GROUP BY - no builder method projects one alongside plain columns.
                DB::raw('COUNT(DISTINCT communityevents.id) AS count'),
            ])
            ->join('communityevents_dates', 'communityevents_dates.eventid', '=', 'communityevents.id')
            ->join('communityevents_groups', 'communityevents.id', '=', 'communityevents_groups.eventid')
            ->join('groups', 'groups.id', '=', 'communityevents_groups.groupid')
            ->whereIn('communityevents_groups.groupid', $groupids)
            ->where(function ($q) {
                $q->whereNull('groups.settings')
                    ->orWhereJsonDoesntContainKey('groups.settings->communityevents')
                    ->orWhereJsonContains('groups.settings->communityevents', 1);
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
                // keep-raw: aliased aggregate (COUNT DISTINCT) in a multi-row SELECT list
                // under GROUP BY - same reason as pendingeventcounts above.
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
                    ->orWhereJsonDoesntContainKey('groups.settings->volunteering')
                    ->orWhereJsonContains('groups.settings->volunteering', 1);
            })
            ->where(function ($q) use ($eventsqltime) {
                // applyby lives on volunteering_dates, not volunteering. The original
                // raw SQL used the bare column name, which MySQL resolved unambiguously
                // because only that joined table has it; the port to the query builder
                // qualified it with the wrong table, so this method threw
                // "ERROR 1054 (42S22): Unknown column 'volunteering.applyby'" on EVERY
                // call - verified by executing both forms against MySQL.
                $q->whereNull('volunteering_dates.applyby')
                    ->orWhere('volunteering_dates.applyby', '>=', $eventsqltime);
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
                // keep-raw: aliased aggregate (COUNT DISTINCT) in a multi-row SELECT list
                // under GROUP BY - same reason as pendingeventcounts above.
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
        //
        // logincount is a correlated aggregate subquery projected as a select column;
        // built via selectSub() with the sub-builder's ->aggregate set directly so it
        // compiles to "(select count(*) as aggregate from ...)" without any raw SQL. The
        // outer alias ("logincount") is what having() and callers key off; the sub-builder's
        // own "as aggregate" label is unused. having('logincount', ...) then references that
        // outer select-list alias directly, exactly as havingRaw('logincount > 0') did.
        $sub1LoginCount = DB::table('users_logins')->whereColumn('userid', 'memberships.userid');
        $sub1LoginCount->aggregate = ['function' => 'count', 'columns' => ['*']];

        $sub1 = DB::table('users_related')
            ->select([
                'users_related.user1',
                'memberships.groupid',
            ])
            ->selectSub($sub1LoginCount, 'logincount')
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
            ->having('logincount', '>', 0);

        $sub2LoginCount = DB::table('users_logins')->whereColumn('userid', 'memberships.userid');
        $sub2LoginCount->aggregate = ['function' => 'count', 'columns' => ['*']];

        $sub2 = DB::table('users_related')
            ->select([
                'users_related.user1',
                'memberships.groupid',
            ])
            ->selectSub($sub2LoginCount, 'logincount')
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
            ->having('logincount', '>', 0);

        $unionQuery = $sub1->union($sub2);
        $relatedmembers = DB::query()
            ->fromSub($unionQuery, 't')
            // keep-raw: aliased aggregate (COUNT(*) AS count) combined with a plain column
            // in the same multi-row SELECT list under GROUP BY - no builder method projects
            // one alongside the other; see the manifest reason recorded for this construct.
            ->selectRaw('COUNT(*) AS count, groupid')
            ->groupBy('groupid')
            ->get();

        # We only want to show edit reviews upto 7 days old - after that assume they're ok.
        $mysqltime7 = now()->startOfDay()->subDays(7);
        $editreviewcounts = DB::table('messages_edits')
            ->select([
                'messages_groups.groupid',
                // keep-raw: aliased aggregate (COUNT DISTINCT) in a multi-row SELECT list
                // under GROUP BY - same reason as pendingeventcounts above.
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
                // keep-raw: aliased aggregate (COUNT DISTINCT) in a multi-row SELECT list
                // under GROUP BY - same reason as pendingeventcounts above.
                DB::raw('COUNT(DISTINCT messages_groups.msgid) AS count'),
            ])
            ->join('messages_groups', 'messages_groups.msgid', '=', 'messages_outcomes.msgid')
            ->join('messages', 'messages.id', '=', 'messages_outcomes.msgid')
            ->where('messages_outcomes.timestamp', '>', $earliestmsg)
            ->where('messages_groups.arrival', '>', $earliestmsg)
            ->whereIn('messages_groups.groupid', $groupids)
            ->where('messages_outcomes.reviewed', 0)
            ->whereNotNull('messages_outcomes.comments')
            ->whereNotIn('messages_outcomes.comments', self::HAPPINESS_FILTER_EXCLUDED_COMMENTS)
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
     * Auto-generated/boilerplate happiness comments to exclude when counting comments
     * that are actually worth a moderator's attention.
     *
     * Ported from the legacy V1 PHP Group::getHappinessFilter(). Applied via whereNotNull()
     * + whereNotIn() at the call site (a chain of "comments IS NOT NULL AND comments != x"
     * is equivalent to NOT IN once NULLs are already excluded).
     */
    private const HAPPINESS_FILTER_EXCLUDED_COMMENTS = [
        'Sorry, this is no longer available.',
        'Thanks, this has now been taken.',
        "Thanks, I'm no longer looking for this.",
        'Sorry, this has now been taken.',
        'Thanks for the interest, but this has now been taken.',
        'Thanks, these have now been taken.',
        'Thanks, this has now been received.',
        'Withdrawn on user unsubscribe',
        'Auto-Expired',
    ];

    // Fields exposed by getPublic() - mirrors the legacy V1 PHP Group::$publicatts.
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
     * Ported from the legacy V1 PHP Group::getPublic().
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
