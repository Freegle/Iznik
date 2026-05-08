<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Admin\AdminMail;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAdminCommand extends Command
{
    use AvatarResolver;
    use FeatureFlags;
    use GracefulShutdown;
    use PreventsOverlapping;

    protected $signature = "mail:admin:send
                            {--limit=0 : Max emails per run (0 = unlimited)}
                            {--spool : Spool via EmailSpoolerService for parallel sending}
                            {--dry-run : Count what would be sent without actually sending}
                            {--id= : Send a specific admin by ID (for testing)}";

    protected $description = 'Send approved admin emails to group members';

    private const EMAIL_TYPE = 'Admin';

    /**
     * Days threshold for considering an admin too old to process.
     * Matches V1's "Midnight 7 days ago" filter.
     */
    private const ADMIN_AGE_DAYS = 7;

    /**
     * Days threshold for "active" users (for activeonly admins).
     * Matches V1's Engage::USER_INACTIVE (365 * 24 * 60 * 60 / 2 = ~182.5 days).
     */
    private const USER_INACTIVE_DAYS = 182;

    /**
     * Chunk size for batch-loading User models to avoid N+1 queries.
     */
    private const USER_CHUNK_SIZE = 100;

    public function handle(EmailSpoolerService $spooler): int
    {
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("Admin emails are not enabled. Set FREEGLE_MAIL_ENABLED_TYPES to include 'Admin'.");

            return Command::SUCCESS;
        }

        if (!$this->acquireLock()) {
            $this->warn('Another instance of mail:admin:send is already running.');

            return Command::SUCCESS;
        }

        $this->registerShutdownHandlers();

        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        $useSpool = $this->option('spool');
        $specificId = $this->option('id') ? (int) $this->option('id') : null;

        $stats = [
            'admins_processed' => 0,
            'emails_sent' => 0,
            'skipped_activeonly' => 0,
            'skipped_relevantallowed' => 0,
            'skipped_dedup' => 0,
            'skipped_no_email' => 0,
            'skipped_simplemail' => 0,
            'errors' => 0,
        ];

        $admins = $this->findReadyAdmins($specificId);

        if ($admins->isEmpty()) {
            $this->info('No admin emails ready to send.');

            return Command::SUCCESS;
        }

        $this->info("Found {$admins->count()} admin(s) to process.");

        foreach ($admins as $admin) {
            if ($this->shouldStop()) {
                $this->info('Shutdown signal received, stopping gracefully...');
                break;
            }

            $sent = $this->processAdmin($admin, $stats, $limit, $dryRun, $useSpool, $spooler);
            $stats['admins_processed']++;

            if ($limit > 0 && $stats['emails_sent'] >= $limit) {
                $this->info("Reached email limit of {$limit}.");
                break;
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Admins Processed', $stats['admins_processed']],
                ['Emails Sent', $stats['emails_sent']],
                ['Skipped (active only)', $stats['skipped_activeonly']],
                ['Skipped (relevantallowed)', $stats['skipped_relevantallowed']],
                ['Skipped (dedup)', $stats['skipped_dedup']],
                ['Skipped (no email)', $stats['skipped_no_email']],
                ['Skipped (simplemail=None)', $stats['skipped_simplemail']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("There were {$stats['errors']} errors. Check logs for details.");
        }

        $this->releaseLock();

        return Command::SUCCESS;
    }

    /**
     * Find approved admins ready to send.
     *
     * Mirrors V1's process() query:
     * - complete IS NULL (not yet sent)
     * - pending = 0 (approved)
     * - Created or edited within last 7 days
     * - sendafter has passed (if set)
     */
    protected function findReadyAdmins(?int $specificId = null): \Illuminate\Support\Collection
    {
        $cutoff = now()->subDays(self::ADMIN_AGE_DAYS)->startOfDay();

        $query = DB::table('admins')
            ->whereNull('complete')
            ->where('pending', 0)
            ->where(function ($q) use ($cutoff) {
                $q->where('created', '>=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNotNull('editedat')
                            ->where('editedat', '>', $cutoff);
                    });
            })
            ->where(function ($q) {
                $q->whereNull('sendafter')
                    ->orWhere('sendafter', '<', now());
            });

        if ($specificId) {
            $query->where('id', $specificId);
        }

        return $query->get();
    }

    /**
     * Get local volunteers (active moderators with publish consent) for a group.
     * Matches V1 birthday email logic from Donations.php.
     */
    public static function getLocalVolunteers(int $groupId): array
    {
        $oneYearAgo  = now()->subYear();
        $avatarBase  = rtrim(config('freegle.avatar_server_url', ''), '/');

        $mods = User::select('users.id', 'users.fullname')
            ->join('memberships', 'memberships.userid', '=', 'users.id')
            ->where('memberships.groupid', $groupId)
            ->where('memberships.collection', 'Approved')
            ->whereIn('memberships.role', ['Moderator', 'Owner'])
            ->where('users.lastaccess', '>', $oneYearAgo)
            ->where('users.publishconsent', 1)
            ->whereNull('users.deleted')
            ->limit(100)
            ->get();

        $volunteers = [];
        foreach ($mods as $mod) {
            $firstName = explode(' ', $mod->fullname ?? '')[0];
            if ($firstName) {
                $profileUrl = $mod->getProfileImageUrl()
                    ?? ($avatarBase . '/' . rawurlencode($mod->fullname ?: 'user') . '.png');
                $volunteers[] = [
                    'id' => $mod->id,
                    'displayname' => $mod->fullname,
                    'firstname' => $firstName,
                    'profileurl' => $profileUrl,
                ];
            }
        }

        return $volunteers;
    }

    /**
     * Process a single admin — send to all eligible members of its group.
     */
    protected function processAdmin(
        object $admin,
        array &$stats,
        int $limit,
        bool $dryRun,
        bool $useSpool,
        EmailSpoolerService $spooler
    ): int {
        $sent = 0;

        // Admin must have a group to send to.
        if (!$admin->groupid) {
            Log::warning("Admin {$admin->id} has no groupid, skipping.");

            return 0;
        }

        $group = Group::find($admin->groupid);

        if (!$group) {
            Log::warning("Admin {$admin->id}: group {$admin->groupid} not found.");

            return 0;
        }

        // Only send to active, non-external Freegle groups.
        if ($group->type !== Group::TYPE_FREEGLE || !$group->onhere || !$group->publish || $group->external) {
            Log::info("Admin {$admin->id}: group {$group->id} not an active Freegle group, skipping.");

            return 0;
        }

        $groupName = $group->namefull ?: $group->nameshort;
        $modsEmail = $group->nameshort ? "{$group->nameshort}-volunteers@groups.ilovefreegle.org" : null;

        $this->info("Processing admin {$admin->id} for group {$groupName}...");

        // Pre-load dedup set: all user IDs already sent for this admin's parent (or self).
        // Uses a flipped collection for O(1) lookups instead of per-user DB queries.
        $dedupAdminId = $admin->parentid ?: $admin->id;
        $sentUserIds = DB::table('admins_users')
            ->where('adminid', $dedupAdminId)
            ->pluck('userid')
            ->flip();

        // Get local volunteers for the group footer.
        $volunteers = self::getLocalVolunteers($admin->groupid);

        // Query members with relevantallowed from users table.
        // Note: V1 queries all memberships regardless of collection. We intentionally
        // filter to Approved only to avoid sending to spam-flagged or pending members.
        $members = DB::table('memberships')
            ->join('users', 'users.id', '=', 'memberships.userid')
            ->where('memberships.groupid', $admin->groupid)
            ->where('memberships.collection', 'Approved')
            ->select([
                'memberships.userid',
                'memberships.role',
                'users.relevantallowed',
                'users.lastaccess',
                'users.deleted',
            ])
            ->cursor();

        $activeThreshold = now()->subDays(self::USER_INACTIVE_DAYS);
        $adminArr = (array) $admin;

        $interrupted = FALSE;

        // Buffer members for batch User loading to avoid N+1 queries.
        $memberBuffer = [];

        foreach ($members as $member) {
            if ($this->shouldStop()) {
                $interrupted = TRUE;
                break;
            }

            if ($limit > 0 && $stats['emails_sent'] >= $limit) {
                $interrupted = TRUE;
                break;
            }

            // Apply lightweight filters before buffering (no User model needed).
            if ($member->deleted) {
                continue;
            }

            // Filter: activeonly — skip users not accessed in USER_INACTIVE_DAYS (~6 months).
            // V1: strtotime(NULL) returns FALSE (=0), which is always < threshold, so NULL lastaccess = skipped.
            if ($admin->activeonly) {
                if (!$member->lastaccess) {
                    $stats['skipped_activeonly']++;

                    continue;
                }

                $lastAccess = \Carbon\Carbon::parse($member->lastaccess);
                if ($lastAccess->lt($activeThreshold)) {
                    $stats['skipped_activeonly']++;

                    continue;
                }
            }

            // Filter: non-essential admins skip users with relevantallowed=0.
            // Moderators are always included (they can't opt out).
            if (!$admin->essential && !$member->relevantallowed) {
                $isMod = in_array($member->role, ['Moderator', 'Owner']);
                if (!$isMod) {
                    $stats['skipped_relevantallowed']++;

                    continue;
                }
            }

            // Filter: dedup via pre-loaded set (covers both suggested and regular admins).
            if ($sentUserIds->has($member->userid)) {
                $stats['skipped_dedup']++;

                continue;
            }

            $memberBuffer[] = $member;

            // Process buffer in chunks.
            if (count($memberBuffer) >= self::USER_CHUNK_SIZE) {
                $sent += $this->processChunk(
                    $memberBuffer, $admin, $adminArr, $stats, $dryRun, $useSpool, $spooler,
                    $groupName, $modsEmail, $group->nameshort, $dedupAdminId, $sentUserIds, $volunteers, $limit
                );
                $memberBuffer = [];
            }
        }

        // Process remaining members in buffer.
        if (!empty($memberBuffer) && !$interrupted) {
            $sent += $this->processChunk(
                $memberBuffer, $admin, $adminArr, $stats, $dryRun, $useSpool, $spooler,
                $groupName, $modsEmail, $group->nameshort, $dedupAdminId, $sentUserIds, $volunteers, $limit
            );
        }

        // If limit was reached during chunk processing, mark as interrupted.
        if ($limit > 0 && $stats['emails_sent'] >= $limit) {
            $interrupted = TRUE;
        }

        // Mark admin as complete only if we processed all members.
        // If interrupted (shutdown/limit), don't mark complete so it can be retried.
        if (!$dryRun && !$interrupted) {
            DB::table('admins')
                ->where('id', $admin->id)
                ->update(['complete' => now()]);
        }

        $this->info("Admin {$admin->id}: sent {$sent} emails.");

        return $sent;
    }

    /**
     * Process a chunk of members — batch-load User models to avoid N+1 queries.
     */
    protected function processChunk(
        array $memberBuffer,
        object $admin,
        array $adminArr,
        array &$stats,
        bool $dryRun,
        bool $useSpool,
        EmailSpoolerService $spooler,
        ?string $groupName,
        ?string $modsEmail,
        ?string $groupShort,
        int $dedupAdminId,
        \Illuminate\Support\Collection &$sentUserIds,
        array $volunteers,
        int $limit = 0
    ): int {
        $sent = 0;

        // Batch-load User models for all members in this chunk.
        $userIds = array_map(fn ($m) => $m->userid, $memberBuffer);
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($memberBuffer as $member) {
            if ($limit > 0 && $stats['emails_sent'] >= $limit) {
                break;
            }
            $user = $users->get($member->userid);

            if (!$user) {
                continue;
            }

            // Filter: must have a preferred email.
            $email = $user->email_preferred;
            if (!$email) {
                $stats['skipped_no_email']++;

                continue;
            }

            // Filter: skip TN users.
            if ($user->isTN()) {
                continue;
            }

            // Filter: skip simplemail=None for non-essential admins.
            if (!$admin->essential && $user->getSimpleMail() === User::SIMPLE_MAIL_NONE) {
                $stats['skipped_simplemail']++;

                continue;
            }

            if ($dryRun) {
                $stats['emails_sent']++;
                $sent++;

                continue;
            }

            try {
                // Substitute template variables in admin text, matching V1's constructMessage().
                $substitutedAdmin = $adminArr;
                $substitutedAdmin['text'] = str_replace(
                    ['$groupname', '$owneremail', '$membername', '$memberid'],
                    [$groupName ?? '', $modsEmail ?? '', $user->fullname ?? '', (string) $user->id],
                    $substitutedAdmin['text']
                );

                $mailable = new AdminMail($user, $substitutedAdmin, $groupName, $modsEmail, $groupShort, $volunteers);

                if ($useSpool) {
                    $spooler->spool($mailable, $email, self::EMAIL_TYPE);
                } else {
                    Mail::send($mailable);
                }

                // Record in admins_users for dedup (covers both suggested and regular admins).
                DB::table('admins_users')->insert([
                    'userid' => $member->userid,
                    'adminid' => $dedupAdminId,
                ]);
                $sentUserIds->put($member->userid, TRUE);

                $stats['emails_sent']++;
                $sent++;
            } catch (\Exception $e) {
                $stats['errors']++;
                Log::error("Admin {$admin->id}: error sending to user {$member->userid}: {$e->getMessage()}");
            }
        }

        return $sent;
    }
}
