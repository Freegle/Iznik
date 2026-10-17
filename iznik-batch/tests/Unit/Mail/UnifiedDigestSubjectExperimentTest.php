<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\DigestStyle;
use App\Mail\Digest\UnifiedDigest;
use App\Models\Group;
use App\Models\User;
use App\Services\UnifiedDigestService;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentService;
use App\Support\ExperimentBucket;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HidesExposureTable;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * The subject of the daily digest in the experiment digest-subject
 * (docs/developers/reference/ux-experiments.md, "The daily digest subject").
 *
 * Arm control keeps the subject as it always was: "What's New (12 posts) - Sofa, Lamp, Books, Chair, Table".
 * Arm items names the same items, then how many more posts the email lists: "Sofa, Lamp, Books, Chair,
 * Table and 7 more". It applies to a digest of two or more posts. A digest of one post is not in the
 * experiment, so it keeps the control subject and writes no exposure.
 *
 * legacySubject() is a copy of the lines the control subject is built by, so the control path can be
 * compared with them for as long as the experiment lasts. It goes with the experiment.
 *
 * A member is put in an arm without choosing ids: publish() writes the arms with the member's own
 * bucket in the arm wanted, and the first range that holds a bucket wins.
 */
class UnifiedDigestSubjectExperimentTest extends TestCase
{
    use HidesExposureTable;
    use IsolatedSpoolDirectory;

    private const NAME = 'digest-subject';

    private ?Group $group = null;

    private ?User $poster = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        UxArm::flush();
        $this->setUpIsolatedSpoolDirectory();
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UxArm::flush();
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- the cases

    private static function offer(string $name, string $where = 'London'): string
    {
        return "OFFER: {$name} ({$where})";
    }

