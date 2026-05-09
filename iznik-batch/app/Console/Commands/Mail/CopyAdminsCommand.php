<?php

namespace App\Console\Commands\Mail;

use App\Models\BackgroundTask;
use App\Models\Group;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CopyAdminsCommand extends Command
{
    protected $signature = 'mail:admin:copy';

    protected $description = 'Copy suggested admins to per-group copies and delete old pending admins';

    /**
     * Days after which old pending admins are deleted.
     * Matches V1's 31-day cleanup.
     */
    private const STALE_PENDING_DAYS = 31;

    public function handle(): int
    {
        $this->cleanupOldPending();
        $this->copySuggestedAdmins();

        return Command::SUCCESS;
    }

    /**
     * Delete pending admins older than 31 days.
     * Matches V1: "Delete old unapproved admins".
     */
    protected function cleanupOldPending(): void
    {
        $cutoff = now()->subDays(self::STALE_PENDING_DAYS);

        $deleted = DB::table('admins')
            ->where('pending', 1)
            ->whereNull('complete')
            ->where('created', '<', $cutoff)
            ->delete();

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} stale pending admin(s) older than " . self::STALE_PENDING_DAYS . ' days.');
        }
    }

    /**
     * Find suggested admins (groupid IS NULL, complete IS NULL) and create
     * per-group copies for each Freegle group with autoadmins enabled.
     *
     * Matches V1's cron logic: for each suggested admin, for each Freegle group,
     * insert a per-group copy with parentid pointing to the original.
     */
    protected function copySuggestedAdmins(): void
    {
        $suggested = DB::table('admins')
            ->whereNull('groupid')
            ->whereNull('complete')
            ->get();

        if ($suggested->isEmpty()) {
            $this->info('No suggested admins to copy.');

            return;
        }

        // Get all active Freegle groups with autoadmins enabled.
        $groups = Group::activeFreegle()
            ->get()
            ->filter(function (Group $group) {
                return $group->getSetting('autoadmins', 1) != 0;
            });

        $this->info("Found {$suggested->count()} suggested admin(s) to copy to {$groups->count()} group(s).");

        foreach ($suggested as $admin) {
            $copied = 0;

            foreach ($groups as $group) {
                // Check if a copy already exists for this group.
                $exists = DB::table('admins')
                    ->where('parentid', $admin->id)
                    ->where('groupid', $group->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                // Notify group moderators about the new pending admin via background task.
                DB::table('background_tasks')->insert([
                    'task_type' => BackgroundTask::TASK_PUSH_NOTIFY_GROUP_MODS,
                    'data' => json_encode(['group_id' => $group->id]),
                ]);

                DB::table('admins')->insert([
                    'createdby' => $admin->createdby,
                    'groupid' => $group->id,
                    'created' => now(),
                    'subject' => $admin->subject,
                    'text' => $admin->text,
                    'ctalink' => $admin->ctalink,
                    'ctatext' => $admin->ctatext,
                    'pending' => 1,
                    'parentid' => $admin->id,
                    'activeonly' => $admin->activeonly,
                    'sendafter' => $admin->sendafter,
                    'essential' => $admin->essential,
                    'editprotected' => $admin->editprotected,
                    'template' => $admin->template,
                ]);

                $copied++;
            }

            // Mark the suggested admin as complete (copied).
            DB::table('admins')
                ->where('id', $admin->id)
                ->update(['complete' => now()]);

            $this->info("Suggested admin {$admin->id}: copied to {$copied} group(s).");
        }
    }
}
