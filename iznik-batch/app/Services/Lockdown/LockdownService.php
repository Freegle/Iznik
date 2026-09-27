<?php

namespace App\Services\Lockdown;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The lockdown switch's state, as the batch sees it.
 *
 * `lockdowns` is append-only: every change is a new row and the current state is the
 * newest one. Every loop reads it at the top of each iteration through held(), so a press
 * reaches the long-running spool daemons and the scheduler within one iteration without a
 * restart. The Go API reads the same table through its own five-second cache.
 *
 * A failed read keeps the last state this process saw. A process that has never read the
 * state treats the site as open; that only happens with the database unreachable, when
 * nothing is moving anyway.
 */
class LockdownService
{
    /** Surfaces the switch can hold, in the order they are lifted. */
    public const SURFACES = ['mods', 'chat', 'posts', 'chitchat', 'events', 'push', 'email', 'export'];

    public const CHAT_HARD = 'hard';

    public const CHAT_SOFT = 'soft';

    public const NOTICES = [
        'delay' => 'Freegle is running slowly today. Messages and posts may take longer than usual to reach people.',
        'security' => "We're dealing with a spam attack. Messages may be delayed. If you received a message about vouchers or payments, please don't click the link.",
        'normal' => 'Things are back to normal.',
    ];

    /** The last row read; false until a read has succeeded. */
    private object|false|null $last = false;

    /**
     * The newest row, read now. Null when there has never been a lockdown, or when this
     * process has never managed to read the state.
     */
    public function current(): ?object
    {
        try {
            $this->last = $this->readNewest();
        } catch (\Throwable $e) {
            Log::warning('Lockdown: could not read state, keeping the last one', ['error' => $e->getMessage()]);
        }

        return $this->last ?: null;
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

        return DB::table('lockdowns')->insertGetId($row);
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