    /**
     * Every case: the titles of the posts, the control subject and the items subject. The control column
     * is what the code has always produced, including the stray byte in L1 to L3 and the empty slot in
     * K2. A digest of one post, and a digest with nothing to name, have the control subject in both arms.
     *
     * @return array<string, array{0: string[], 1: string, 2: string}>
     */
    public static function cases(): array
    {
        $a25 = str_repeat('A', 25);
        $b23 = str_repeat('B', 23);
        $b24 = str_repeat('B', 24);
        $c26 = str_repeat('C', 26);
        $c25 = str_repeat('C', 25);
        $c22 = str_repeat('C', 22);
        $sofas = implode(', ', array_fill(0, 8, 'Sofa'));
        $sofas6 = implode(', ', array_fill(0, 6, 'Sofa'));
        $furniture = self::offer('Garden furniture set');

        return [
            'A, five named' => [
                [self::offer('Sofa'), self::offer('Lamp'), self::offer('Books'), self::offer('Chair'), self::offer('Table'), ...array_fill(0, 7, $furniture)],
                "What's New (12 posts) - Sofa, Lamp, Books, Chair, Table",
                'Sofa, Lamp, Books, Chair, Table and 7 more',
            ],
            'B, all named' => [
                [self::offer('Sofa'), self::offer('Lamp'), self::offer('Books')],
                "What's New (3 posts) - Sofa, Lamp, Books",
                'Sofa, Lamp, Books',
            ],
            'C, the tail takes the room of a name' => [
                [self::offer('Wooden bookcase'), self::offer('Single bed base'), self::offer('Bedside cabinet'), self::offer('Rug')],
                "What's New (4 posts) - Wooden bookcase, Single bed base, Bedside cabinet",
                'Wooden bookcase, Single bed base and 2 more',
            ],
            'D1, the names fill 50 and the tail does not fit' => [
                [self::offer($a25), self::offer($b23), self::offer('Rug')],
                "What's New (3 posts) - {$a25}, {$b23}",
                "{$a25} and 2 more",
            ],
            'D2, the names would be 51 long' => [
                [self::offer($a25), self::offer($b24), self::offer('Rug')],
                "What's New (3 posts) - {$a25}",
                "{$a25} and 2 more",
            ],
            'E1, a name of 26 is cut' => [
                [self::offer($c26), self::offer('Lamp')],
                "What's New (2 posts) - {$c22}..., Lamp",
                "{$c22}..., Lamp",
            ],
            'E2, a name of 25 stays whole' => [
                [self::offer($c25), self::offer('Lamp')],
                "What's New (2 posts) - {$c25}, Lamp",
                "{$c25}, Lamp",
            ],
            'F1, at the post cap' => [
                array_fill(0, 65, self::offer('Sofa')),
                "What's New (65 posts) - {$sofas}",
                "{$sofas6} and 59 more",
            ],
            'F2, over the post cap' => [
                array_fill(0, 66, self::offer('Sofa')),
                "What's New - {$sofas}",
                "{$sofas6} and 59 more",
            ],
            'G, a comma in a name' => [
                [self::offer('Sofa, 3 seater'), self::offer('Lamp'), self::offer('Books'), ...array_fill(0, 3, self::offer($c25))],
                "What's New (6 posts) - Sofa, 3 seater, Lamp, Books",
                'Sofa, 3 seater, Lamp, Books and 3 more',
            ],
            'H, an entity' => [
                [self::offer('Coffee &amp; Cake'), self::offer('Lamp')],
                "What's New (2 posts) - Coffee & Cake, Lamp",
                'Coffee & Cake, Lamp',
            ],
            'I, no posts' => [
                [],
                "What's New (0 posts)",
                "What's New (0 posts)",
            ],
            'J, one post is not in the experiment' => [
                [self::offer('Sofa')],
                "What's New (1 post) - Sofa",
                "What's New (1 post) - Sofa",
            ],
            'K1, one post with no name' => [
                ['OFFER: (Sofa)'],
                "What's New (1 post)",
                "What's New (1 post)",
            ],
            'K2, the first of two posts has no name' => [
                ['OFFER: (Sofa)', self::offer('Lamp')],
                "What's New (2 posts) - , Lamp",
                'Lamp and 1 more',
            ],
            'K3, two posts and neither has a name' => [
                ['OFFER: (Sofa)', 'OFFER: (Lamp)'],
                "What's New (2 posts) - , ",
                "What's New (2 posts) - , ",
            ],
            'L1, a 2 byte character at byte 22' => [
                [self::offer(str_repeat('a', 21)."\u{00E9}".'bcdefg'), self::offer('Lamp')],
                "What's New (2 posts) - ".str_repeat('a', 21)."\xC3...".', Lamp',
                str_repeat('a', 21).'..., Lamp',
            ],
            'L2, a 3 byte character at byte 22' => [
                [self::offer(str_repeat('a', 20)."\u{2019}".'sbike and more'), self::offer('Lamp')],
                "What's New (2 posts) - ".str_repeat('a', 20)."\xE2\x80...".', Lamp',
                str_repeat('a', 20).'..., Lamp',
            ],
            'L3, a 4 byte character at byte 22' => [
                [self::offer(str_repeat('a', 19)."\u{1F6CB}".' sofa with more'), self::offer('Lamp')],
                "What's New (2 posts) - ".str_repeat('a', 19)."\xF0\x9F\x9B...".', Lamp',
                str_repeat('a', 19).'..., Lamp',
            ],
            'M, an apostrophe entity' => [
                // A title is stored HTML-encoded, so an apostrophe in a name is stored as an entity. Both subjects show the apostrophe.
                [self::offer('Men&#039;s coat'), self::offer('Lamp')],
                "What's New (2 posts) - Men's coat, Lamp",
                "Men's coat, Lamp",
            ],
            'N, a name of 14 two byte characters' => [
                // 14 characters are 28 bytes, over the limit of 25 bytes, so the name is cut. Byte 22 ends the 11th character, so the cut
                // splits no character and the control subject and the items subject agree on it.
                [self::offer(str_repeat("\u{00E9}", 14)), self::offer('Lamp')],
                "What's New (2 posts) - ".str_repeat("\u{00E9}", 11).'..., Lamp',
                str_repeat("\u{00E9}", 11).'..., Lamp',
            ],
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function group(): Group
    {
        return $this->group ??= $this->createTestGroup();
    }

    private function poster(): User
    {
        if ($this->poster === null) {
            $this->poster = $this->createTestUser();
            $this->createMembership($this->poster, $this->group());
        }

        return $this->poster;
    }

    private function member(): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $this->group());

        return $user;
    }

    /**
     * A member whose bucket is neither 0 nor 99, so that either end of an arm can be tried.
     */
    private function memberAwayFromTheEnds(): User
    {
        for ($i = 0; $i < 50; $i++) {
            $user = $this->member();
            $bucket = ExperimentBucket::bucket($user->id, self::NAME);
            if ($bucket >= 1 && $bucket <= 98) {
                return $user;
            }
        }

        $this->fail('no member with a bucket between 1 and 98 in 50 tries');
    }

