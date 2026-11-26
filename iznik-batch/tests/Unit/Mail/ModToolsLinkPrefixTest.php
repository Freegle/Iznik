<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * ModTools is served at the root of config('freegle.sites.mod')
 * (https://modtools.org), so a link built as {modSite}/modtools/... does not
 * hit the page it names. It lands on the catch-all redirect shim at
 * iznik-nuxt3/modtools/pages/modtools/[...slug].vue, which strips the prefix
 * and bounces the moderator on after a two second wait.
 *
 * PushNotificationService already carries this rule for push routes (Discourse
 * #9692/10). This guard extends it to every link builder in the batch app, so
 * the prefix cannot creep back into a mail template or service.
 */
class ModToolsLinkPrefixTest extends TestCase
{
    public function test_no_source_file_builds_a_modtools_prefixed_link(): void
    {
        $offenders = [];

        foreach (['app', 'resources/views'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path) {
                foreach (file($path) as $i => $line) {
                    if ($this->isComment($line)) {
                        continue;
                    }

                    // A path segment "/modtools" followed by another segment or
                    // the end of the URL. Deliberately does not match
                    // modtools_logo.png, channel ids, or dotted Blade includes.
                    if (preg_match('#/modtools(/|["\'])#', $line)) {
                        $rel = str_replace(base_path() . '/', '', $path);
                        $offenders[] = $rel . ':' . ($i + 1) . '  ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Link(s) built with a /modtools prefix. ModTools is served at the "
            . "root of freegle.sites.mod, so drop the prefix and link straight "
            . "to the page:\n" . implode("\n", $offenders)
        );
    }

    /**
     * @return iterable<string>
     */
    private function phpFilesIn(string $root): iterable
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }

    private function isComment(string $line): bool
    {
        $t = ltrim($line);

        return str_starts_with($t, '//')
            || str_starts_with($t, '*')
            || str_starts_with($t, '/*')
            || str_starts_with($t, '#')
            || str_starts_with($t, '{{--');
    }
}
