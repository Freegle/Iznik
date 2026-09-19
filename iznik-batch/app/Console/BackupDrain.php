<?php

namespace App\Console;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Holds batch work off while the nightly database backup runs.
 *
 * The backup takes a physical XtraBackup copy with `wsrep_desync = ON`. Desync is not
 * replication lag: Galera is virtually synchronous, and desync deliberately takes the node
 * out of flow control so the cluster stops waiting for it and it falls behind for the
 * duration. Measured 18 September 2026, that duration is about eighteen minutes.
 *
 * While the backup ran on a node nothing reads from, none of that mattered. Under the
 * agreed two-node topology it moves to the node that serves bulk reads, so batch work has
 * to be held off for the window: partly so the backup is not competing for I/O, and partly
 * because `wsrep_sync_wait` is 0, so nothing makes a reader on the desynced node wait for
 * it to catch up.
 *
 * Design notes:
 *
 * - **Off by default.** Merging this changes nothing until BACKUP_DRAIN_ENABLED is set in
 *   the batch environment. Same shape as the schedule profile and the read/write split.
 * - **A bad value fails open**, never closed. An unparseable start time or a non-positive
 *   duration leaves the drain off rather than holding the entire batch schedule back
 *   indefinitely, which is how the schedule profile treats a typo.
 * - **The window is decided per tick**, inside the filter closure, not when
 *   routes/console.php is evaluated.
 *
 * Set `start` earlier than the backup's own cron so in-flight jobs have time to finish
 * before it begins. That gap is the drain; the rest of the window is the delay.
 */
class BackupDrain
{
    /**
     * Events already given a filter, so a repeated apply() does not stack them up.
     *
     * A WeakMap, not an SplObjectStorage: the store must not be what keeps an Event alive.
     * routes/console.php calls apply() as the console routes load, which happens on every
     * application boot, and the schedule has over 150 events. Holding each one strongly meant
     * a test suite retained every event of every boot, along with the closures and container
     * they reference, until PHP ran out of memory partway through a run.
     */
    private static ?\WeakMap $applied = null;

