<?php

namespace App\Services;

/**
 * Sets up, announces and closes the annual AGM category on Discourse.
 *
 * The AGM runs once a year in its own category: anyone may read and reply, but
 * only staff and the Announcers group may start topics, and every Discourse
 * user is put on "Watching" so they see the announcements. This service does
 * that in three deliberately separate steps.
 *
 * The separation matters. Watching is switched on by adding the category to the
 * `default_categories_watching` site setting, and from that moment every new
 * post notifies every user - so the category has to be created and filled with
 * the information posts *before* the announcement is made, not at the same time.
 */
class AgmCategoryService
{
    /**
     * Discourse category permission types, from
     * CategoryGroup.permission_types: 1 full (create/reply/see),
     * 2 create_post (reply/see), 3 readonly (see).
     */
    private const PERMISSION_CREATE = 1;

    private const PERMISSION_REPLY = 2;

    private const PERMISSION_SEE = 3;

    public const WATCHING_SETTING = 'default_categories_watching';

    public function __construct(private DiscourseClient $client) {}

    public function categoryName(int $year): string
    {
        return "AGM {$year}";
    }

    public function categorySlug(int $year): string
    {
        return "agm-{$year}";
    }

    /**
     * Create (or re-apply settings to) this year's AGM category.
     *
     * Deliberately does not switch Watching on - see the class docblock, and run
     * announce() once the information posts are in place.
     *
     * @return array<string, mixed>
     */
    public function setup(int $year, bool $dryRun = false): array
    {
        if (!$this->client->isConfigured()) {
            return ['skipped' => true];
        }

        $name = $this->categoryName($year);
        $slug = $this->categorySlug($year);
        $existing = $this->client->findCategoryBySlug($slug);
        $permissions = $this->permissions(open: true);

        $result = [
            'skipped' => false,
            'dryRun' => $dryRun,
            'name' => $name,
            'slug' => $slug,
            'created' => $existing === null,
            'permissions' => $permissions,
            'categoryId' => $existing === null ? null : (int) $existing['id'],
        ];

        if ($dryRun) {
            return $result;
        }

        if ($existing === null) {
            // Discourse seeds the "About the ... category" topic from the
            // description passed at creation time (Category#create_category_definition
            // builds the first post with `raw: description || post_template`).
            $category = $this->client->createCategory([
                'name' => $name,
                'slug' => $slug,
                'color' => (string) config('freegle.discourse.agm.colour'),
                'text_color' => (string) config('freegle.discourse.agm.text_colour'),
                'description' => $this->description($name),
                'permissions' => $permissions,
            ]);

            $result['categoryId'] = (int) ($category['id'] ?? 0);

            return $result;
        }

        // Re-applying to an existing category fixes its permissions but leaves
        // the description alone: on update Discourse takes the description from
        // the About topic's first post, so changing it means editing that post.
        $this->client->updateCategory((int) $existing['id'], [
            'name' => $name,
            'slug' => $slug,
            'color' => (string) ($existing['color'] ?? config('freegle.discourse.agm.colour')),
            'text_color' => (string) ($existing['text_color'] ?? config('freegle.discourse.agm.text_colour')),
            'permissions' => $permissions,
        ]);

        return $result;
    }

