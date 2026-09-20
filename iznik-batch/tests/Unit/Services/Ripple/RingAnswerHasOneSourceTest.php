<?php

namespace Tests\Unit\Services\Ripple;

use Tests\TestCase;

/**
 * "Does an overflow ring admit this member" must have exactly one answer, and this
 * is the guard that keeps it that way.
 *
 * On 2026-08-21 the mail derived that answer from the ring column itself
 * while the website asked the spatial index. The two came apart, and members were
 * emailed posts that browse would not show them, that search could not find, that
 * the message page called "not reached yet", and whose replies the reply gate held
 * until the item was gone.
 *
 * The Go side is protected by the compiler: the SQL builders were deleted, so a
 * surface that wanted to answer for itself would have to write a new one. PHP has
 * no such backstop, hence this test. It is deliberately a crude string search: the
 * point is not to catch a clever reimplementation, it is to make the obvious
 * copy-paste fail loudly, with an explanation of why.
 */
class RingAnswerHasOneSourceTest extends TestCase
{
    public function test_no_php_tests_ring_geometry_in_sql(): void
    {
        $offenders = [];

        foreach ($this->phpSources(base_path('app')) as $file) {
            $src = file_get_contents($file);

            // The ring geometry parsed out of the JSON column, in any query.
            if (preg_match('/ST_GeomFromText\s*\(\s*JSON_UNQUOTE/i', $src)) {
                $offenders[] = $file.' parses a ring out of the ring column in SQL';
            }
            // Containment tested against the column directly.
            if (preg_match('/ST_Contains[^;]{0,200}overflow_(bounds|cells)/is', $src)) {
                $offenders[] = $file.' tests containment against the ring column in SQL';
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge([
            'Ring admission must come from App\Services\Ripple\RingIndex, which asks the',
            'spatial index - the same service, and so the same answer, that the feed, the',
            'badge, search, the message page and the reply gate use.',
            '',
            'Testing the ring here instead gives this path its own answer. That is what',
            'invited members to posts the site then refused. It is also slow: the rings',
            'average 37,000 vertices, and parsing them per candidate row cost 4.8s a page',
            'load on the read surfaces.',
            '',
            'Offending files:',
        ], $offenders)));
    }

    /**
     * Reading the ring column is fine - the bbox prefilter and the lane-presence
     * checks legitimately do - so this asserts only that nothing DECIDES
     * admission from it.
     *
     * @return iterable<string>
     */
    private function phpSources(string $dir): iterable
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
