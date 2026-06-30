<?php

namespace App\Console\Commands\BulkOffer;

use App\Models\MessagesBulkOutreach;
use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Import community organisations (from the community-reuse-outreach CSV) as Freegle
 * users and record their details + preferences for a specific bulk offer, without
 * sending the automatic welcome email. The per-org intro is sent later, separately,
 * via chat linking to the bulk offer page.
 *
 * CSV columns (the outreach list): Tier, Cost to donor, Organisation, Type, Area,
 * Email, Website, Likely wants / why, Cluster, Activity evidence, Confidence, Source.
 * Only Organisation + Email are required per row.
 */
class ImportOrgsCommand extends Command
{
    protected $signature = 'bulkoffer:import-orgs
                            {--msgid= : The bulk offer message id (messages.id) these orgs are being approached for (required)}
                            {--input= : Path to the outreach-list CSV (required)}
                            {--source=cold_outreach : users.source recorded on newly-created org users}
                            {--dry-run : Report what would be created/updated without writing anything}';

    protected $description = 'Import outreach organisations from a CSV as Freegle users and record their per-bulk-offer details (no welcome email sent)';

    public function handle(): int
    {
        $msgid = (int) $this->option('msgid');
        $input = (string) $this->option('input');
        $source = $this->option('source') ?: 'cold_outreach';
        $dryRun = (bool) $this->option('dry-run');

        if (!$msgid) {
            $this->error('--msgid is required (the bulk offer messages.id).');
            return self::FAILURE;
        }
        if ($input === '') {
            $this->error('--input is required (path to the outreach CSV).');
            return self::FAILURE;
        }
        if (!file_exists($input)) {
            $this->error("Input file not found: {$input}");
            return self::FAILURE;
        }
        if (!DB::table('messages')->where('id', $msgid)->exists()) {
            $this->error("Bulk offer message #{$msgid} not found in the messages table.");
            return self::FAILURE;
        }

        $fh = fopen($input, 'r');
        if ($fh === false) {
            $this->error("Could not open: {$input}");
            return self::FAILURE;
        }

        $header = fgetcsv($fh);
        if ($header === false) {
            $this->error('CSV is empty.');
            fclose($fh);
            return self::FAILURE;
        }
        $idx = [];
        foreach ($header as $i => $h) {
            $idx[strtolower(trim((string) $h))] = $i;
        }
        $col = function (array $row, string $name) use ($idx): string {
            $i = $idx[strtolower($name)] ?? null;
            return ($i !== null && isset($row[$i])) ? trim((string) $row[$i]) : '';
        };

        if ($dryRun) {
            $this->warn('[DRY RUN] no changes will be written.');
        }

        $created = 0;
        $matched = 0;
        $outreachNew = 0;
        $outreachUpd = 0;
        $skipped = 0;
        $line = 1;

        while (($row = fgetcsv($fh)) !== false) {
            $line++;
            $email = $col($row, 'Email');
            $orgname = $col($row, 'Organisation') ?: $col($row, 'Name');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("  line {$line}: skipped ('{$orgname}') - missing or invalid email");
                $skipped++;
                continue;
            }
            if ($orgname === '') {
                $this->warn("  line {$line}: skipped - missing organisation name (email {$email})");
                $skipped++;
                continue;
            }

            [$user, $isNew] = $this->findOrCreateUser($email, $orgname, $source, $dryRun);
            if ($isNew) {
                $created++;
            } else {
                $matched++;
            }

            $attrs = [
                'orgname' => $orgname,
                'orgtype' => $col($row, 'Type') ?: null,
                'website' => $col($row, 'Website') ?: null,
                'area' => $col($row, 'Area') ?: null,
                'tier' => $this->parseTier($col($row, 'Tier')),
                'clusters' => $this->parseClusters($col($row, 'Cluster')),
                'preferences' => $col($row, 'Likely wants / why') ?: null,
                'activity_url' => $this->firstUrl($col($row, 'Activity evidence')),
                'confidence' => $this->parseConfidence($col($row, 'Confidence')),
                'source' => $col($row, 'Source') ?: null,
                'notes' => $col($row, 'Activity evidence') ?: null,
            ];

            if ($dryRun || !$user) {
                $outreachNew++;
                continue;
            }

            $existing = MessagesBulkOutreach::where('msgid', $msgid)
                ->where('userid', $user->id)
                ->first();
            if ($existing) {
                $existing->fill($attrs)->save();
                $outreachUpd++;
            } else {
                MessagesBulkOutreach::create($attrs + [
                    'msgid' => $msgid,
                    'userid' => $user->id,
                    'status' => MessagesBulkOutreach::STATUS_IMPORTED,
                ]);
                $outreachNew++;
            }
        }
        fclose($fh);

        $this->info(sprintf(
            '%sBulk offer #%d: users %d new / %d matched; outreach %d new / %d updated; %d skipped.',
            $dryRun ? '[DRY RUN] ' : '',
            $msgid,
            $created,
            $matched,
            $outreachNew,
            $outreachUpd,
            $skipped
        ));

        return self::SUCCESS;
    }

    /**
     * Find an existing user by email (or its canonical form), otherwise create a new
     * one. New org users have `added` backdated so the mail:welcome:send job - which
     * only processes users added within the last day - never sends them a welcome.
     *
     * @return array{0: ?User, 1: bool}  [user (null only in dry-run when it would create), isNew]
     */
    private function findOrCreateUser(string $email, string $orgname, string $source, bool $dryRun): array
    {
        $canon = User::canonMail($email);
        $emailRow = UserEmail::where('email', $email)
            ->orWhere('canon', $canon)
            ->first();

        if ($emailRow && $emailRow->userid) {
            return [User::find($emailRow->userid), false];
        }

        if ($dryRun) {
            return [null, true];
        }

        $user = new User();
        $user->fullname = $orgname;
        $user->source = $source;
        $backdated = Carbon::now()->subDays(2);   // suppress the welcome email
        $user->added = $backdated;
        $user->lastaccess = $backdated;
        $user->save();
        $user->addEmail($email, 1);

        return [$user, true];
    }

    private function parseTier(string $v): ?string
    {
        return preg_match('/\b([12])\b/', $v, $m) ? $m[1] : null;
    }

    private function parseConfidence(string $v): ?string
    {
        $v = strtolower($v);
        foreach (['high', 'med', 'low'] as $c) {
            if (str_contains($v, $c)) {
                return $c;
            }
        }
        return null;
    }

    private function parseClusters(string $v): ?array
    {
        if ($v === '') {
            return null;
        }
        $parts = preg_split('/\s*[\/,;]\s*/', $v, -1, PREG_SPLIT_NO_EMPTY);
        return $parts ?: null;
    }

    private function firstUrl(string $v): ?string
    {
        return preg_match('#https?://\S+#', $v, $m) ? rtrim($m[0], '.,);') : null;
    }
}
