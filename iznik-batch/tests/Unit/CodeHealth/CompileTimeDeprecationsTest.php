<?php

namespace Tests\Unit\CodeHealth;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * No PHP file of ours may raise a deprecation when it is compiled.
 *
 * Compile-time deprecations (an implicitly nullable parameter, deprecated syntax)
 * are raised when PHP compiles a file, not when it runs. With opcache on for the
 * CLI (docker/php-cli-opcache.ini) a file is compiled once and then served from the
 * cache, so a deprecation is printed on a cold cache and never seen again on a warm
 * one, and PHPUnit's own deprecation reporting stops naming them. This lints every
 * file with opcache off and with every error reported, so the check does not depend
 * on what is in the cache. One PHP process lints many files at once (PHP 8.3+), so
 * the whole tree takes about a second.
 *
 * ImplicitNullableParametersTest covers the commonest case with a clearer message;
 * this catches the rest, including any a PHP or dependency upgrade brings in.
 */
class CompileTimeDeprecationsTest extends TestCase
{
    private const DIRECTORIES = ['app', 'tests', 'routes', 'config', 'bootstrap', 'database'];

    public function test_no_file_raises_a_deprecation_when_compiled(): void
    {
        $base = base_path();
        $files = [];
        foreach (self::DIRECTORIES as $dir) {
            if (! is_dir("{$base}/{$dir}")) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$base}/{$dir}", \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $this->assertGreaterThan(1000, count($files), 'the lint should see the whole tree');

        $problems = [];
        foreach (array_chunk($files, 400) as $chunk) {
            $process = new Process(array_merge(
                [PHP_BINARY, '-d', 'opcache.enable_cli=0', '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l'],
                $chunk
            ));
            $process->setTimeout(120);
            $process->run();

            foreach (preg_split('/\R/', $process->getOutput().$process->getErrorOutput()) as $line) {
                if (preg_match('/^(PHP )?(Deprecated|Parse error|Fatal error|Warning):/', trim($line))) {
                    $problems[] = str_replace($base.'/', '', trim($line));
                }
            }
        }

        $this->assertSame([], $problems, "Compiling these files raises:\n".implode("\n", $problems));
    }
}
