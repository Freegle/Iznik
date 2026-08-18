<?php

namespace App\Services\Mail;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The gate: may we generate mail for this address right now?
 *
 * Called from inside every per-recipient sending loop, before the MJML render
 * rather than at the point of sending, because rendering is where the money
 * goes. During the 2026-08-15 Yahoo episode we spent three days rendering
 * roughly 67,000 digests a day, plus per-post mail, for addresses that
 * provably could not receive any of it.
 *
 * Answers come from `mail_suppressions`. Domain rows are materialised from
 * queue evidence when a relay family is suppressed, so this method is two
 * indexed lookups and never a DNS query - it has to be cheap enough to run
 * hundreds of thousands of times an hour.
 *
 * Deliberately separate from users.bouncing, which means "this address is
 * bad". This is our reputation with a provider, which is a different fact
 * about a different party, and conflating them would show moderators the
 * wrong story and leave members permanently flagged after an outage that was
 * never their fault.
 */
class MailSuppressionService
{
    public const SCOPE_MXGROUP = 'mxgroup';

    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_ADDRESS = 'address';

    /**
     * Active suppressions, keyed "scope\0value".
     *
     * Loaded once per process. The sending loops are long-running batch jobs
     * over tens of thousands of members, and a per-recipient query would cost
     * more than the render we are trying to avoid. The cost of being at most
     * one refresh window stale is one extra mail into a queue that is already
     * holding thousands.
     *
     * @var array<string, object>|null
     */
    private ?array $cache = null;

    private ?float $cacheLoadedAt = null;

    /** Seconds before the in-process cache is reloaded. */
    private const CACHE_TTL = 60.0;

    /**
     * Whether we should decline to generate mail for this address.
     */
    public function isSuppressed(?string $email): bool
    {
        return $this->suppressionFor($email) !== null;
    }

    /**
     * The suppression covering this address, or null.
     *
     * Returns the row so callers can report what is happening: the "delayed
     * since" date and the provider's name both come from here.
     */
    public function suppressionFor(?string $email): ?object
    {
        $email = $this->normalise($email);
        if ($email === null) {
            return null;
        }

        $active = $this->active();

        // Most specific first: an individual mailbox over quota is a
        // different problem from its provider deferring everyone, and the
        // address row carries the more accurate reason.
        $byAddress = $active[self::SCOPE_ADDRESS."\0".$email] ?? null;
        if ($byAddress !== null) {
            return $byAddress;
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }

        $domain = substr($email, $at + 1);

        return $active[self::SCOPE_DOMAIN."\0".$domain] ?? null;
    }

    /**
     * Record that we declined to generate one mail, so release can send a
     * single catch-up instead of replaying the backlog.
     *
     * $emailType uses the same vocabulary as EmailSpoolerService::spool(), so
     * the catch-up policy can be written in the same terms as the mail it
     * stands in for.
     */
    public function recordSuppressed(?int $userId, string $emailType, ?int $suppressionId = null): void
    {
        if ($userId === null || $userId <= 0) {
            return;
        }

        try {
            DB::statement(
                'INSERT INTO mail_suppressed_counts (userid, emailtype, suppressionid, `count`, firstat, lastat)
                 VALUES (?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `count` = `count` + 1,
                    lastat = NOW(),
                    suppressionid = VALUES(suppressionid),
                    -- A fresh episode after a catch-up starts counting again
                    -- rather than resuming the old total.
                    firstat = IF(caughtup_at IS NULL, firstat, NOW()),
                    `count` = IF(caughtup_at IS NULL, `count`, 1),
                    caughtup_at = NULL',
                [$userId, substr($emailType, 0, 32), $suppressionId]
            );
        } catch (\Throwable $e) {
            // Losing a counter costs us accuracy in one catch-up email. It
            // must never cost us the send loop.
            Log::warning('Mail suppression: could not record suppressed count', [
                'userid' => $userId,
                'emailtype' => $emailType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convenience for the sending loops: check, and count it if suppressed.
     *
     * Returns true when the caller should skip this recipient.
     */
    public function shouldSkip(?string $email, ?int $userId, string $emailType): bool
    {
        if (! $this->isSuppressed($email)) {
            return false;
        }

        $this->recordSuppressed($userId, $emailType);

        return true;
    }

    /**
     * Drop the in-process cache. Called by the scan after it changes the
     * suppression set, and by tests.
     */
    public function flushCache(): void
    {
        $this->cache = null;
        $this->cacheLoadedAt = null;
    }

    /**
     * @return array<string, object>
     */
    private function active(): array
    {
        $now = microtime(true);

        if ($this->cache !== null && $this->cacheLoadedAt !== null
            && ($now - $this->cacheLoadedAt) < self::CACHE_TTL) {
            return $this->cache;
        }

        try {
            $rows = DB::table('mail_suppressions')
                ->select(['id', 'scope', 'value', 'parentid', 'reason', 'provider', 'deferred_since', 'message_count'])
                ->whereNull('released_at')
                ->whereIn('scope', [self::SCOPE_DOMAIN, self::SCOPE_ADDRESS])
                ->get();
        } catch (\Throwable $e) {
            // If we cannot read the table we must fail OPEN. A suppression
            // bug that silently stops all mail would be far worse than the
            // problem it was written to solve.
            Log::error('Mail suppression: could not load suppressions, failing open', [
                'error' => $e->getMessage(),
            ]);

            $this->cache = [];
            $this->cacheLoadedAt = $now;

            return $this->cache;
        }

        $map = [];
        foreach ($rows as $row) {
            $map[$row->scope."\0".strtolower((string) $row->value)] = $row;
        }

        $this->cache = $map;
        $this->cacheLoadedAt = $now;

        return $map;
    }

    private function normalise(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }
}