    /**
     * Put every Discourse user on Watching for this year's AGM category.
     *
     * @return array<string, mixed>
     */
    public function announce(int $year, bool $force = false, bool $dryRun = false): array
    {
        if (!$this->client->isConfigured()) {
            return ['skipped' => true];
        }

        $id = (string) $this->requireCategory($year, 'Run discourse:agm setup first.')['id'];
        $current = $this->client->getSiteSetting(self::WATCHING_SETTING);
        $ids = $this->splitIds($current);
        $already = in_array($id, $ids, true);

        $without = array_values(array_diff($ids, [$id]));
        $with = array_merge($without, [$id]);

        $result = [
            'skipped' => false,
            'dryRun' => $dryRun,
            'categoryId' => (int) $id,
            'alreadyWatching' => $already,
            'previous' => $current,
            'value' => $this->joinIds($with),
            'backfilled' => false,
        ];

        // Discourse backfills existing users by diffing the previous value
        // against the new one, so re-sending a value that is already set never
        // touches a user however the backfill flag is set. Without --force,
        // say so rather than pretending a no-op did something.
        if ($already && !$force) {
            return $result;
        }

        if ($dryRun) {
            return $result;
        }

        if ($already) {
            // Drop it first so the re-add is a real change and the backfill runs.
            $this->client->updateSiteSetting(self::WATCHING_SETTING, $this->joinIds($without), false);
        }

        $this->client->updateSiteSetting(self::WATCHING_SETTING, $this->joinIds($with), true);
        $result['backfilled'] = true;

        return $result;
    }

    /**
     * Close a finished AGM: stop notifying everyone and make it read-only.
     *
     * Removing the category from the watching setting with the backfill flag
     * deletes the Watching rows it created and resets auto-watched topics to
     * Regular, so this genuinely undoes announce() rather than leaving stale
     * rows behind. The category and its topics are kept, readable.
     *
     * @return array<string, mixed>
     */
    public function close(int $year, bool $dryRun = false): array
    {
        if (!$this->client->isConfigured()) {
            return ['skipped' => true];
        }

        $category = $this->requireCategory($year, 'There is nothing to close.');
        $id = (string) $category['id'];
        $current = $this->client->getSiteSetting(self::WATCHING_SETTING);
        $ids = $this->splitIds($current);
        $without = array_values(array_diff($ids, [$id]));
        $wasWatching = count($without) !== count($ids);
        $permissions = $this->permissions(open: false);

        $result = [
            'skipped' => false,
            'dryRun' => $dryRun,
            'categoryId' => (int) $id,
            'wasWatching' => $wasWatching,
            'value' => $this->joinIds($without),
            'permissions' => $permissions,
        ];

        if ($dryRun) {
            return $result;
        }

        if ($wasWatching) {
            $this->client->updateSiteSetting(self::WATCHING_SETTING, $this->joinIds($without), true);
        }

        $this->client->updateCategory((int) $id, [
            'name' => (string) ($category['name'] ?? $this->categoryName($year)),
            'slug' => $this->categorySlug($year),
            'color' => (string) ($category['color'] ?? config('freegle.discourse.agm.colour')),
            'text_color' => (string) ($category['text_color'] ?? config('freegle.discourse.agm.text_colour')),
            'permissions' => $permissions,
        ]);

        return $result;
    }

    /** @return array<string, mixed> */
    private function requireCategory(int $year, string $hint): array
    {
        $slug = $this->categorySlug($year);
        $category = $this->client->findCategoryBySlug($slug);

        if ($category === null) {
            throw new \RuntimeException("Discourse category '{$slug}' does not exist. {$hint}");
        }

        return $category;
    }

    /**
     * Everyone may reply while the AGM is open and only see it once closed;
     * staff keep full rights throughout so they can still moderate.
     *
     * @return array<string, int>
     */
    private function permissions(bool $open): array
    {
        $announcers = (string) config('freegle.discourse.agm.announcers_group', 'Announcers');

        return [
            'everyone' => $open ? self::PERMISSION_REPLY : self::PERMISSION_SEE,
            'staff' => self::PERMISSION_CREATE,
            $announcers => $open ? self::PERMISSION_CREATE : self::PERMISSION_SEE,
        ];
    }

    private function description(string $name): string
    {
        return str_replace(':name', $name, (string) config('freegle.discourse.agm.description'));
    }

    /** @return array<int, string> */
    private function splitIds(string $value): array
    {
        return array_values(array_filter(explode('|', $value), static fn ($id) => $id !== ''));
    }

    /** @param  array<int, string>  $ids */
    private function joinIds(array $ids): string
    {
        return implode('|', $ids);
    }
}
