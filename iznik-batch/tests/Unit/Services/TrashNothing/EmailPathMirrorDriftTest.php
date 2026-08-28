<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the hand-maintained mirror between the two TN post-ingestion paths.
 *
 * GroupPostIngestionService is a deliberate line-by-line reimplementation of
 * IncomingMailService::handleGroupPost() + ::createGroupPostMessage() — see
 * that class's docblock. Nothing in the language or the type system ties the
 * two together, so a fix applied to the email path and not mirrored is
 * invisible: it compiles, the suite is green, and TN posts quietly diverge
 * from every other post on the site.
 *
 * tn:parity-check is the thorough answer, but it is an on-demand command that
 * needs live TN data and a disposable database — nobody runs it on a routine
 * PR, and once the cutover flag is on it is tn:verify-email-coverage (coverage
 * only, not behaviour) that runs on a schedule. This test is the cheap
 * always-on half: it does not check that the mirror is CORRECT, only that
 * nobody changed one side without looking at the other.
 *
 * WHEN THIS TEST FAILS you have edited the email path. Do this:
 *   1. Decide whether the change also applies to the API path. Most do.
 *   2. If so, make the matching change in GroupPostIngestionService.
 *   3. Re-pin the digest below to the value the failure message prints.
 *   4. If the change was comment-only or cosmetic, just re-pin — the
 *      normalisation below already drops whole-line // comments and blank
 *      lines, so a digest change means real code moved.
 *
 * Re-pinning without step 1 defeats the whole point. It takes ten seconds to
 * check; a silently unmirrored fix costs a lot more than that to find later.
 */
class EmailPathMirrorDriftTest extends TestCase
{
    /**
     * Digest of each mirrored email-path method, normalised as below.
     *
     * Regenerate with the value printed by this test's failure message.
     */
    private const PINNED_DIGESTS = [
        'handleGroupPost'         => '1b3f0c0b7eab7fd093661982e69d0669ec91e02a11833d71a575b9c42a2a0a2d',
        'createGroupPostMessage'  => '280231fd84ce35a88ec5e82658454b2c6487273b4d507735483ffa0c9dce0ecc',
    ];

    public static function mirroredMethods(): array
    {
        return [
            'handleGroupPost'        => ['handleGroupPost'],
            'createGroupPostMessage' => ['createGroupPostMessage'],
        ];
    }

    #[DataProvider('mirroredMethods')]
    public function test_the_email_path_has_not_changed_without_the_api_mirror_being_reviewed(string $method): void
    {
        $actual = self::digestOf(IncomingMailService::class, $method);

        $this->assertSame(
            self::PINNED_DIGESTS[$method],
            $actual,
            sprintf(
                "IncomingMailService::%s() has changed.\n\n"
                . "It is mirrored by hand in %s. Check whether this change also belongs there, "
                . "make it if so, then re-pin PINNED_DIGESTS['%s'] in %s to:\n\n    %s\n",
                $method,
                GroupPostIngestionService::class,
                $method,
                basename(__FILE__),
                $actual,
            ),
        );
    }

    public function test_the_api_path_mirror_still_exists(): void
    {
        // A rename or removal of either side must fail loudly rather than
        // leave the digests above pinned to something nothing mirrors.
        $this->assertTrue(
            (new ReflectionClass(GroupPostIngestionService::class))->hasMethod('ingest'),
            'GroupPostIngestionService::ingest() is the API-path mirror entry point.',
        );

        foreach (array_keys(self::PINNED_DIGESTS) as $method) {
            $this->assertTrue(
                (new ReflectionClass(IncomingMailService::class))->hasMethod($method),
                "IncomingMailService::{$method}() is pinned here but no longer exists — "
                . 'if it was renamed, update this test; if it was deleted, the API path is '
                . 'now the only implementation and this guard can go.',
            );
        }
    }

    /**
     * SHA-256 of a method's source with indentation, blank lines and whole-line
     * // comments removed, so reformatting and documentation churn do not fire
     * the guard but any change to the code itself does.
     */
    private static function digestOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $source     = file($reflection->getFileName());
        $body       = array_slice(
            $source,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        );

        $significant = [];
        foreach ($body as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $significant[] = $line;
        }

        return hash('sha256', implode("\n", $significant));
    }
}
