<?php
// Fast Layer-1 checker for the Laravel ORM conversions.
//
// Runs the Wave* parity assertions directly, without the full Laravel suite -
// whose two migrate:fresh passes dominate a ~10 minute cycle regardless of how
// few tests you filter to. This answers "does my conversion render the golden?"
// in about 0.2s, which is the difference between a conversion programme of this
// size being feasible and not.
//
// NOT a replacement for the suite, which stays the gate: this skips PHPUnit
// entirely, so no data providers, no expectException, no per-test isolation.
// Run it while converting; run the suite before believing anything.
//
// Usage, from the repo root:
//   docker cp tools/orm-migration/services/laravel/check-goldens.php \
//     <project>-batch:/tmp/check-goldens.php
//   docker exec <project>-batch php /tmp/check-goldens.php [ClassNameFilter]
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$dir = '/var/www/html/tests/Unit/OrmHarness';
$only = $argv[1] ?? '';
$pass = 0; $fail = 0; $fails = [];

// Only the conversion tests (Wave*). The harness's OWN self-tests are
// deliberately skipped: several of them swap in a fixture manifest to exercise
// the failure paths, and without PHPUnit's per-test isolation that poisons
// Manifest's static cache for everything that runs afterwards - which looked
// like "no manifest entry for site ..." on perfectly good sites. Those
// self-tests are covered by the real suite; this script only answers "does my
// conversion render the golden".
foreach (glob($dir . '/Wave*Test.php') as $file) {
    $base = basename($file, '.php');
    if ($only !== '' && !str_contains($base, $only)) { continue; }
    require_once $file;
    $class = 'Tests\\Unit\\OrmHarness\\' . $base;
    if (!class_exists($class)) { continue; }
    $rc = new ReflectionClass($class);
    if ($rc->isAbstract()) { continue; }
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if (!str_starts_with($m->getName(), 'test')) { continue; }
        $obj = $rc->newInstanceWithoutConstructor();
        try {
            $m->invoke($obj);
            $pass++;
        } catch (Throwable $e) {
            $fail++;
            $fails[] = $base . '::' . $m->getName() . "\n    " . str_replace("\n", "\n    ", trim($e->getMessage()));
        }
    }
}
echo "layer1: {$pass} passed, {$fail} failed\n";
foreach ($fails as $f) { echo "\nFAIL {$f}\n"; }
exit($fail > 0 ? 1 : 0);