    /**
     * Is batch work being held off right now?
     */
    public static function active(?CarbonInterface $at = null): bool
    {
        $config = config('freegle.backup.drain', []);

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $minutes = (int) ($config['minutes'] ?? 0);
        if ($minutes <= 0) {
            return false;
        }

        $tz = config('app.timezone') ?: 'UTC';
        $now = $at ? Carbon::parse($at)->setTimezone($tz) : Carbon::now($tz);

        $start = self::startOn($now, (string) ($config['start'] ?? ''), $tz);
        if ($start === null) {
            return false;
        }

        // Today's window, and yesterday's in case it runs through midnight.
        foreach ([$start, $start->copy()->subDay()] as $from) {
            if ($now->greaterThanOrEqualTo($from) && $now->lessThan($from->copy()->addMinutes($minutes))) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the current or next window ends, for callers that want to report a wait.
     */
    public static function endsAt(?CarbonInterface $at = null): ?Carbon
    {
        if (! self::active($at)) {
            return null;
        }

        $config = config('freegle.backup.drain', []);
        $tz = config('app.timezone') ?: 'UTC';
        $now = $at ? Carbon::parse($at)->setTimezone($tz) : Carbon::now($tz);
        $minutes = (int) ($config['minutes'] ?? 0);

        $start = self::startOn($now, (string) ($config['start'] ?? ''), $tz);
        if ($start === null) {
            return null;
        }

        foreach ([$start, $start->copy()->subDay()] as $from) {
            $end = $from->copy()->addMinutes($minutes);
            if ($now->greaterThanOrEqualTo($from) && $now->lessThan($end)) {
                return $end;
            }
        }

        return null;
    }

    /**
     * Give every scheduled command a filter that holds it off during the window.
     *
     * Call this once, after routes/console.php has defined its commands, so a job cannot
     * be forgotten. Commands named in `always_run` are left alone.
     */
    public static function apply(Schedule $schedule): void
    {
        self::$applied ??= new \WeakMap();

        $always = array_values(array_filter(array_map(
            'strval',
            (array) config('freegle.backup.drain.always_run', [])
        )));

        foreach ($schedule->events() as $event) {
            if (isset(self::$applied[$event])) {
                continue;
            }

            self::$applied[$event] = true;

            if (self::exempt($event, $always)) {
                continue;
            }

            // Evaluated on each due-check, so the answer tracks the clock.
            $event->skip(static fn (): bool => self::active());
        }
    }

    /**
     * Events that must keep running through the window whatever the config says.
     *
     * Both carry Sentry Crons check-ins (->sentryMonitor() in routes/console.php), and
     * Sentry raises a missed-check-in issue after two consecutive misses. A 45-minute hold
     * is nine missed heartbeats, so holding them off would page every night about a
     * scheduler that is fine. Neither touches the database in a way the drain cares
     * about: the heartbeat does nothing at all, and the outcome monitor runs a few
     * aggregate queries whose cursor checks know about the drain (BacklogCheck).
     */
    private const KEEPS_RUNNING = ['scheduler-heartbeat', 'monitor:scheduled-outcomes'];

    /**
     * Is this event left alone by the drain?
     *
     * The backup's own commands are never held off. The window exists FOR the backup, so
     * holding it back would deadlock the whole arrangement. Structural rather than left to
     * the safelist, because getting this wrong in config would silently stop backups.
     * The Sentry-monitored events in KEEPS_RUNNING are structural for the same reason.
     * Anything in `always_run` is left alone too, matched on the artisan command name or,
     * for a scheduled closure, on its ->name().
     *
     * Public so BackupDrainWindowTest can ask the same question apply() does, rather than
     * keeping its own copy of the rule.
     *
     * @param  string[]|null  $always  the safelist; read from config when not given
     */
    public static function exempt(Event $event, ?array $always = null): bool
    {
        $always ??= array_values(array_filter(array_map(
            'strval',
            (array) config('freegle.backup.drain.always_run', [])
        )));

        $name = self::commandName($event);
        $label = trim((string) $event->description);

        if ($name !== null && str_starts_with($name, 'backup:')) {
            return true;
        }

        foreach (array_merge(self::KEEPS_RUNNING, $always) as $keep) {
            if ($keep !== '' && ($name === $keep || $label === $keep)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Was batch work held off at any point in the last $minutes, including right now?
     *
     * For checks that measure how long work has been waiting: a backlog that is $minutes
     * old is a stuck worker on any other night, and the drain working as intended on this
     * one. Answers true from the start of the window until $minutes after it closes.
     */
    public static function heldOffWithin(int $minutes, ?CarbonInterface $at = null): bool
    {
        if (self::active($at)) {
            return true;
        }

        $config = config('freegle.backup.drain', []);

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $length = (int) ($config['minutes'] ?? 0);
        if ($length <= 0) {
            return false;
        }

        $tz = config('app.timezone') ?: 'UTC';
        $now = $at ? Carbon::parse($at)->setTimezone($tz) : Carbon::now($tz);

        $start = self::startOn($now, (string) ($config['start'] ?? ''), $tz);
        if ($start === null) {
            return false;
        }

        $since = $now->copy()->subMinutes(max(0, $minutes));

        foreach ([$start, $start->copy()->subDay()] as $from) {
            $end = $from->copy()->addMinutes($length);
            if ($end->greaterThan($since) && $from->lessThanOrEqualTo($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Only used by tests, so one test's schedule cannot leak into the next.
     */
    public static function forget(): void
    {
        self::$applied = null;
    }

    /**
     * The artisan command name inside a scheduled event, e.g. "ripple:expand".
     *
     * Schedule::command() builds a full shell line ("'php' 'artisan' name --opt"), so the
     * name is the token after "artisan". Returns null for anything that is not an artisan
     * command, such as a scheduled shell job.
     */
    private static function commandName(Event $event): ?string
    {
        $raw = trim(str_replace("'", '', (string) $event->command));
        if ($raw === '') {
            return null;
        }

        $parts = preg_split('/\s+/', $raw) ?: [];
        $index = array_search('artisan', $parts, true);

        if ($index === false) {
            return null;
        }

        return $parts[$index + 1] ?? null;
    }

    /**
     * The window's start on the given day, or null if the configured time is not HH:MM.
     */
    private static function startOn(CarbonInterface $day, string $raw, string $tz): ?Carbon
    {
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($raw), $m)) {
            return null;
        }

        return Carbon::create($day->year, $day->month, $day->day, (int) $m[1], (int) $m[2], 0, $tz);
    }
}
