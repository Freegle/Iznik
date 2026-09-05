<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageGroup;
use App\Services\AutoRepostService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The due window moved from PHP into the candidate query, so the two have to agree.
 *
 * The direction that matters is one-sided: the query may hand PHP more rows than it
 * will act on, because PHP still applies every check afterwards. It must never hand
 * over fewer, because a post the query drops is a repost that silently never happens.
 *
 * The bounds are written against arrival while the PHP test uses TIMESTAMPDIFF, which
 * truncates to whole hours, so the query is deliberately a little wider at the lower
 * edge. These tests walk the boundary hour by hour to pin that down rather than take
 * it on trust.
 */
class AutoRepostDueWindowTest extends TestCase
{
    private AutoRepostService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AutoRepostService();
    }

    /** Run the real candidate query for a group with these settings. */
    private function candidateIds(int $groupid, array $reposts): array
    {
        $method = new \ReflectionMethod(AutoRepostService::class, 'getCandidates');
        $method->setAccessible(true);
        $mindate = now()->subDays(AutoRepostService::LOOKBACK_DAYS)->format('Y-m-d');

        return collect($method->invoke($this->service, $groupid, $mindate, $reposts))
            ->pluck('msgid')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * What the PHP logic would do with a post of this age: does either the warning or
     * the repost branch fire? Mirrors processGroup's arithmetic exactly.
     */
    private function phpWouldAct(int $hoursAgo, int $interval, int $max): bool
    {
        if ($interval <= 0 || $max <= 0) {
            return false;
        }
        if ($hoursAgo >= $interval * ($max + 1) * 24) {
            return false;                                  // aged out
        }
        if ($hoursAgo <= $interval * 24 && $hoursAgo > ($interval - 1) * 24) {
            return true;                                   // warning band
        }

        return $hoursAgo > $interval * 24;                 // repost band
    }

    private function postAged(int $groupid, int $userid, int $hoursAgo, string $type): int
    {
        $msgid = DB::table('messages')->insertGetId([
            'type' => $type,
            'fromuser' => $userid,
            'subject' => ($type === Message::TYPE_OFFER ? 'OFFER' : 'WANTED') . ": window {$hoursAgo}h",
            'textbody' => 'body',
            'source' => Message::SOURCE_PLATFORM,
            'fromaddr' => 'someone@ilovefreegle.org',
            'date' => now()->subHours($hoursAgo),
            'arrival' => now()->subHours($hoursAgo),
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $msgid,
            'groupid' => $groupid,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subHours($hoursAgo),
            'autoreposts' => 0,
        ]);

        return $msgid;
    }

    /**
     * The one that matters: nothing PHP would act on may be filtered out by the query.
     */
    public function test_the_query_never_drops_a_post_php_would_act_on(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);
        DB::table('users')->where('id', $user->id)->update(['lastaccess' => now()->subYear()]);

        $reposts = ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5];

        // Walk each boundary hour by hour either side, for both types.
        $ages = [];
        foreach ([3, 7] as $interval) {
            foreach ([($interval - 1) * 24, $interval * 24, $interval * ($reposts['max'] + 1) * 24] as $edge) {
                foreach ([-2, -1, 0, 1, 2] as $offset) {
                    $age = $edge + $offset;
                    if ($age > 0) {
                        $ages[] = $age;
                    }
                }
            }
        }
        $ages = array_values(array_unique($ages));

        $expected = [];
        foreach ($ages as $age) {
            foreach ([Message::TYPE_OFFER => 3, Message::TYPE_WANTED => 7] as $type => $interval) {
                $msgid = $this->postAged($group->id, $user->id, $age, $type);
                if ($this->phpWouldAct($age, $interval, $reposts['max'])) {
                    $expected[$msgid] = "{$type} at {$age}h";
                }
            }
        }

        $returned = $this->candidateIds($group->id, $reposts);

        foreach ($expected as $msgid => $what) {
            $this->assertContains(
                $msgid,
                $returned,
                "the query dropped a post the PHP logic would have acted on: {$what}"
            );
        }
    }

    /**
     * And it should genuinely narrow things, or it is not worth having: a post far too
     * new to be due must not come back at all.
     */
    public function test_the_query_drops_posts_that_are_nowhere_near_due(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $reposts = ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5];

        $tooNew = $this->postAged($group->id, $user->id, 1, Message::TYPE_OFFER);
        $due = $this->postAged($group->id, $user->id, 3 * 24 + 5, Message::TYPE_OFFER);

        $returned = $this->candidateIds($group->id, $reposts);

        $this->assertNotContains($tooNew, $returned, 'an hour-old post is not due for anything');
        $this->assertContains($due, $returned);
    }

    public function test_a_post_past_its_maximum_age_is_dropped(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $reposts = ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5];

        // interval 3 x (max 5 + 1) = 18 days; a 20-day-old offer has aged out.
        $agedOut = $this->postAged($group->id, $user->id, 20 * 24, Message::TYPE_OFFER);

        $this->assertNotContains($agedOut, $this->candidateIds($group->id, $reposts));
    }

    /** Each type is bounded by its own interval, not by the other's. */
    public function test_offer_and_wanted_use_their_own_intervals(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $reposts = ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5];

        // Four days: past an offer's 3-day interval, short of a wanted's 7.
        $offer = $this->postAged($group->id, $user->id, 4 * 24, Message::TYPE_OFFER);
        $wanted = $this->postAged($group->id, $user->id, 4 * 24, Message::TYPE_WANTED);

        $returned = $this->candidateIds($group->id, $reposts);

        $this->assertContains($offer, $returned);
        $this->assertNotContains($wanted, $returned, 'a wanted is not due at 4 days when its interval is 7');
    }

    /** Reposting switched off for the group means no candidates at all. */
    public function test_reposting_turned_off_returns_nothing(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $this->postAged($group->id, $user->id, 30 * 24, Message::TYPE_OFFER);

        $this->assertSame([], $this->candidateIds($group->id, ['offer' => 3, 'wanted' => 7, 'max' => 0]));
        $this->assertSame([], $this->candidateIds($group->id, ['offer' => 0, 'wanted' => 0, 'max' => 5]));
    }
}
