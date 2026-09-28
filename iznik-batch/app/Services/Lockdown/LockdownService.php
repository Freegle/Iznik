<?php

namespace App\Services\Lockdown;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lockdown switch's state, as the batch sees it.
 *
 * `lockdowns` is append-only: every change is a new row and the current state is the
 * newest one. current() reads through a five-second in-process cache (section 11.6), the
 * same as the Go API's own cache, so a per-item gate check - once per recipient, per chat
 * message, per post, per push - costs a memory read rather than a query. Any write made
 * through this service (press/setSurfaces/setNotice/setPhrases/close) flushes the cache
 * immediately, so a press is always felt at once by whatever made it and by every other
 * caller in the same process; the TTL only bounds how stale a read can be of a change made
 * some other way, such as the Go API writing the row directly.
 *
 * The cache is a static property, not a per-instance one, because a batch command is
 * normally its own OS process (the natural scope for an "in-process" cache) but may still
 * construct more than one LockdownService instance within it - every instance must see the
 * same state.
 *
 * A failed read keeps the last state this process saw. A process that has never read the
 * state treats the site as open; that only happens with the database unreachable, when
 * nothing is moving anyway.
 */
class LockdownService
{
    /** Surfaces the switch can hold, in the order they are lifted. */
    public const SURFACES = ['mods', 'chat', 'posts', 'chitchat', 'events', 'push', 'email', 'export'];

    /**
     * The batch loops that call ack() (section 11.6), in the order they appear in the plan.
     * Named here once so lockdown:report can list every loop, including one that has never
     * ticked over at all since the press (no lockdown_acks row yet).
     */
    public const ACK_LOOPS = [
        'chat-process', 'content-check', 'auto-approve', 'mail-spool',
        'mail-loops', 'background-tasks', 'push', 'triage',
    ];

    public const CHAT_HARD = 'hard';

    public const CHAT_SOFT = 'soft';

    public const NOTICES = [
        'delay' => 'Freegle is running slowly today. Messages and posts may take longer than usual to reach people.',
        'security' => "We're dealing with a spam attack. Messages may be delayed. If you received a message about vouchers or payments, please don't click the link.",
        'normal' => 'Things are back to normal.',
    ];

    /**
     * How long a read is trusted before the next current() call queries again (section
     * 11.6: "the same as Go"). This is the whole reason a per-item gate check is cheap
     * enough to run on every recipient/message/post instead of once per job. Public so
     * tests can advance Carbon's fake clock past it without repeating the number.
     */
    public const CACHE_TTL_SECONDS = 5;

    /** The last row read; false until a read has succeeded. Shared process-wide - see class doc. */
    private static object|false $cachedRow = false;

    /** When $cachedRow was last set by a real read; null means "never, or just flushed". */
    private static ?\Illuminate\Support\Carbon $cachedAt = null;

    /** Rows already acknowledged by this process, keyed by loop name (section 11.6): a
     *  write per state change, not per item. */
    private static array $ackedRows = [];

    /**
     * The newest row, from the cache if it is still fresh, otherwise read now. Null when
     * there has never been a lockdown, or when this process has never managed to read the
     * state.
     */
    public function current(): ?object
    {
        // Plain timestamp subtraction rather than diffInSeconds(): unambiguous regardless of
        // Carbon's default sign/direction, and respects Carbon::setTestNow() the same way.
        if (self::$cachedAt !== null && (now()->getTimestamp() - self::$cachedAt->getTimestamp()) < self::CACHE_TTL_SECONDS) {
            return self::$cachedRow ?: null;
        }

        try {
            self::$cachedRow = $this->readNewest() ?? false;
            self::$cachedAt = now();
        } catch (\Throwable $e) {
            Log::warning('Lockdown: could not read state, keeping the last one', ['error' => $e->getMessage()]);
        }

        return self::$cachedRow ?: null;
    }

