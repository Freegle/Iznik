<?php

namespace App\Console\Commands\User;

use App\Models\User;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'users:fix-tn-names')]
class FixTNNamesCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'users:fix-tn-names
                            {--dry-run : Show what would be updated without making changes}';

    protected $description = 'Fix display names for Trash Nothing users whose email encodes their name';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no changes will be made.');
        }

        return $this->runWithLogging(function () use ($dryRun) {
            Log::info('Starting TN name fix', ['dry_run' => $dryRun]);

            $fixed  = 0;
            $skipped = 0;

            // TN emails have the format: firstname-groupid@user.trashnothing.com.
            //
            // Matched on the address, not on backwards. That column does not hold one
            // thing: its definition is REVERSE(canon), which drops the -gNNNN suffix and
            // the domain dots, but some rows hold REVERSE(email) and some hold NULL. A
            // prefix on it reached 20.5% of Trash Nothing members and silently skipped
            // the rest. See .claude/rules/mail-and-data.md.
            // Both shapes are live: the per-group aliases are name-gNNNN@user.trashnothing.com,
            // and older rows sit directly on the bare domain. The reversed prefix this
            // replaced matched both, so the address pattern has to as well.
            $tnAddressSuffix = '%@%' . config('freegle.mail.trashnothing_domain');

            $rows = DB::table('users')
                ->join('users_emails', 'users.id', '=', 'users_emails.userid')
                ->where('users_emails.email', 'LIKE', $tnAddressSuffix)
                ->whereNull('users.firstname')
                ->whereNull('users.lastname')
                ->where(function ($q) {
                    $q->whereNull('users.fullname')
                      ->orWhere('users.fullname', 'LIKE', '%-%');
                })
                ->select('users.id', 'users.fullname', 'users_emails.email')
                ->get();

            // A member can be several rows (a bare address and aliases), so the question
            // "is this name already right" is asked once per member, over all their rows.
            $rowsByUser = $rows->groupBy('id');

            foreach ($rowsByUser as $userRows) {
                if ($this->alreadyHasDisplayName($userRows)) {
                    $skipped++;
                    continue;
                }

                $row  = $userRows->first(fn ($candidate) => $this->nameFromAlias($candidate->email) !== null);
                $name = $row ? $this->nameFromAlias($row->email) : null;

                if ($name === null) {
                    $skipped++;
                    continue;
                }

                Log::debug("FixTNNames: set fullname for user {$row->id} from {$row->email} => {$name}");

                if (!$dryRun) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['fullname' => $name]);
                }

                $fixed++;
            }

            $verb = $dryRun ? 'would fix' : 'fixed';
            $this->info("Processed " . count($rowsByUser) . " TN users: {$verb} {$fixed}, skipped {$skipped}.");
            Log::info('TN name fix complete', [
                'candidates' => count($rowsByUser),
                'fixed'      => $fixed,
                'skipped'    => $skipped,
            ]);

            return Command::SUCCESS;
        });
    }

    /**
     * Whether the member's name is already the display name of one of their TN usernames.
     *
     * The user-changes sync names a member with User::tnDisplayName()
     * ("mary-jane" is "Mary-Jane"), and that name has a hyphen, which is what lets the
     * query above select it. It is the right name and must be left as it is.
     */
    private function alreadyHasDisplayName(Collection $userRows): bool
    {
        $fullname = (string) $userRows->first()->fullname;
        if ($fullname === '') {
            return false;
        }

        foreach ($userRows as $row) {
            $username = User::tnUsernameFromEmail($row->email);
            if ($username !== null && $fullname === User::tnDisplayName($username)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The name an alias address encodes, or null when the address encodes none.
     *
     * An address at user.trashnothing.com is either the bare username or the username
     * with a per-group -g<digits> suffix, and only the suffix is dropped. A bare address
     * has nothing to drop: a hyphen in it belongs to the username ("mary-jane" is not
     * "mary"). Older addresses on the plain trashnothing.com domain end in the group id.
     */
    private function nameFromAlias(string $email): ?string
    {
        if (User::tnUsernameFromEmail($email) !== null) {
            return preg_match('/^(.+)-g\d+@/i', $email, $matches) ? $matches[1] : null;
        }

        return preg_match('/^(.*)-[^-]+@/', $email, $matches) ? $matches[1] : null;
    }
}
