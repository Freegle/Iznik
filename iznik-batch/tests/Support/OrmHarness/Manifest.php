<?php

namespace Tests\Support\OrmHarness;

/**
 * Loads tools/orm-migration/services/laravel/manifest.json - the same
 * manifest tools/orm-migration/php-extractor/extract.php generates and
 * the extractor writes. This is the ONE place the harness reads
 * it from, so GoldenSql and ResultParity agree on the path and on caching.
 *
 * READS FROM A CONTAINER BIND MOUNT, NOT A RELATIVE PATH ESCAPE - found the
 * hard way. The first version of this class tried a relative walk upward
 * from __DIR__ on the (wrong) assumption that PHPUnit always runs from a
 * full repo checkout. It does not: the batch container's ONLY bind mounts
 * are iznik-batch itself and .git (docker-compose.yml's `batch` service),
 * so `tools/orm-migration/` does not exist inside it at all, and every
 * harness test errored with "file_get_contents(...): Failed to open
 * stream" - passing 48 assertions while still failing the run, the classic
 * PHPUnit "errors are invisible in the pass count" trap. The fix is the
 * honest one team lead specified rather than a workaround: docker-compose.yml
 * now bind-mounts tools/orm-migration/services/laravel read-only into the
 * batch container at /var/www/orm-migration-manifest, so the SAME file the
 * extractor writes is what the harness reads - not a
 * copy that could drift from the source of truth. A copy into
 * iznik-batch/tests/fixtures/ was considered and rejected for exactly that
 * reason (see the corpus-file precedent's own 3-copy-plus-sync-check design
 * in Canonical.php's header, which is only tolerable there because the
 * corpus is a small, rarely-changing pinning fixture - the manifest is the
 * living source of truth for the whole migration's status and must never be
 * read stale).
 *
 * The relative-path fallback still exists for contexts where the mount does
 * not apply (CI's checkout, or PHPUnit run directly on a host with the full
 * monorepo present) - but if NEITHER resolves, this fails LOUDLY with a
 * clear RuntimeException naming both paths it tried, rather than letting a
 * PHP warning silently produce an empty site list. A Layer 1/2 test that
 * cannot find its golden must never quietly no-op; "reports green while
 * checking nothing" is the failure this whole harness exists to rule out.
 *
 * Not embedded the way the (now removed) Go harness embedded its manifest.json
 * via go:embed: that embedding existed because the Go binary is compiled and
 * shipped into a container where nothing above iznik-server-go exists at
 * all, even via a mount - there is no equivalent "just bind-mount it" option
 * for a compiled binary. PHPUnit reads the filesystem at test-run time, so a
 * mount (or, in CI, a full checkout) is enough and avoids a build/embed step
 * this harness has no other reason to need.
 */
final class Manifest
{
    /**
     * Where docker-compose.yml's `batch` service (profile: dev) bind-mounts
     * tools/orm-migration/services/laravel, read-only.
     */
    private const CONTAINER_MOUNT_PATH = '/var/www/orm-migration-manifest/manifest.json';

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $sites = null;

    /**
     * @return array<string,array<string,mixed>> sites keyed by id
     */
    public static function sites(): array
    {
        if (self::$sites !== null) {
            return self::$sites;
        }

        $path = self::path();
        if ($path === null) {
            throw new \RuntimeException(sprintf(
                "ormharness: could not find the Laravel ORM migration manifest. Tried:\n".
                "  container mount: %s\n".
                "  repo-relative:   %s\n".
                'If running inside the batch container, check docker-compose.yml\'s `batch` service '.
                'mounts tools/orm-migration/services/laravel at /var/www/orm-migration-manifest (ro).',
                self::CONTAINER_MOUNT_PATH,
                self::relativePath()
            ));
        }

        // file_exists() was already checked in path(), so this is a read of
        // a file we just confirmed exists - file_get_contents() failing here
        // would be a genuine I/O error worth its own exception, not folded
        // into the "could not find it at all" message above.
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("ormharness: found the manifest at {$path} but could not read it");
        }

        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        self::$sites = $decoded['sites'] ?? [];

        return self::$sites;
    }

    /**
     * @return array<string,mixed>
     */
    public static function site(string $siteId): array
    {
        $sites = self::sites();
        if (! isset($sites[$siteId])) {
            throw new \RuntimeException(
                "ormharness: no manifest entry for site \"{$siteId}\"; this must be a real site id from ".
                'tools/orm-migration/services/laravel/manifest.json'
            );
        }

        return $sites[$siteId];
    }

    /**
     * Returns the first of the two candidate paths that actually exists, or
     * null if neither does - the caller decides how to fail on null, this
     * only decides where to look.
     */
    private static function path(): ?string
    {
        if (file_exists(self::CONTAINER_MOUNT_PATH)) {
            return self::CONTAINER_MOUNT_PATH;
        }

        $relative = self::relativePath();
        if (file_exists($relative)) {
            return $relative;
        }

        return null;
    }

    /**
     * iznik-batch/tests/Support/OrmHarness -> repo root is four levels up.
     * Only reachable when the full monorepo checkout is present alongside
     * iznik-batch (CI, or PHPUnit run on a host outside any container) -
     * see this class's header for why the container mount is checked first.
     */
    private static function relativePath(): string
    {
        $path = __DIR__.'/../../../../tools/orm-migration/services/laravel/manifest.json';

        return realpath($path) ?: $path;
    }

    /**
     * Test-only escape hatch, mirroring golden_test.go's
     * manifestPathOverride/resetManifestCacheForTest: lets a self-test of
     * the harness pin down exact site content, rather than depending on
     * real sites in services/laravel/manifest.json that are being actively
     * edited by the rest of the migration programme. Injects the array
     * directly rather than round-tripping through a temporary JSON file the
     * way the Go version does - this class controls its own cache, so
     * there is no equivalent of loadManifest()'s file-path indirection to
     * preserve for the test to exercise.
     *
     * @param  array<string,array<string,mixed>>  $sites
     */
    public static function setSitesForTest(array $sites): void
    {
        self::$sites = $sites;
    }

    /**
     * @see setSitesForTest
     */
    public static function resetCacheForTest(): void
    {
        self::$sites = null;
    }
}