    /**
     * Forces the next current() call (from any instance in this process) to read the
     * database again, and forgets which rows this process has already acked. Every write
     * below calls this itself. Tests call it after changing `lockdowns` some other way (a
     * direct DB write, or between test cases sharing one process) so the next read is not
     * served from a previous case's cache.
     */
    public static function flushCache(): void
    {
        self::$cachedRow = false;
        self::$cachedAt = null;
        self::$ackedRows = [];
    }

    public function active(): bool
    {
        $row = $this->current();

        return $row !== null && (int) $row->active === 1;
    }

    public function held(string $surface): bool
    {
        $row = $this->current();
        if ($row === null || (int) $row->active !== 1) {
            return false;
        }

        return (bool) ($this->surfacesOf($row)[$surface] ?? false);
    }

    public function chatMode(): string
    {
        $row = $this->current();

        return $row ? ($this->surfacesOf($row)['chat_mode'] ?? self::CHAT_HARD) : self::CHAT_HARD;
    }

    /**
     * The incident the newest row belongs to, active or closed. Null if there has never been one.
     */
    public function incidentId(): ?int
    {
        $row = $this->current();

        return $row ? (int) $row->incidentid : null;
    }

    public function phrases(): array
    {
        $row = $this->current();

        return $row && $row->phrases ? (json_decode($row->phrases, true) ?: []) : [];
    }

