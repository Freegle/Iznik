<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\PreventsOverlapping;
use App\Mail\Admin\AdminMail;
use App\Mail\Traits\AvatarResolver;
use App\Mail\Traits\FeatureFlags;
use App\Models\User;
use App\Services\EmailSpoolerService;
use App\Support\GreatCircle;
use App\Traits\GracefulShutdown;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'mail:admin:send')]
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

    protected $description = 'Send approved admin emails to members';

    private const EMAIL_TYPE = 'Admin';

    /**
     * Days threshold for considering an admin too old to process.
     * Matches V1's "Midnight 7 days ago" filter.
     */
    private const ADMIN_AGE_DAYS = 7;

    // USER_INACTIVE_DAYS lives on User::USER_INACTIVE_DAYS — single source of
    // truth so this command, the events/volunteering digests, BirthdayService
    // etc. all agree on what "recently active" means.

    /**
     * Chunk size for batch-loading User models to avoid N+1 queries.
     */
    private const USER_CHUNK_SIZE = 100;

    private const MOD_ROLES = [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN];

    public function handle(EmailSpoolerService $spooler): int
    {
        if (!self::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            $this->info("Admin emails are not enabled. Set FREEGLE_MAIL_ENABLED_TYPES to include 'Admin'.");

            return Command::SUCCESS;
        }

        // While a lockdown holds email, generate nothing (plan 2026-09-27-lockdown-switch.md
        // section 11.11). Admins a moderator queued before the press have already been sent
        // back to pending by lockdown:tick, so they wait for a fresh approval.
        $lockdown = app(\App\Services\Lockdown\LockdownService::class);
        $lockdown->ack('mail-loops');
        if ($lockdown->held('email')) {
            $lockdown->count('deferred:admin');
            $this->info('Email is held by the lockdown; no admins sent.');

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
            'skipped_outside_area' => 0,
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
                ['Skipped (outside the area)', $stats['skipped_outside_area']],
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
     * - Created or edited within last 7 days, or its sendafter time fell within them
     * - sendafter has passed (if set)
     *
     * The 7 days stop an old, forgotten admin going out late. A sendafter time is a deliberate
     * choice, so the window runs from it too: otherwise an admin approved more than 7 days
     * before its sendafter time was never sent at all (V1 had the same gap).
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
                    })
                    ->orWhere(function ($q3) use ($cutoff) {
                        $q3->whereNotNull('sendafter')
                            ->where('sendafter', '>', $cutoff);
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
     * Fill in the template variables, matching V1's constructMessage(). The MJML part is markup,
     * so the values going into it are HTML-escaped: a member's name must not become a tag.
     */
    public static function personalise(array $admin, ?string $groupName, ?string $modsEmail, User $user): array
    {
        $names = ['$groupname', '$owneremail', '$membername', '$memberid'];
        $values = [$groupName ?? '', $modsEmail ?? '', $user->fullname ?? '', (string) $user->id];

        $admin['text'] = str_replace($names, $values, $admin['text']);

        if (!empty($admin['mjml'])) {
            $admin['mjml'] = str_replace($names, array_map('e', $values), $admin['mjml']);
        }

        return $admin;
    }

    /**
     * Get the volunteers (active national moderators who haven't opted out via
     * "Show me as a volunteer").
     *
     * Uses users.settings.showmod (default true), the setting the ModTools
     * "Show me as a volunteer" toggle writes. users.publishconsent is a different
     * consent and must not be used here.
     */
    public static function getVolunteers(): array
    {
        $oneYearAgo  = now()->subYear();
        $avatarBase  = rtrim(config('freegle.avatar_server_url', ''), '/');

        $mods = User::select('users.id', 'users.fullname')
            ->whereIn('users.systemrole', self::MOD_ROLES)
            ->where('users.lastaccess', '>', $oneYearAgo)
            ->where(function ($q) {
                $q->whereJsonDoesntContainKey('users.settings->showmod')
                    ->orWhere('users.settings->showmod', true);
            })
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
     * Whether a member lives within radius miles of an ADMIN's centre: the place they chose in
     * their settings, else their last location. A member with no location is outside every area.
     */
    private function inArea(object $member, float $lat, float $lng, float $radius): bool
    {
        $at = User::chosenLatLng($member->settings, $member->tnuserid)
            ?? (($member->loclat !== null && $member->loclng !== null)
                ? [(float) $member->loclat, (float) $member->loclng]
                : null);

        return $at !== null && GreatCircle::distanceMiles($lat, $lng, $at[0], $at[1]) <= $radius;
    }

    /**
     * Process a single admin: send to every eligible member in its audience.
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

        $groupName = 'Freegle';
        $modsEmail = config('freegle.mail.support_addr');

        $this->info("Processing admin {$admin->id}...");

        // Who it goes to. With no communities an ADMIN is either national or shown around a centre,
        // like an event; one with neither goes to nobody rather than to everyone.
        if (! $admin->national && ($admin->lat === null || $admin->lng === null)) {
            $this->warn("Admin {$admin->id} says neither national nor where it goes; not sending.");
            Log::warning('SendAdmin: admin has no audience', ['admin_id' => $admin->id]);

            return 0;
        }
        $radius = $admin->national
            ? null
            : (float) ($admin->reachmiles ?? config('freegle.local_reach.fallback_miles'));

        // Pre-load dedup set: all user IDs already sent for this admin's parent (or self).
        // Uses a flipped collection for O(1) lookups instead of per-user DB queries.
        $dedupAdminId = $admin->parentid ?: $admin->id;
        $sentUserIds = DB::table('admins_users')
            ->where('adminid', $dedupAdminId)
            ->pluck('userid')
            ->flip();

        // Volunteers for the footer.
        $volunteers = self::getVolunteers();

        $members = DB::table('users')
            ->leftJoin('locations', 'locations.id', '=', 'users.lastlocation')
            ->whereNull('users.deleted')
            ->whereNull('users.banned')
            ->select([
                'users.id AS userid',
                'users.systemrole',
                'users.relevantallowed',
                'users.lastaccess',
                'users.deleted',
                'users.settings',
                'users.tnuserid',
                'locations.lat AS loclat',
                'locations.lng AS loclng',
            ])
            ->orderBy('users.id')
            ->cursor();

        $activeThreshold = now()->subDays(User::USER_INACTIVE_DAYS);
        $adminArr = (array) $admin;

        // A designed version that will not build is dropped once here, so members get the plain
        // text without every one of their copies failing to compile first.
        if (!empty($adminArr['mjml']) && !AdminMail::mjmlBuilds($adminArr['mjml'])) {
            Log::warning("Admin {$admin->id}: MJML part does not build, sending the plain text only.");
            $adminArr['mjml'] = NULL;
        }

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

            if ($radius !== null && ! $this->inArea($member, (float) $admin->lat, (float) $admin->lng, $radius)) {
                $stats['skipped_outside_area']++;

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
                $isMod = in_array($member->systemrole, self::MOD_ROLES);
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
                    $groupName, $modsEmail, null, $dedupAdminId, $sentUserIds, $volunteers, $limit
                );
                $memberBuffer = [];
            }
        }

        // Process remaining members in buffer.
        if (!empty($memberBuffer) && !$interrupted) {
            $sent += $this->processChunk(
                $memberBuffer, $admin, $adminArr, $stats, $dryRun, $useSpool, $spooler,
                $groupName, $modsEmail, null, $dedupAdminId, $sentUserIds, $volunteers, $limit
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
                $substitutedAdmin = self::personalise($adminArr, $groupName, $modsEmail, $user);

                $mailable = new AdminMail($user, $substitutedAdmin, $groupName, $modsEmail, $groupShort, $volunteers);

                if ($useSpool) {
                    $spooler->spool($mailable, $email, self::EMAIL_TYPE);
                } else {
                    app(\App\Services\EmailSpoolerService::class)->spool($mailable);
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
