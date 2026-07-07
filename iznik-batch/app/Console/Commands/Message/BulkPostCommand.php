<?php

namespace App\Console\Commands\Message;

use App\Models\Group;
use App\Models\Location;
use App\Models\User;
use App\Models\UserEmail;
use App\Services\FreegleApiClient;
use App\Services\TusService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Bulk-post offer messages from a CSV file via the Go API.
 *
 * Designed for charity clearance events (e.g. Mind in Brighton) where
 * many items need to be posted at once from a single location/user.
 *
 * Folder layout:
 *   items.csv   - name,count,photos columns
 *   body.txt    - shared body text for all posts
 *   *.jpg/png   - photo files referenced in CSV
 */
class BulkPostCommand extends Command
{
    protected $signature = 'messages:bulk-post
        {folder : Path to folder containing items.csv, body.txt, and photos}
        {--email= : Email address of the posting user}
        {--postcode= : Postcode to post from (e.g. "BN1 1AA")}
        {--group= : Group name to post to (auto-detected from postcode if omitted)}
        {--cross-post-groups= : Comma-separated group names to also post to (with a "no takers yet" prefix)}
        {--skip-primary-group : Only post to --cross-post-groups, not the primary group (for items already posted)}
        {--first : Only post the first item (for testing)}
        {--api-url= : Override the API base URL (default: from config)}
        {--dry-run : Show what would be posted without making changes}';

    protected $description = 'Bulk-post offer messages from a CSV file via the API';

    private FreegleApiClient $api;

    private TusService $tus;