    /**
     * @param  string[]  $titles  message subjects; a title that repeats uses one message
     * @return Collection<int, array{message: \App\Models\Message, postedToGroups: int[]}>
     */
    private function posts(array $titles): Collection
    {
        $messages = [];
        $posts = [];
        foreach ($titles as $title) {
            $messages[$title] ??= $this->createTestMessage($this->poster(), $this->group(), ['subject' => $title]);
            $posts[] = ['message' => $messages[$title], 'postedToGroups' => [$this->group()->id]];
        }

        return collect($posts);
    }

    private function digest(Collection $posts, User $user, string $mode = UnifiedDigestService::MODE_DAILY): UnifiedDigest
    {
        return new UnifiedDigest($user, $posts, $mode);
    }

    private function setConfig(string $key, string $value): void
    {
        DB::table('config')->upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
    }

    /**
     * Publish the experiment as the engine would, with the member's own bucket in $arm, and switch
     * server arms on. The other arm takes everyone else. $entry replaces keys of the entry, such as until.
     *
     * @param  array<string, mixed>  $entry
     */
    private function publish(User $user, string $arm, array $entry = [], ?string $switch = '1'): void
    {
        $b = ExperimentBucket::bucket($user->id, self::NAME);
        $ranges = $arm === 'control'
            ? ['control' => [$b, $b], 'items' => [0, 99]]
            : [$arm => [$b, $b], 'control' => [0, 99]];

        $this->publishRanges($ranges, $entry, $switch);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $ranges
     * @param  array<string, mixed>  $entry
     */
    private function publishRanges(array $ranges, array $entry = [], ?string $switch = '1'): void
    {
        $entry += ['arms' => $ranges, 'until' => Carbon::now()->getTimestamp() + 3600];
        $this->setConfig(UxExperimentService::CONFIG_KEY, json_encode([self::NAME => $entry]));
        if ($switch === null) {
            DB::table('config')->where('key', UxArm::SWITCH_KEY)->delete();
        } else {
            $this->setConfig(UxArm::SWITCH_KEY, $switch);
        }
        UxArm::flush();
    }

    /** @return object[] */
    private function exposures(User $user): array
    {
        return DB::table('ux_experiment_exposures')->where('experiment', self::NAME)->where('userid', $user->id)->orderBy('id')->get()->all();
    }

    /**
     * The daily subject as UnifiedDigest built it before the experiment: a copy of the daily branch of
     * getSubject(), of getItemNamesForSubject() and of extractItemName(), as they stood when the experiment
     * was added.
     */
    private function legacySubject(Collection $posts): string
    {
        $count = $posts->count();

        $names = [];
        $totalLength = 0;
        foreach ($posts as $post) {
            $decodedSubject = html_entity_decode($post['message']->subject ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $itemName = preg_replace('/^(OFFER|WANTED)\s*:\s*/i', '', $decodedSubject);
            $itemName = trim(preg_replace('/\s*\([^)]+\)\s*$/', '', $itemName));
            if (strlen($itemName) > 25) {
                $itemName = substr($itemName, 0, 22).'...';
            }
            $newLength = $totalLength + strlen($itemName) + ($totalLength > 0 ? 2 : 0);
            if ($newLength > 50) {
                break;
            }
            $names[] = $itemName;
            $totalLength = $newLength;
        }
        $itemNames = implode(', ', $names);

        $subject = $count > DigestStyle::DIGEST_POST_CAP
            ? "What's New"
            : "What's New ({$count} post".($count === 1 ? '' : 's').')';
        if ($itemNames) {
            $subject .= " - {$itemNames}";
        }

        return $subject;
    }

    // ---------------------------------------------------------------- the items arm

    public function test_the_items_arm_leads_the_subject_with_the_items(): void
    {
        $user = $this->member();
        $this->publish($user, 'items');

        $mail = $this->digest($this->posts(self::cases()['A, five named'][0]), $user);

        $this->assertSame('Sofa, Lamp, Books, Chair, Table and 7 more', $mail->envelope()->subject);
    }

    public function test_the_subject_in_the_tracking_row_is_the_subject_sent(): void
    {
        // The arm is looked up before initTracking() runs getSubject(), so the row holds the subject the arm gives.
        $posts = $this->posts(self::cases()['A, five named'][0]);

        foreach (['items', 'control'] as $arm) {
            $user = $this->member();
            $this->publish($user, $arm);

            $mail = $this->digest($posts, $user);

            $this->assertNotNull($mail->getTracking(), $arm);
            $this->assertSame($mail->envelope()->subject, $mail->getTracking()->subject, $arm);
            $this->assertSame($mail->getTracking()->subject, DB::table('email_tracking')->where('id', $mail->getTracking()->id)->value('subject'), "$arm: and the stored row");
        }
    }

    public function test_the_spooled_message_carries_the_items_subject(): void
    {
        $user = $this->member();
        $this->publish($user, 'items');
        $mail = $this->digest($this->posts(self::cases()['A, five named'][0]), $user);

        $id = $this->spooler->spool($mail, $user->email_preferred);
        $data = json_decode(file_get_contents($this->testSpoolDir.'/pending/'.$id.'.json'), true);

        $this->assertSame('Sofa, Lamp, Books, Chair, Table and 7 more', $data['subject']);
    }

    public function test_the_items_arm_changes_the_subject_and_nothing_else_the_spool_holds(): void
    {
        $posts = $this->posts(self::cases()['A, five named'][0]);
        $spooled = [];
        foreach (['control', 'items'] as $arm) {
            $user = $this->member();
            $this->publish($user, $arm);
            $mail = $this->digest($posts, $user);
            $id = $this->spooler->spool($mail, $user->email_preferred);
            $spooled[$arm] = ['data' => json_decode(file_get_contents($this->testSpoolDir.'/pending/'.$id.'.json'), true), 'type' => $mail->getEmailType()];
        }

        $this->assertNotSame($spooled['control']['data']['subject'], $spooled['items']['data']['subject']);
        $this->assertSame('UnifiedDigestDaily', $spooled['control']['type']);
        $this->assertSame('UnifiedDigestDaily', $spooled['items']['type'], 'the email type is matched exactly elsewhere');
        $this->assertSame($spooled['control']['data']['from'], $spooled['items']['data']['from']);
        $this->assertSame($spooled['control']['data']['reply_to'], $spooled['items']['data']['reply_to']);
        $this->assertSame(array_keys($spooled['control']['data']['headers']), array_keys($spooled['items']['data']['headers']));
    }

    public function test_the_first_bucket_of_items_gets_items_and_the_last_of_control_gets_control(): void
    {
        $posts = $this->posts(self::cases()['B, all named'][0]);
        $user = $this->memberAwayFromTheEnds();
        $b = ExperimentBucket::bucket($user->id, self::NAME);

        // The member is the first bucket of items.
        $this->publishRanges(['control' => [0, $b - 1], 'items' => [$b, 99]]);
        $this->assertSame('Sofa, Lamp, Books', $this->digest($posts, $user)->envelope()->subject, 'the first bucket of items');

        // The member is the last bucket of control.
        $this->publishRanges(['control' => [0, $b], 'items' => [$b + 1, 99]]);
        $this->assertSame("What's New (3 posts) - Sofa, Lamp, Books", $this->digest($posts, $user)->envelope()->subject, 'the last bucket of control');
    }

    public function test_the_arm_stops_at_until(): void
    {
        $posts = $this->posts(self::cases()['B, all named'][0]);
        $user = $this->member();
        $this->publish($user, 'items', ['until' => Carbon::now()->getTimestamp() + 30]);

        Carbon::setTestNow('2026-10-20 10:00:29');
        $before = $this->digest($posts, $user);
        $this->assertSame('Sofa, Lamp, Books', $before->envelope()->subject, 'one second before until');
        $this->assertCount(1, $this->exposures($user));

        Carbon::setTestNow('2026-10-20 10:00:30');
        $after = $this->digest($posts, $user);
        $this->assertSame("What's New (3 posts) - Sofa, Lamp, Books", $after->envelope()->subject, 'at until');
        $this->assertCount(1, $this->exposures($user), 'and no row for the second');
    }

    #[DataProvider('cases')]
    public function test_each_case_in_both_arms(array $titles, string $control, string $items): void
    {
        $posts = $this->posts($titles);
        $user = $this->member();

        // No arm: the subject as it always was.
        $this->assertSame($control, $this->digest($posts, $user)->envelope()->subject, 'no experiment published');
        $this->assertSame($control, $this->legacySubject($posts), 'the copy of the old lines gives the same');

        $this->publish($user, 'control');
        $this->assertSame($control, $this->digest($posts, $user)->envelope()->subject, 'arm control');

        $this->publish($user, 'items');
        $mail = $this->digest($posts, $user);
        $subject = $mail->envelope()->subject;
        $this->assertSame($items, $subject, 'arm items');
        $this->assertNotSame('', $subject, 'an empty subject would become the class name');
        $this->assertTrue(mb_check_encoding($subject, 'UTF-8'), 'the items subject is valid UTF-8');
        $this->assertSame($subject, $mail->getTracking()->subject, 'the tracking row holds the subject sent');
    }

    // ---------------------------------------------------------------- the control path is unchanged

    /**
     * Each way a member can end up with the control subject, as the publish step of that case.
     *
     * @return array<string, array{0: string}>
     */
    public static function waysToGetControl(): array
    {
        return [
            'no experiment published' => ['none'],
            'arm control' => ['control'],
            'an arm this code has no variant for' => ['other'],
            'the switch row is missing' => ['switch missing'],
            'the switch is 0' => ['switch 0'],
            'the switch is true, which is not 1' => ['switch true'],
            'the entry has expired' => ['expired'],
            'email tracking is off' => ['tracking off'],
        ];
    }

    private function applyWayToGetControl(string $way, User $user): void
    {
        match ($way) {
            'none' => null,
            'control' => $this->publish($user, 'control'),
            'other' => $this->publish($user, 'other'),
            'switch missing' => $this->publish($user, 'items', [], null),
            'switch 0' => $this->publish($user, 'items', [], '0'),
            'switch true' => $this->publish($user, 'items', [], 'true'),
            'expired' => $this->publish($user, 'items', ['until' => Carbon::now()->getTimestamp() - 1]),
            'tracking off' => $this->publishAndSwitchTrackingOff($user),
        };
    }

    private function publishAndSwitchTrackingOff(User $user): void
    {
        $this->publish($user, 'items');
        config(['freegle.mail.tracking_enabled' => false]);
    }

    #[DataProvider('waysToGetControl')]
    public function test_control_builds_the_subject_the_old_lines_built(string $way): void
    {
        $user = $this->member();
        $this->applyWayToGetControl($way, $user);

        foreach (self::cases() as $name => [$titles]) {
            $posts = $this->posts($titles);
            $mail = $this->digest($posts, $user);
            $expected = $this->legacySubject($posts);

            $this->assertSame($expected, $mail->envelope()->subject, "$way, case $name");
            if ($mail->getTracking() !== null) {
                $this->assertSame($expected, $mail->getTracking()->subject, "$way, case $name, the tracking row");
            } else {
                $this->assertSame('tracking off', $way, "$name has a tracking row unless tracking is off");
            }
        }
    }

    public function test_immediate_mail_is_not_touched(): void
    {
        // Two posts, so that the rule "a digest of two or more posts" cannot stand in for the rule "daily mail only":
        // with two posts it is the mode alone that keeps this mail out of the experiment.
        $user = $this->member();
        $posts = $this->posts([self::offer('Sofa'), self::offer('Lamp')]);

        $plain = $this->digest($posts, $user, UnifiedDigestService::MODE_IMMEDIATE)->envelope()->subject;

        $this->publish($user, 'items');
        $mail = $this->digest($posts, $user, UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertSame($plain, $mail->envelope()->subject);
        $this->assertSame('UnifiedDigestImmediate', $mail->getEmailType());
        $this->assertSame([], $this->exposures($user));
    }

    public function test_the_items_names_are_the_names_the_control_subject_shows(): void
    {
        // namedItemsForSubject() writes the rules of getItemNamesForSubject() again. The names after "What's New (N posts) - "
        // are the names before " and N more", except where the tail needs room: then the items subject names fewer, and its
        // names are the first names of the control subject, cut between two names.
        $user = $this->member();
        $checked = 0;
        $makesRoomForTheTail = [
            'C, the tail takes the room of a name',
            'D1, the names fill 50 and the tail does not fit',
            'F1, at the post cap',
            'F2, over the post cap',
        ];
        foreach (['A, five named', 'B, all named', 'C, the tail takes the room of a name', 'D1, the names fill 50 and the tail does not fit', 'D2, the names would be 51 long',
            'E1, a name of 26 is cut', 'E2, a name of 25 stays whole', 'F1, at the post cap', 'F2, over the post cap',
            'G, a comma in a name', 'H, an entity', 'M, an apostrophe entity', 'N, a name of 14 two byte characters'] as $name) {
            [$titles] = self::cases()[$name];
            $posts = $this->posts($titles);

            $this->publish($user, 'control');
            $controlNames = explode(' - ', $this->digest($posts, $user)->envelope()->subject, 2)[1];
            $this->publish($user, 'items');
            $itemsNames = preg_replace('/ and \d+ more\z/', '', $this->digest($posts, $user)->envelope()->subject);

            if (in_array($name, $makesRoomForTheTail, true)) {
                $this->assertLessThan(strlen($controlNames), strlen($itemsNames), $name);
                $this->assertStringStartsWith($itemsNames.', ', $controlNames, $name);
            } else {
                $this->assertSame($controlNames, $itemsNames, $name);
            }
            $checked++;
        }

        $this->assertSame(13, $checked);
    }

    // ---------------------------------------------------------------- the names come from the posts the email lists

    public function test_the_items_subject_never_names_a_post_the_email_does_not_hold(): void
    {
        // The member owns 65 posts, which fill the cap and are all kept. The two posts of other members come first and are
        // dropped by the cap, so the email holds only "Mine".
        $member = $this->member();
        $other = $this->createTestUser();
        $this->createMembership($other, $this->group());

        $cards = [];
        foreach (['Sofa', 'Lamp'] as $name) {
            $cards[] = ['message' => $this->createTestMessage($other, $this->group(), ['subject' => self::offer($name)]), 'postedToGroups' => [$this->group()->id]];
        }
        $mine = $this->createTestMessage($member, $this->group(), ['subject' => self::offer('Mine')]);
        for ($i = 0; $i < DigestStyle::DIGEST_POST_CAP; $i++) {
            $cards[] = ['message' => $mine, 'postedToGroups' => [$this->group()->id]];
        }

        $this->publish($member, 'items');
        $mail = $this->digest(collect($cards), $member);

        $this->assertSame('Mine, Mine, Mine, Mine, Mine, Mine and 59 more', $mail->envelope()->subject);
        $this->assertStringNotContainsString('Sofa', $mail->envelope()->subject);
        $this->assertStringNotContainsString('Lamp', $mail->envelope()->subject);
        $this->assertSame(DigestStyle::DIGEST_POST_CAP, $mail->getTracking()->metadata['post_count'] ?? null);
    }

    // ---------------------------------------------------------------- a digest with nothing to name is in neither arm

    public function test_a_digest_with_nothing_to_name_has_the_control_subject_and_no_row_in_either_arm(): void
    {
        $titles = ['OFFER: (Sofa)', 'OFFER: (Lamp)'];

        foreach (['control', 'items'] as $arm) {
            $user = $this->member();
            $this->publish($user, $arm);

            $mail = $this->digest($this->posts($titles), $user);

            $this->assertSame("What's New (2 posts) - , ", $mail->envelope()->subject, $arm);
            $this->assertSame([], $this->exposures($user), "{$arm}: the member saw no change, so there is no row");
        }

        // A digest with something to name, for the same members, is counted.
        $user = $this->member();
        $this->publish($user, 'items');
        $this->digest($this->posts([self::offer('Sofa'), self::offer('Lamp')]), $user);
        $this->assertCount(1, $this->exposures($user));
    }

    // ---------------------------------------------------------------- the whole subject fits

    /**
     * The longest the items subject is allowed to be, names and "and N more" together.
     */
    private const SUBJECT_BUDGET = 50;

    private static function tail(int $more): string
    {
        return $more > 0 ? " and {$more} more" : '';
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function nameAndCount(): array
    {
        $cases = [];
        foreach (['Sofa', 'Wooden bookcase', 'Garden furniture set', str_repeat('A', 25), str_repeat('B', 30)] as $name) {
            foreach ([2, 3, 9, 10, 11, 12, 40, 65] as $count) {
                $cases["{$count} posts named ".substr($name, 0, 12)] = [$name, $count];
            }
        }

        return $cases;
    }

    #[DataProvider('nameAndCount')]
    public function test_the_whole_items_subject_fits_the_budget_and_names_as_many_as_fit(string $name, int $count): void
    {
        $user = $this->member();
        $this->publish($user, 'items');

        $subject = $this->digest($this->posts(array_fill(0, $count, self::offer($name))), $user)->envelope()->subject;

        $this->assertLessThanOrEqual(self::SUBJECT_BUDGET, strlen($subject), 'names and tail together');

        $this->assertSame(1, preg_match('/\A(.+?)(?: and (\d+) more)?\z/', $subject, $m), $subject);
        $shown = count(explode(', ', $m[1]));
        $more = (int) ($m[2] ?? 0);
        $this->assertSame($count, $shown + $more, 'every post is either named or counted');

        // The name is cut at 25 bytes, with "..." after 22.
        $one = strlen($name) > 25 ? substr($name, 0, 22).'...' : $name;
        $length = fn (int $k) => $k * strlen($one) + 2 * ($k - 1) + strlen(self::tail($count - $k));
        $this->assertSame($length($shown), strlen($subject));
        for ($more_names = $shown + 1; $more_names <= $count; $more_names++) {
            $this->assertGreaterThan(self::SUBJECT_BUDGET, $length($more_names), "{$more_names} names would not fit");
        }
    }

    public function test_a_three_digit_tail_still_fits(): void
    {
        // A name of 25 bytes with " and 999 more" is 38 bytes: the tail is never the reason for no name.
        $this->assertLessThanOrEqual(self::SUBJECT_BUDGET, 25 + strlen(self::tail(999)));
    }

    // ---------------------------------------------------------------- a digest of one post is not in the experiment

    public function test_a_digest_of_one_post_is_not_in_the_experiment(): void
    {
        $user = $this->member();
        $this->publish($user, 'items');
        $reads = 0;
        DB::listen(function ($query) use (&$reads) {
            if (in_array(UxArm::SWITCH_KEY, $query->bindings, true)) {
                $reads++;
            }
        });

        $one = $this->digest($this->posts([self::offer('Sofa')]), $user);

        $this->assertSame("What's New (1 post) - Sofa", $one->envelope()->subject);
        $this->assertSame([], $this->exposures($user), 'no row for a digest that is not in the experiment');
        $this->assertSame(0, $reads, 'and no look at the experiment');

        // The same member, with two posts, is in it.
        $two = $this->digest($this->posts([self::offer('Sofa'), self::offer('Lamp')]), $user);

        $this->assertSame('Sofa, Lamp', $two->envelope()->subject);
        $this->assertCount(1, $this->exposures($user));
    }

    public function test_a_digest_of_one_post_is_left_out_in_the_control_arm_too(): void
    {
        $user = $this->member();
        $this->publish($user, 'control');

        $this->digest($this->posts([self::offer('Sofa')]), $user);
        $this->assertSame([], $this->exposures($user));

        $this->digest($this->posts([self::offer('Sofa'), self::offer('Lamp')]), $user);
        $this->assertCount(1, $this->exposures($user));
    }

    // ---------------------------------------------------------------- the exposure

    public function test_each_arm_writes_one_row_after_the_tracking_row(): void
    {
        $posts = $this->posts(self::cases()['B, all named'][0]);
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        foreach (['control', 'items'] as $arm) {
            $user = $this->member();
            $this->publish($user, $arm);   // forgets the copy of the config rows, so the read below is issued
            $statements = [];

            $mail = $this->digest($posts, $user);

            $rows = $this->exposures($user);
            $this->assertCount(1, $rows, $arm);
            $this->assertSame(self::NAME, $rows[0]->experiment);
            $this->assertSame($arm, $rows[0]->arm);
            $this->assertSame('email', $rows[0]->subject_type, $arm);
            $this->assertSame($mail->getTracking()->id, (int) $rows[0]->subject_id, $arm);
            $this->assertNull($rows[0]->stratum);
            $this->assertNull($rows[0]->detail);
            $this->assertSame('2026-10-20 10:00:00', $rows[0]->exposed_at, $arm);

            $position = function (string $needle) use (&$statements) {
                foreach ($statements as $i => $sql) {
                    if (str_contains($sql, $needle)) {
                        return $i;
                    }
                }

                return null;
            };
            $read = $position('from `config` where `key` in');
            $tracking = $position('insert into `email_tracking`');
            $exposure = $position('into `ux_experiment_exposures`');
            $this->assertNotNull($read, "$arm: the config rows were read");
            $this->assertNotNull($tracking, "$arm: the tracking row was written");
            $this->assertNotNull($exposure, "$arm: the exposure was written");
            $this->assertLessThan($tracking, $read, "$arm: the arm is looked up before the tracking row, because the row holds the subject");
            $this->assertLessThan($exposure, $tracking, "$arm: the exposure points at the tracking row, so it comes after it");
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function waysToWriteNoRow(): array
    {
        return [
            'the switch is 0' => ['switch 0'],
            'the experiment is not running' => ['not running'],
            'the entry has expired' => ['expired'],
            'the mail is immediate' => ['immediate'],
            'email tracking is off' => ['tracking off'],
        ];
    }

    #[DataProvider('waysToWriteNoRow')]
    public function test_no_row_is_written_without_an_arm_or_a_tracking_row(string $way): void
    {
        $posts = $this->posts(self::cases()['B, all named'][0]);
        $user = $this->member();
        $mode = UnifiedDigestService::MODE_DAILY;
        $reads = 0;

        switch ($way) {
            case 'switch 0':
                $this->publish($user, 'items', [], '0');
                break;
            case 'not running':
                break;
            case 'expired':
                $this->publish($user, 'items', ['until' => Carbon::now()->getTimestamp() - 1]);
                break;
            case 'immediate':
                // Two posts: the mode alone keeps this mail out of the experiment, not the count of posts.
                $this->publish($user, 'items');
                $mode = UnifiedDigestService::MODE_IMMEDIATE;
                $posts = $this->posts([self::offer('Sofa'), self::offer('Lamp')]);
                break;
            case 'tracking off':
                $this->publish($user, 'items');
                config(['freegle.mail.tracking_enabled' => false]);
                DB::listen(function ($query) use (&$reads) {
                    if (in_array(UxArm::SWITCH_KEY, $query->bindings, true)) {
                        $reads++;
                    }
                });
                break;
        }

        $mail = $this->digest($posts, $user, $mode);

        $this->assertSame([], $this->exposures($user), $way);
        if ($way === 'tracking off') {
            $this->assertNull($mail->getTracking());
            $this->assertSame(0, DB::table('email_tracking')->where('userid', $user->id)->count());
            $this->assertSame(0, $reads, 'and the arm is not even looked up, because nobody could count it');
            $this->assertSame("What's New (3 posts) - Sofa, Lamp, Books", $mail->envelope()->subject);
        }
    }

    public function test_a_failed_exposure_does_not_stop_the_mail(): void
    {
        $posts = $this->posts(self::cases()['A, five named'][0]);
        $user = $this->member();
        $this->publish($user, 'items');
        Log::spy();

        $mail = $this->withoutExposureTable(fn () => $this->digest($posts, $user));

        $this->assertSame('Sofa, Lamp, Books, Chair, Table and 7 more', $mail->envelope()->subject);
        $this->assertNotNull($mail->getTracking());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'UxArm: could not record the exposure for digest-subject'))->once();
    }

    public function test_an_exposure_needs_a_tracking_row(): void
    {
        // The exposure points at the tracking row. A subclass that makes no row stands in for a build with none: there is
        // no exposure and no error, and the mail still has the subject of its arm.
        $user = $this->member();
        $this->publish($user, 'items');
        $posts = $this->posts([self::offer('Sofa'), self::offer('Lamp')]);

        $mail = new class($user, $posts, UnifiedDigestService::MODE_DAILY) extends UnifiedDigest
        {
            protected function initTracking(string $emailType, string $recipientEmail, ?int $userId = null, ?int $groupId = null, ?string $subject = null, ?array $metadata = null, bool $hasAmp = false): void
            {
                $this->tracking = null;
            }
        };

        $this->assertNull($mail->getTracking());
        $this->assertSame([], $this->exposures($user));
        $this->assertSame('Sofa, Lamp', $mail->envelope()->subject);
    }

    public function test_a_rebuilt_digest_gets_the_same_arm_and_subject(): void
    {
        $posts = $this->posts(self::cases()['A, five named'][0]);
        $user = $this->member();
        $this->publish($user, 'items', ['until' => Carbon::now()->getTimestamp() + 3600]);

        $first = $this->digest($posts, $user);
        $rebuilt = UnifiedDigest::rebuildFromDescriptor($first->mailDescriptor());

        $this->assertNotNull($rebuilt);
        $this->assertSame($first->envelope()->subject, $rebuilt->envelope()->subject);
        $rows = $this->exposures($user);
        $this->assertCount(2, $rows, 'a second mail is a second row, and the count takes the first');
        $this->assertNotSame($rows[0]->subject_id, $rows[1]->subject_id);

        // After until the arm is no longer served: the retry is control, and writes no row.
        Carbon::setTestNow('2026-10-20 11:00:01');
        $late = UnifiedDigest::rebuildFromDescriptor($first->mailDescriptor());

        $this->assertNotNull($late);
        $this->assertStringStartsWith("What's New (12 posts)", $late->envelope()->subject);
        $this->assertCount(2, $this->exposures($user));
    }

    public function test_a_retry_with_fewer_than_two_posts_left_is_control_and_writes_no_row(): void
    {
        $posts = $this->posts([self::offer('Sofa'), self::offer('Lamp')]);
        $user = $this->member();
        $this->publish($user, 'items');

        $first = $this->digest($posts, $user);
        $this->assertSame('Sofa, Lamp', $first->envelope()->subject);

        // One of the posts is deleted before the retry.
        $descriptor = $first->mailDescriptor();
        $descriptor['posts'][1]['msgid'] = 999999999;
        $rebuilt = UnifiedDigest::rebuildFromDescriptor($descriptor);

        $this->assertNotNull($rebuilt);
        $this->assertSame("What's New (1 post) - Sofa", $rebuilt->envelope()->subject);
        $this->assertCount(1, $this->exposures($user));
    }
}