    /**
     * Count something refused or not sent, against the active incident.
     */
    public function count(string $kind, int $by = 1): void
    {
        if (!$this->active()) {
            return;
        }

        try {
            DB::table('lockdown_counters')->upsert(
                [['lockdownid' => $this->incidentId(), 'kind' => substr($kind, 0, 64), 'count' => $by]],
                ['lockdownid', 'kind'],
                ['count' => DB::raw('count + VALUES(count)')]
            );
        } catch (\Throwable $e) {
            // A lost counter costs a number in the report; it must never cost the loop.
            Log::warning('Lockdown: could not count', ['kind' => $kind, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Press the switch: every surface held, chat hard.
     */
    public function press(?int $by, ?string $reason, ?string $notice = null): int
    {
        if ($this->active()) {
            throw new \RuntimeException('A lockdown is already active.');
        }
        $this->validateNotice($notice);

        $surfaces = array_fill_keys(self::SURFACES, true);
        $surfaces['chat_mode'] = self::CHAT_HARD;

        return DB::transaction(function () use ($by, $reason, $notice, $surfaces) {
            $id = DB::table('lockdowns')->insertGetId([
                'active' => 1,
                'surfaces' => json_encode($surfaces),
                'reason' => $reason,
                'notice' => $notice,
                'phrases' => json_encode([]),
                'changedby' => $by,
                'startedby' => $by,
                'startedat' => now(),
            ]);
            DB::table('lockdowns')->where('id', $id)->update(['incidentid' => $id]);
            self::flushCache();

            return $id;
        });
    }

    /**
     * Hold or lift any subset of surfaces, and switch chat between hard and soft.
     */
    public function setSurfaces(array $changes, ?int $by): int
    {
        $row = $this->requireActive();
        $surfaces = $this->surfacesOf($row);

        foreach ($changes as $key => $value) {
            if ($key === 'chat_mode') {
                if (!in_array($value, [self::CHAT_HARD, self::CHAT_SOFT], true)) {
                    throw new \InvalidArgumentException("Unknown chat mode $value");
                }
                $surfaces['chat_mode'] = $value;
            } elseif (in_array($key, self::SURFACES, true)) {
                $surfaces[$key] = (bool) $value;
            } else {
                throw new \InvalidArgumentException("Unknown surface $key");
            }
        }

        return $this->append($row, ['surfaces' => json_encode($surfaces)], $by);
    }

    public function setNotice(?string $notice, ?int $by): int
    {
        $this->validateNotice($notice);

        return $this->append($this->requireCurrent(), ['notice' => $notice], $by);
    }

    public function setPhrases(array $phrases, ?int $by): int
    {
        $clean = array_values(array_unique(array_filter(array_map(
            fn ($p) => mb_strtolower(trim((string) $p)),
            $phrases
        ))));

        return $this->append($this->requireActive(), ['phrases' => json_encode($clean)], $by);
    }

    /**
     * End the incident. Surfaces all lifted, incident phrases cleared.
     */
    public function close(?int $by, ?string $note): int
    {
        $row = $this->requireActive();
        $surfaces = array_fill_keys(self::SURFACES, false);
        $surfaces['chat_mode'] = $this->surfacesOf($row)['chat_mode'] ?? self::CHAT_HARD;

        return $this->append($row, [
            'active' => 0,
            'surfaces' => json_encode($surfaces),
            'phrases' => json_encode([]),
            'endedby' => $by,
            'endedat' => now(),
            'endnote' => $note,
        ], $by);
    }

    /**
     * Record that a batch loop has acted on the current lockdowns row. GET
     * /modtools/lockdown/stats reads lockdown_acks to show the presser each loop ticking
     * over, and how long it took. Writes once per row id per loop per process (section
     * 11.6: "a write per state change, not per item") - cheap enough to call from inside a
     * per-item gate check without an extra query on every item.
     *
     * $loop is one of the names in the plan: chat-process, content-check, auto-approve,
     * mail-spool, mail-loops, background-tasks, push, triage.
     */
    public function ack(string $loop): void
    {
        $row = $this->current();
        $rowId = $row ? (int) $row->id : null;
        if ($rowId === null) {
            // Nothing has ever been pressed - nothing to acknowledge.
            return;
        }

        if ((self::$ackedRows[$loop] ?? null) === $rowId) {
            // This process already acked this exact row for this loop.
            return;
        }

        try {
            DB::table('lockdown_acks')->upsert(
                [['loop' => $loop, 'lockdownrowid' => $rowId, 'seenat' => now()]],
                ['loop'],
                ['lockdownrowid', 'seenat']
            );
            self::$ackedRows[$loop] = $rowId;
        } catch (\Throwable $e) {
            // A missed ack only delays the presser's "taking effect" ticks, never the loop.
            Log::warning('Lockdown: could not ack', ['loop' => $loop, 'error' => $e->getMessage()]);
        }
    }

    public static function noticeText(?string $notice): ?string
    {
        return $notice ? (self::NOTICES[$notice] ?? null) : null;
    }

    public function surfacesOf(object $row): array
    {
        $decoded = $row->surfaces ? json_decode($row->surfaces, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    protected function readNewest(): ?object
    {
        return DB::table('lockdowns')->orderByDesc('id')->first();
    }

    private function append(object $previous, array $changes, ?int $by): int
    {
        $row = array_merge([
            'incidentid' => $previous->incidentid,
            'active' => $previous->active,
            'surfaces' => $previous->surfaces,
            'reason' => $previous->reason,
            'notice' => $previous->notice,
            'phrases' => $previous->phrases,
            'startedby' => $previous->startedby,
            'startedat' => $previous->startedat,
            'endedby' => $previous->endedby,
            'endedat' => $previous->endedat,
            'endnote' => $previous->endnote,
        ], $changes, ['changedby' => $by]);

        $id = DB::table('lockdowns')->insertGetId($row);
        self::flushCache();

        return $id;
    }

    private function requireCurrent(): object
    {
        $row = $this->current();
        if ($row === null) {
            throw new \RuntimeException('There has never been a lockdown.');
        }

        return $row;
    }

    private function requireActive(): object
    {
        $row = $this->current();
        if ($row === null || (int) $row->active !== 1) {
            throw new \RuntimeException('No lockdown is active.');
        }

        return $row;
    }

    private function validateNotice(?string $notice): void
    {
        if ($notice !== null && !array_key_exists($notice, self::NOTICES)) {
            throw new \InvalidArgumentException("Unknown notice $notice");
        }
    }
}