    public function handle(TusService $tus): int
    {
        $this->api = new FreegleApiClient($this->option('api-url'));
        $this->tus = $tus;

        $folder = rtrim($this->argument('folder'), '/');
        $dryRun = $this->option('dry-run');

        // Validate folder contents.
        $validation = $this->validateFolder($folder);
        if ($validation !== null) {
            $this->error($validation);

            return self::FAILURE;
        }

        // Parse CSV.
        $items = $this->parseCsv($folder.'/items.csv');
        if ($items === null) {
            return self::FAILURE;
        }

        // Read body text.
        $body = file_get_contents($folder.'/body.txt');
        if (! $body || trim($body) === '') {
            $this->error('body.txt is empty.');

            return self::FAILURE;
        }
        $body = trim($body);

        // Validate all photo files exist before starting.
        $missingPhotos = $this->validatePhotos($items, $folder);
        if (! empty($missingPhotos)) {
            foreach ($missingPhotos as $missing) {
                $this->error("Missing photo: {$missing}");
            }

            return self::FAILURE;
        }

        // Validate deadline formats.
        $badDeadlines = $this->validateDeadlines($items);
        if (! empty($badDeadlines)) {
            foreach ($badDeadlines as $bad) {
                $this->error($bad);
            }

            return self::FAILURE;
        }

        // Resolve user.
        $user = $this->resolveUser($this->option('email'));
        if (! $user) {
            return self::FAILURE;
        }

        // Resolve location.
        $location = $this->resolveLocation($this->option('postcode'));
        if (! $location) {
            return self::FAILURE;
        }

        // Resolve group.
        $group = $this->resolveGroup($this->option('group'), $location);
        if (! $group) {
            return self::FAILURE;
        }

        // Resolve cross-post groups.
        $crossPostGroups = $this->resolveCrossPostGroups($this->option('cross-post-groups'), $group);
        $skipPrimary = (bool) $this->option('skip-primary-group');

        if ($skipPrimary && empty($crossPostGroups)) {
            $this->error('--skip-primary-group requires --cross-post-groups to be specified.');

            return self::FAILURE;
        }

        // Show summary.
        $this->info("User:     {$user->fullname} (ID {$user->id})");
        $this->info("Postcode: {$location->name} (ID {$location->id})");
        $this->info("Group:    {$group->nameshort} (ID {$group->id})".($skipPrimary ? ' [SKIPPED]' : ''));
        if (! empty($crossPostGroups)) {
            $cpNames = implode(', ', array_map(fn ($g) => $g->nameshort, $crossPostGroups));
            $this->info("X-post:   {$cpNames}");
        }
        $this->info("Items:    ".count($items));
        $this->info("Dry run:  ".($dryRun ? 'YES' : 'NO'));
        $this->newLine();

        if ($dryRun) {
            return $this->dryRun($items, $body, $folder, $group, $crossPostGroups, $skipPrimary);
        }

        // Authenticate to Go API.
        if (! $this->authenticateApi($user)) {
            return self::FAILURE;
        }

        // Post items (optionally just the first one for testing).
        $postItems = $this->option('first') ? [reset($items)] : $items;

        $successCount = 0;
        $failCount = 0;

        if ($this->option('first')) {
            $this->info('--first: posting only the first item.');
        }

        // Build the cross-post body prefix once.
        $primaryGroupName = $group->namedisplay ?: $group->nameshort;
        $crossPostBody = "Posting here because no takers so far on {$primaryGroupName}.\n\n{$body}";

        foreach ($postItems as $i => $item) {
            $num = $i + 1;
            $this->info("[{$num}/".count($postItems)."] Posting: {$item['name']}");

            // Upload photos once; reuse attachment IDs across all groups.
            $attachmentIds = $this->uploadItemPhotos($item, $folder);

            // Build list of groups to post to.
            $targets = [];
            if (! $skipPrimary) {
                $targets[] = ['group' => $group, 'body' => $body];
            }
            foreach ($crossPostGroups as $cpGroup) {
                $targets[] = ['group' => $cpGroup, 'body' => $crossPostBody];
            }

            foreach ($targets as $target) {
                $msgId = $this->postItemToGroup($item, $target['body'], $location, $target['group'], $attachmentIds);

                if ($msgId) {
                    $successCount++;
                    $this->info("  Created message ID {$msgId} on {$target['group']->nameshort}");
                } else {
                    $failCount++;
                    $this->error("  FAILED to post: {$item['name']} on {$target['group']->nameshort}");
                }
            }
        }

        $this->newLine();
        $this->info("Done. {$successCount} posted, {$failCount} failed.");

        if ($failCount > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Validate that the folder contains the required files.
     */
    private function validateFolder(string $folder): ?string
    {
        if (! is_dir($folder)) {
            return "Folder not found: {$folder}";
        }

        if (! file_exists($folder.'/items.csv')) {
            return "items.csv not found in {$folder}";
        }

        if (! file_exists($folder.'/body.txt')) {
            return "body.txt not found in {$folder}";
        }

        return null;
    }

    /**
     * Parse the CSV file into an array of items.
     *
     * Expected columns: name, count, photos
     * Photos column contains semicolon-separated filenames.
     *
     * @return array|null Array of ['name' => string, 'count' => int, 'photos' => string[]]
     */
    private function parseCsv(string $path): ?array
    {
        $handle = fopen($path, 'r');
        if (! $handle) {
            $this->error("Cannot open: {$path}");

            return null;
        }

        // Read header row.
        $header = fgetcsv($handle);
        if (! $header) {
            $this->error('CSV is empty.');
            fclose($handle);

            return null;
        }

        // Normalize header names.
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        $nameIdx = array_search('name', $header);
        $countIdx = array_search('count', $header);
        $photosIdx = array_search('photos', $header);
        $deadlineIdx = array_search('deadline', $header); // Optional column.

        if ($nameIdx === false || $countIdx === false || $photosIdx === false) {
            $this->error('CSV must have columns: name, count, photos. Found: '.implode(', ', $header));
            fclose($handle);

            return null;
        }

        $items = [];
        $lineNum = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;
            $name = trim($row[$nameIdx] ?? '');
            $count = (int) trim($row[$countIdx] ?? '0');
            $photosRaw = trim($row[$photosIdx] ?? '');

            if ($name === '') {
                continue; // Skip blank rows.
            }

            if ($count < 1) {
                $this->warn("Line {$lineNum}: count is {$count} for '{$name}', defaulting to 1.");
                $count = 1;
            }

            $photos = [];
            if ($photosRaw !== '') {
                $photos = array_map('trim', preg_split('/[;,]/', $photosRaw));
                $photos = array_filter($photos, fn ($p) => $p !== '');
            }

            $deadline = ($deadlineIdx !== false) ? trim($row[$deadlineIdx] ?? '') : '';

            $items[] = [
                'name' => $name,
                'count' => $count,
                'photos' => array_values($photos),
                'deadline' => $deadline ?: null,
            ];
        }

        fclose($handle);

        if (empty($items)) {
            $this->error('No items found in CSV.');

            return null;
        }

        return $items;
    }

    /**
     * Check all referenced photo files exist.
     */
    private function validatePhotos(array $items, string $folder): array
    {
        $missing = [];

        foreach ($items as $item) {
            foreach ($item['photos'] as $photo) {
                $path = $folder.'/'.$photo;
                if (! file_exists($path)) {
                    $missing[] = $photo;
                }
            }
        }

        return array_unique($missing);
    }

    /**
     * Validate deadline formats (YYYY-MM-DD).
     */
    private function validateDeadlines(array $items): array
    {
        $errors = [];

        foreach ($items as $item) {
            if ($item['deadline'] !== null) {
                $d = \DateTime::createFromFormat('Y-m-d', $item['deadline']);
                if (! $d || $d->format('Y-m-d') !== $item['deadline']) {
                    $errors[] = "Invalid deadline '{$item['deadline']}' for '{$item['name']}' (expected YYYY-MM-DD)";
                }
            }
        }

        return $errors;
    }

    /**
     * Look up user by email address.
     */
    private function resolveUser(?string $email): ?User
    {
        if (! $email) {
            $this->error('--email is required.');

            return null;
        }

        $userEmail = UserEmail::where('email', $email)->first();
        if (! $userEmail) {
            $this->error("No user found with email: {$email}");

            return null;
        }

        $user = User::find($userEmail->userid);
        if (! $user) {
            $this->error("User record not found for ID: {$userEmail->userid}");

            return null;
        }

        return $user;
    }

    /**
     * Look up postcode in the locations table.
     */
    private function resolveLocation(?string $postcode): ?Location
    {
        if (! $postcode) {
            $this->error('--postcode is required.');

            return null;
        }

        $canon = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $postcode));

