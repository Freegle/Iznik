<?php

namespace App\Console\Commands\TrashNothing;

use App\Services\TrashNothing\SharedAccountSplitter;
use App\Services\TrashNothing\SplitRefused;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Moves one Trash Nothing member out of a Freegle account that holds two, onto a new account
 * with their TN user id. Reports only, unless --commit. See SharedAccountSplitter for the
 * evidence each row is moved on.
 *
 * Prints ids and counts only: never a name, username or address.
 */
#[AsCommand(name: 'tn:split-shared-account')]
class TNSplitSharedAccountCommand extends Command
{
    protected $signature = 'tn:split-shared-account
                            {userid : The Freegle account that holds both Trash Nothing members}
                            {username : The Trash Nothing username to move out}
                            {tnuserid : That username\'s Trash Nothing user id, from TN\'s API}
                            {--commit : Write the split. Without it, report what would move and write nothing}
                            {--move-chats= : Comma-separated ids of undecided chats to move as well}
                            {--rollback-file= : Where to write the SQL that undoes the split (default under storage/app/tn-splits)}';

    protected $description = 'Move one Trash Nothing member out of a Freegle account that holds two, onto a new account.';

    public function handle(SharedAccountSplitter $splitter): int
    {
        $account = (int) $this->argument('userid');
        $tnUserId = (int) $this->argument('tnuserid');
        $moveChats = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('move-chats')))));

        try {
            $plan = $splitter->plan($account, (string) $this->argument('username'), $tnUserId, $moveChats);
        } catch (SplitRefused $e) {
            $this->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($plan['state'] === 'done') {
            $this->info("Already split: TN user {$tnUserId} is on account {$plan['newAccount']} and account {$account} holds none of its addresses. Nothing to move.");

            return Command::SUCCESS;
        }

        $this->report($plan);

        if (!$this->option('commit')) {
            $this->info('Dry run: nothing written. Add --commit to split.');

            return Command::SUCCESS;
        }

        $path = $this->option('rollback-file')
            ?: storage_path("app/tn-splits/split-{$account}-{$tnUserId}-" . now()->format('Ymd-His') . '.sql');
        $new = $splitter->apply($plan, $path);

        $this->info("Split done: new account {$new} holds TN user {$tnUserId}. Rollback SQL: {$path}");

        return Command::SUCCESS;
    }

    private function report(array $plan): void
    {
        $ids = fn (array $list) => $list === [] ? '' : ' (' . implode(',', $list) . ')';
        $pairs = fn (array $list) => array_map(fn ($pair) => $pair[0], $list);

        $this->line("Account {$plan['account']}: moving one Trash Nothing member out to a new account for TN user {$plan['tnUserId']}.");
        $this->line('users_emails: ' . count($plan['emails']) . ' to move' . $ids($plan['emails']) . ' - addresses with that username');
        $this->line('messages: ' . count($plan['messages']) . ' to move' . $ids($plan['messages']) . ' - posts sent from those addresses');
        $this->line(sprintf(
            'memberships: %d to move, %d to copy, %d to stay, %d with no evidence - by which member\'s aliases posted to or joined each community',
            count($plan['membershipsMove']),
            count($plan['membershipsCopy']),
            count($plan['membershipsStay']),
            count($plan['membershipsUnattributed'])
        ));
        $this->line('memberships_history: ' . count($plan['historyMove']) . ' to move - joins made when that member\'s alias first reached us');
        $this->line(sprintf(
            'chat_rooms: %d to move, %d to stay, %d undecided - by which member could have written in each',
            count($plan['chatsMove']),
            count($plan['chatsStay']),
            count($plan['chatsUndecided'])
        ) . $ids($pairs($plan['chatsMove'])));
        $this->line('chat_roster: ' . count($plan['roster']) . ' to move');
        $this->line('chat_messages: ' . count($plan['chatMessages']) . ' to move - the account\'s messages in the moved chats');
        $this->line('messages_promises: ' . count($plan['promises']) . ', messages_by: ' . count($plan['takenBy'])
            . ', messages_reneged: ' . count($plan['reneged']) . ' to move - about posts in the moved chats');
        $this->line('ratings: ' . count($plan['ratings']) . ', trysts: ' . count($plan['trysts'])
            . ', users_expected: ' . count($plan['expected']) . ' to move - with people the account only chats with as that member');
        $this->line('logs: ' . (count($plan['logsUser']) + count($plan['logsByuser'])) . ' to move - about the moved posts, or joins at the moved aliases\' time');

        if ($plan['membershipsUnattributed'] !== []) {
            $this->line('Memberships with no evidence stay on the account' . $ids($plan['membershipsUnattributed']) . '.');
        }
        if ($plan['chatsUndecided'] !== []) {
            $this->line("Undecided chats stay on account {$plan['account']} unless named in --move-chats:");
            foreach ($plan['chatsUndecided'] as $chat => $reason) {
                $this->line("  chat {$chat}: {$reason}");
            }
        }
    }
}
