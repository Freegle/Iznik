<?php

namespace Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The query builder's JSON predicates, as the code uses them on users.settings and memberships.settings,
 * pick the same rows on MySQL and PostgreSQL. The columns are text holding JSON, not a JSON type. These run
 * on whichever engine the suite is on, with the same expected rows, so a difference between the engines
 * fails one of the two runs.
 */
class JsonPredicateBehaviourTest extends TestCase
{
    /** One user for each shape of settings the predicates have to tell apart. */
    private function users(): array
    {
        $shapes = [
            'null' => null,
            'empty' => '{}',
            'other' => '{"other": 1}',
            'true' => '{"showmod": true}',
            'false' => '{"showmod": false}',
            'one' => '{"showmod": 1}',
            'zero' => '{"showmod": 0}',
            'jsonnull' => '{"showmod": null}',
        ];
        $ids = [];
        foreach ($shapes as $name => $settings) {
            $user = $this->createTestUser();
            DB::table('users')->where('id', $user->id)->update(['settings' => $settings]);
            $ids[$name] = $user->id;
        }

        return $ids;
    }

    /** The names of the users a query picked, in a fixed order. */
    private function picked(array $ids, \Closure $where): array
    {
        $found = DB::table('users')->whereIn('users.id', array_values($ids))->where($where)->pluck('id')->all();
        $names = array_keys(array_filter($ids, fn ($id) => in_array($id, $found)));
        sort($names);

        return $names;
    }

    public function test_a_key_that_is_absent(): void
    {
        $ids = $this->users();

        // A row with no settings at all is picked too: Laravel wraps MySQL's answer in IFNULL(..., 0), so
        // "no document" counts as "no key". A member who has never saved a setting has no settings.
        $this->assertSame(
            ['empty', 'null', 'other'],
            $this->picked($ids, fn ($q) => $q->whereJsonDoesntContainKey('users.settings->showmod'))
        );
    }

    public function test_a_key_that_is_present(): void
    {
        $ids = $this->users();

        $this->assertSame(
            ['false', 'jsonnull', 'one', 'true', 'zero'],
            $this->picked($ids, fn ($q) => $q->whereJsonContainsKey('users.settings->showmod'))
        );
    }

    public function test_a_key_that_is_true(): void
    {
        $ids = $this->users();

        $this->assertSame(['true'], $this->picked($ids, fn ($q) => $q->where('users.settings->showmod', true)));
    }

    public function test_absent_or_true_as_the_mail_jobs_ask_it(): void
    {
        $ids = $this->users();

        // SendAdminCommand, BirthdayService and ReengageContentService: show the member unless they said no.
        $this->assertSame(
            ['empty', 'null', 'other', 'true'],
            $this->picked($ids, fn ($q) => $q->whereJsonDoesntContainKey('users.settings->showmod')
                ->orWhere('users.settings->showmod', true))
        );
    }

    public function test_true_or_one_or_absent_as_the_moderator_notifications_ask_it(): void
    {
        $ids = $this->users();

        // ModNotifService asks this of memberships.settings->active; the shape is the same.
        $this->assertSame(
            ['empty', 'null', 'one', 'other', 'true'],
            $this->picked($ids, fn ($q) => $q->where('users.settings->showmod', true)
                ->orWhereJsonContains('users.settings->showmod', 1)
                ->orWhereJsonDoesntContainKey('users.settings->showmod'))
        );
    }

    public function test_a_key_that_is_null_or_not(): void
    {
        $ids = $this->users();

        // LapseDonationOptOutsCommand: whereNull finds both a missing key and a JSON null.
        $this->assertSame(
            ['empty', 'jsonnull', 'null', 'other'],
            $this->picked($ids, fn ($q) => $q->whereNull('users.settings->showmod'))
        );
        $this->assertSame(
            ['false', 'one', 'true', 'zero'],
            $this->picked($ids, fn ($q) => $q->whereNotNull('users.settings->showmod'))
        );
    }
}
