<?php

namespace Tests\Unit\OrmHarness;

use Illuminate\Support\Facades\DB;
use Tests\Support\OrmHarness\GoldenSql;
use Tests\TestCase;

/**
 * Wave 3: PurgeService's chunked DELETEs.
 *
 * These are the sites that showed why the inventory refuses to count deleted
 * SQL as converted. All thirteen were removed from PurgeService and the
 * headline raw count did not move, because mergeForward re-adds any site whose
 * id has vanished with presentInCode=false - "a deleted call site and a
 * converted one look identical to an extractor". Only a golden naming the site
 * id promotes it. This file is that golden.
 *
 * Each raw DELETE sat directly beneath a count() query in the same method that
 * already expressed the identical predicate as a builder chain, so the
 * conversion was copying the sibling and appending ->limit()->delete(). The
 * golden is what proves the copy was faithful - in particular that the OR
 * groupings survived, since a flattened OR on a DELETE widens it across the
 * whole table.
 */
class Wave3PurgeTest extends TestCase
{
    private const CHUNK = 500;

    private const SITE_LOGIN_LOGOUT = 'b7fe22c9f927';
    private const SITE_USER_DELETED = 'ee112faa8fb6';
    private const SITE_USER_CREATED = 'e8216202fee1';
    private const SITE_BLANK_SUBTYPE = 'bfd91e927d85';
    private const SITE_USER_BOUNCE = '06a0736afd38';
    private const SITE_BOUNCES_EMAILS = 'af5bd3f5ecb1';
    private const SITE_LOGS_EMAILS = '0f525ed7d096';
    private const SITE_LOGS_BY_GROUP = 'f88d2c32b9f2';
    private const SITE_LOGS_SRC = 'd3c47e835355';
    private const SITE_LOGS_ERRORS = '978d17107d12';
    private const SITE_LOGS_PLUGIN = 'a0abe6e079a4';
    private const SITE_LOGS_SQL = '91a2a946f8f3';
    private const SITE_USERS_ACTIVE = '6f40faa9e18c';

    private const CUTOFF = '2026-01-01 00:00:00';

    /**
     * The grouped OR is the whole point of this one. Written flat as
     * ->where('subtype','Login')->orWhere('subtype','Logout') the OR would bind
     * against the type predicate too, and the DELETE would take every Logout
     * row in the table regardless of type or timestamp.
     */
    public function test_login_logout_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGIN_LOGOUT, fn () => DB::table('logs')
            ->where('type', 'User')
            ->where(function ($q) {
                $q->where('subtype', 'Login')->orWhere('subtype', 'Logout');
            })
            ->where('timestamp', '<', self::CUTOFF)
            ->limit(self::CHUNK));
    }

    public function test_user_deleted_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_USER_DELETED, fn () => DB::table('logs')
            ->where('type', 'User')->where('subtype', 'Deleted')
            ->where('timestamp', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_user_created_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_USER_CREATED, fn () => DB::table('logs')
            ->where('type', 'User')->where('subtype', 'Created')
            ->where('timestamp', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    /** The OR is on `type` here, again grouped away from the subtype filter. */
    public function test_blank_subtype_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_BLANK_SUBTYPE, fn () => DB::table('logs')
            ->where(function ($q) {
                $q->where('type', 'User')->orWhere('type', 'Group');
            })
            ->where('subtype', '')
            ->where('timestamp', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_user_bounce_logs(): void
    {
        GoldenSql::assertDelete(self::SITE_USER_BOUNCE, fn () => DB::table('logs')
            ->where('type', 'User')->where('subtype', 'Bounce')
            ->where('timestamp', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_bounces_emails(): void
    {
        GoldenSql::assertDelete(self::SITE_BOUNCES_EMAILS, fn () => DB::table('bounces_emails')
            ->where('date', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    /**
     * A genuinely flat OR, unlike the two above: rows outside the retained
     * window in either direction, with no third predicate for it to bind
     * against. The golden holds the distinction.
     */
    public function test_logs_emails_outside_window(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_EMAILS, fn () => DB::table('logs_emails')
            ->where('timestamp', '<', self::CUTOFF)
            ->orWhere('timestamp', '>', '2027-01-01 00:00:00')
            ->limit(self::CHUNK));
    }

    public function test_logs_by_group(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_BY_GROUP, fn () => DB::table('logs')
            ->where('timestamp', '<', self::CUTOFF)->where('groupid', 1)
            ->limit(self::CHUNK));
    }

    public function test_logs_src(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_SRC, fn () => DB::table('logs_src')
            ->where('date', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_logs_errors(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_ERRORS, fn () => DB::table('logs_errors')
            ->where('date', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_logs_plugin(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_PLUGIN, fn () => DB::table('logs')
            ->where('timestamp', '<', self::CUTOFF)->where('type', 'Plugin')
            ->limit(self::CHUNK));
    }

    public function test_logs_sql(): void
    {
        GoldenSql::assertDelete(self::SITE_LOGS_SQL, fn () => DB::table('logs_sql')
            ->where('date', '<', self::CUTOFF)->limit(self::CHUNK));
    }

    public function test_users_active(): void
    {
        GoldenSql::assertDelete(self::SITE_USERS_ACTIVE, fn () => DB::table('users_active')
            ->where('timestamp', '<', self::CUTOFF)->limit(self::CHUNK));
    }
}