        $location = Location::where('type', 'Postcode')
            ->where('canon', $canon)
            ->first();

        if (! $location) {
            // Try prefix match for partial postcodes.
            $location = Location::where('type', 'Postcode')
                ->where('canon', 'LIKE', $canon.'%')
                ->where('name', 'LIKE', '% %')
                ->first();
        }

        if (! $location) {
            $this->error("Postcode not found: {$postcode}");

            return null;
        }

        return $location;
    }

    /**
     * Resolve the target group, either by name or by finding the nearest to the location.
     */
    private function resolveGroup(?string $groupName, Location $location): ?Group
    {
        if ($groupName) {
            $group = Group::where('nameshort', $groupName)->first();
            if (! $group) {
                $group = Group::where('nameshort', 'LIKE', '%'.$groupName.'%')
                    ->where('type', Group::TYPE_FREEGLE)
                    ->first();
            }

            if (! $group) {
                $this->error("Group not found: {$groupName}");

                return null;
            }

            return $group;
        }

        // Find nearest Freegle group to the postcode.
        $group = Group::where('type', Group::TYPE_FREEGLE)
            ->where('onhere', 1)
            ->where('publish', 1)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->select('*')
            ->selectRaw(
                'ST_Distance_Sphere(POINT(lng, lat), POINT(?, ?)) AS distance',
                [$location->lng, $location->lat]
            )
            ->orderBy('distance')
            ->first();

        if (! $group) {
            $this->error('No Freegle group found near this postcode. Use --group to specify one.');

            return null;
        }

        $this->info("Auto-detected nearest group: {$group->nameshort}");

        return $group;
    }

    /**
     * Show what would be posted without making changes.
     */
    private function dryRun(array $items, string $body, string $folder, Group $primaryGroup, array $crossPostGroups, bool $skipPrimary): int
    {
        $this->info('=== DRY RUN — nothing will be posted ===');
        $this->newLine();

        $targets = [];
        if (! $skipPrimary) {
            $targets[] = $primaryGroup->nameshort;
        }
        foreach ($crossPostGroups as $g) {
            $targets[] = $g->nameshort.' (cross-post)';
        }
        $this->info('Target groups: '.implode(', ', $targets));
        $this->newLine();

        $totalPhotos = 0;

        foreach ($items as $i => $item) {
            $num = $i + 1;
            $subject = $this->buildSubject($item);
            $photoCount = count($item['photos']);
            $totalPhotos += $photoCount;

            $photoSizes = [];
            foreach ($item['photos'] as $photo) {
                $path = $folder.'/'.$photo;
                $photoSizes[] = $photo.' ('.round(filesize($path) / 1024).'KB)';
            }

            $countInfo = $item['count'] > 1 ? " (qty: {$item['count']})" : '';
            $deadlineInfo = $item['deadline'] ? " [deadline: {$item['deadline']}]" : '';
            $this->info("{$num}. OFFER: {$subject}{$countInfo}{$deadlineInfo}");
            $this->info("   Photos ({$photoCount}): ".($photoSizes ? implode(', ', $photoSizes) : 'none'));
        }

        $this->newLine();
        $this->info('Body text ('.strlen($body).' chars):');
        $this->line(substr($body, 0, 200).(strlen($body) > 200 ? '...' : ''));
        $this->newLine();
        $postCount = count($items) * count($targets);
        $this->info("Total: ".count($items)." items × ".count($targets)." groups = {$postCount} posts, {$totalPhotos} photos per item");
        $this->info('All posts will go to PENDING for moderator review.');

        return self::SUCCESS;
    }

    /**
     * Build the OFFER subject line for an item.
     * Count is handled separately via availableinitially/availablenow API fields.
     */
    private function buildSubject(array $item): string
    {
        return $item['name'];
    }

    /**
     * Authenticate to the Go API by creating a Link login key.
     */
    private function authenticateApi(User $user): bool
    {
        // Create or reuse a Link login key for this user.
        $key = bin2hex(random_bytes(16));

        DB::table('users_logins')->updateOrInsert(
            ['userid' => $user->id, 'type' => 'Link'],
            ['credentials' => $key, 'added' => now()]
        );

        if (! $this->api->authenticate($user->id, $key)) {
            $this->error('Failed to authenticate to the Go API.');

            return false;
        }

        $this->info('Authenticated to API.');

        return true;
    }

    /**
     * Upload photos for an item and return the attachment IDs.
     * Called once per item; IDs are reused across cross-post groups.
     *
     * @return int[]
     */
    private function uploadItemPhotos(array $item, string $folder): array
    {
        $attachmentIds = [];

        foreach ($item['photos'] as $photo) {
            $attachmentId = $this->uploadPhoto($folder.'/'.$photo);

            if ($attachmentId === null) {
                $this->warn("  Failed to upload photo: {$photo}");

                continue;
            }

            $attachmentIds[] = $attachmentId;
        }

        return $attachmentIds;
    }

    /**
     * Create and publish a message for one item to one group.
     * Photos are pre-uploaded; attachment IDs are passed in.
     *
     * @param  int[]  $attachmentIds
     * @return int|null Message ID on success, null on failure
     */
    private function postItemToGroup(
        array $item,
        string $body,
        Location $location,
        Group $group,
        array $attachmentIds
    ): ?int {
        $subject = $this->buildSubject($item);

        $messageId = $this->api->createMessage([
            'type' => 'Offer',
            'item' => $subject,
            'textbody' => $body,
            'locationid' => $location->id,
            'groupid' => $group->id,
            'collection' => 'Draft',
            'attachments' => $attachmentIds,
            'availableinitially' => $item['count'],
            'availablenow' => $item['count'],
        ]);

        if (! $messageId) {
            return null;
        }

        // Publish via JoinAndPost with forcepending=true so the message goes
        // to Pending regardless of user's posting status.
        $published = $this->api->publishMessage($messageId, $group->id, forcePending: true);

        if (! $published) {
            $this->warn("  Message {$messageId} created but failed to publish.");

            return null;
        }

        // Set deadline if provided.
        if (! empty($item['deadline'])) {
            $this->api->patchMessage($messageId, ['deadline' => $item['deadline']]);
        }

        return $messageId;
    }

    /**
     * Resolve cross-post group names into Group models.
     * Warns and skips any names that cannot be found or match the primary group.
     *
     * @return Group[]
     */
    private function resolveCrossPostGroups(?string $groupNames, Group $primaryGroup): array
    {
        if (! $groupNames) {
            return [];
        }

        $groups = [];

        foreach (array_filter(array_map('trim', explode(',', $groupNames))) as $name) {
            if (strcasecmp($name, $primaryGroup->nameshort) === 0) {
                $this->warn("Cross-post group '{$name}' is the same as the primary group — skipping.");

                continue;
            }

            $group = Group::where('nameshort', $name)->first()
                ?? Group::where('nameshort', 'LIKE', '%'.$name.'%')
                    ->where('type', Group::TYPE_FREEGLE)
                    ->first();

            if (! $group) {
                $this->warn("Cross-post group not found: {$name} — skipping.");

                continue;
            }

            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * Upload a photo file to TUS and create an API attachment record.
     *
     * @return int|null Attachment ID on success
     */
    private function uploadPhoto(string $path): ?int
    {
        $data = file_get_contents($path);
        if (! $data) {
            return null;
        }

        // Determine MIME type.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($data) ?: 'image/jpeg';

        // Upload to TUS.
        $tusUrl = $this->tus->upload($data, $mime);
        if (! $tusUrl) {
            return null;
        }

        // Create attachment record via API.
        $externalUid = TusService::urlToExternalUid($tusUrl);

        return $this->api->createImageAttachment($externalUid);
    }
}
