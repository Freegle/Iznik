#!/usr/bin/env php
<?php

declare(strict_types=1);

$rootDir = realpath(__DIR__ . '/../../../..');

if ($rootDir === false) {
    fwrite(STDERR, "Failed to resolve repository root\n");
    exit(1);
}

$fixturesDir = $rootDir . '/iznik/test/integration/tn_sync/fixtures';
$logFile = $argv[1] ?? ('/tmp/tn_sync_seeded_' . date('Ymd_His') . '.log');

define('BASE_DIR', $rootDir . '/iznik');
require_once BASE_DIR . '/include/config.php';
require_once IZNIK_BASE . '/include/db.php';

global $dbhr, $dbhm;

function loadFixture(string $path, string $key): array
{
    if (!file_exists($path)) {
        throw new RuntimeException("Missing fixture file: $path");
    }

    $decoded = json_decode(file_get_contents($path), true);

    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON fixture: $path");
    }

    $items = $decoded[$key] ?? [];

    if (!is_array($items)) {
        throw new RuntimeException("Fixture key $key is not an array in: $path");
    }

    return $items;
}

function upsertTNUser($dbhm, int $userId, int $tnUserId, string $username): void
{
    $email = sprintf('%s-g%d@user.trashnothing.com', $username, $tnUserId);

    $dbhm->preExec(
        "INSERT INTO users (id, firstname, lastname, fullname, systemrole, added, lastaccess, tnuserid, deleted, forgotten) VALUES (?, NULL, NULL, ?, 'User', NOW(), NOW(), ?, NULL, NULL) ON DUPLICATE KEY UPDATE fullname = VALUES(fullname), tnuserid = VALUES(tnuserid), deleted = NULL, forgotten = NULL;",
        [$userId, $username, $tnUserId]
    );

    $dbhm->preExec('UPDATE users_emails SET preferred = 0 WHERE userid = ?;', [$userId]);

    $dbhm->preExec(
        'INSERT INTO users_emails (userid, email, preferred, canon, backwards, validated) VALUES (?, ?, 1, ?, ?, NOW()) ON DUPLICATE KEY UPDATE userid = VALUES(userid), preferred = 1, canon = VALUES(canon), backwards = VALUES(backwards), validated = NOW();',
        [$userId, $email, strtolower($email), strrev(strtolower($email))]
    );
}

echo "Seeding TN sync fixture users and rows...\n";

$ratings = loadFixture($fixturesDir . '/ratings_page_1.json', 'ratings');
$changes = loadFixture($fixturesDir . '/user_changes_page_1.json', 'changes');

$seedUsers = [];

foreach ($ratings as $rating) {
    if (!isset($rating['ratee_fd_user_id'], $rating['ratee_tn_user_id'], $rating['ratee_username'])) {
        continue;
    }

    if ($rating['ratee_fd_user_id'] === null) {
        continue;
    }

    $seedUsers[(int) $rating['ratee_fd_user_id']] = [
        'tn_user_id' => (int) $rating['ratee_tn_user_id'],
        'username' => (string) $rating['ratee_username'],
    ];
}

foreach ($changes as $change) {
    if (!isset($change['fd_user_id'], $change['tn_user_id'], $change['username'])) {
        continue;
    }

    if ($change['fd_user_id'] === null) {
        continue;
    }

    $seedUsers[(int) $change['fd_user_id']] = [
        'tn_user_id' => (int) $change['tn_user_id'],
        'username' => (string) $change['username'],
    ];
}

if (isset($seedUsers[510002])) {
    $seedUsers[510002]['username'] = 'test_blair';
}

foreach ($seedUsers as $userId => $userData) {
    if ($userId === 599999) {
        continue;
    }

    upsertTNUser($dbhm, $userId, $userData['tn_user_id'], $userData['username']);
}

$dbhm->preExec(
    'INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), timestamp = VALUES(timestamp);',
    [510003, 'Up', '2026-04-29T23:59:59', 9001003]
);

$dbhm->preExec(
    'INSERT INTO ratings (ratee, rating, timestamp, visible, tn_rating_id) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), timestamp = VALUES(timestamp);',
    [510001, 'Down', '2026-04-29T23:58:00', 9001006]
);

// Seed two FD users with the same TN username prefix to trigger the duplicate-merge code path.
// The email format is "{username}-g{tn_user_id}@user.trashnothing.com", so different tn_user_ids
// produce different emails but REGEXP_REPLACE extracts the same username for both, causing the
// dup-scan query to find them as duplicates and merge one into the other.
upsertTNUser($dbhm, 510010, 820001, 'test_dup_user');
upsertTNUser($dbhm, 510011, 820002, 'test_dup_user');

// Seed a test postcode near user 510001's fixture coordinates (lat=51.5074, lng=-0.1278).
// This lets closestPostcode() return a result so the [LOCATION] trace fires during the sync.
// User 510001 is inserted with lastlocation=NULL, so any returned loc triggers the update.
$srid = $dbhm->SRID();
$locLat = 51.5074;
$locLng = -0.1278;
$locName = 'EC1A 1BB';
$dbhm->preExec(
    "INSERT INTO locations (name, type, lat, lng, geometry) VALUES (?, 'Postcode', ?, ?, ST_GeomFromText(?, $srid));",
    [$locName, $locLat, $locLng, "POINT($locLng $locLat)"]
);
$testLocId = $dbhm->lastInsertId();
$dbhm->preExec(
    "INSERT INTO locations_spatial (locationid, geometry) VALUES (?, ST_GeomFromText(?, $srid));",
    [$testLocId, "POINT($locLng $locLat)"]
);

echo 'Seed complete. Users seeded: ' . count($seedUsers) . "\n";
echo "Running tn_sync.php...\n";

$command = sprintf('php %s > %s 2>&1', escapeshellarg($rootDir . '/iznik/scripts/cron/tn_sync.php'), escapeshellarg($logFile));
passthru($command, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "tn_sync failed with exit code $exitCode. Log: $logFile\n");
    exit($exitCode);
}

echo "tn_sync finished. Log: $logFile\n";

// Assertions
$failures = [];

function assertQuery(string $label, $dbhr, string $sql, array $params, callable $check): void
{
    global $failures;
    $rows = $dbhr->preQuery($sql, $params);
    if (!$check($rows)) {
        $failures[] = "$label: assertion failed. Rows: " . json_encode($rows);
        echo "FAIL: $label\n";
    } else {
        echo "PASS: $label\n";
    }
}

// --- forget flow ---
// User 510004 (test_removed) had account_removed=true in the fixture.
// After sync, forget() should have set forgotten=NOW() and fullname="Deleted User #510004".

assertQuery(
    'forgotten user 510004: users.forgotten is not null',
    $dbhr,
    'SELECT forgotten FROM users WHERE id = ?',
    [510004],
    fn($rows) => count($rows) === 1 && $rows[0]['forgotten'] !== null
);

assertQuery(
    'forgotten user 510004: users.fullname is "Deleted User #510004"',
    $dbhr,
    'SELECT fullname FROM users WHERE id = ?',
    [510004],
    fn($rows) => count($rows) === 1 && $rows[0]['fullname'] === 'Deleted User #510004'
);

assertQuery(
    'forgotten user 510004: users.tnuserid is null',
    $dbhr,
    'SELECT tnuserid FROM users WHERE id = ?',
    [510004],
    fn($rows) => count($rows) === 1 && $rows[0]['tnuserid'] === null
);

assertQuery(
    'forgotten user 510004: trashnothing email removed',
    $dbhr,
    "SELECT COUNT(*) AS cnt FROM users_emails WHERE userid = ? AND email LIKE '%@user.trashnothing.com'",
    [510004],
    fn($rows) => count($rows) === 1 && (int) $rows[0]['cnt'] === 0
);

assertQuery(
    'forgotten user 510004: users_logins deleted',
    $dbhr,
    'SELECT COUNT(*) AS cnt FROM users_logins WHERE userid = ?',
    [510004],
    fn($rows) => count($rows) === 1 && (int) $rows[0]['cnt'] === 0
);

// Sanity check: non-removed users are NOT forgotten.
assertQuery(
    'non-removed user 510001: users.forgotten remains null',
    $dbhr,
    'SELECT forgotten FROM users WHERE id = ?',
    [510001],
    fn($rows) => count($rows) === 1 && $rows[0]['forgotten'] === null
);

// --- merge flow ---
// Users 510010 and 510011 share username prefix 'test_dup_user', triggering the dup-scan merge.
// After merge: one user is deleted from users; the survivor owns both TN emails.

assertQuery(
    'merged dup users: exactly one of 510010/510011 survives in users',
    $dbhr,
    'SELECT COUNT(*) AS cnt FROM users WHERE id IN (510010, 510011)',
    [],
    fn($rows) => count($rows) === 1 && (int) $rows[0]['cnt'] === 1
);

assertQuery(
    'merged dup users: both TN emails owned by the same surviving user',
    $dbhr,
    "SELECT COUNT(DISTINCT userid) AS distinct_owners FROM users_emails WHERE email LIKE 'test\\_dup\\_user-%@user.trashnothing.com'",
    [],
    fn($rows) => count($rows) === 1 && (int) $rows[0]['distinct_owners'] === 1
);

assertQuery(
    'merged dup users: no remaining duplicate detected by dup-scan query',
    $dbhr,
    "SELECT COUNT(DISTINCT(userid)) AS cnt FROM users_emails WHERE REGEXP_REPLACE(email, '(.*)-g[0-9]+@user\\\\.trashnothing\\\\.com', '\$1') = 'test_dup_user' AND email LIKE '%@user.trashnothing.com'",
    [],
    fn($rows) => count($rows) === 1 && (int) $rows[0]['cnt'] === 1
);

// --- location flow ---
// User 510001 has lat/lng in the user_changes fixture and lastlocation=NULL before the sync.
// The seeded EC1A 1BB postcode sits at the same coordinates, so closestPostcode() returns it
// and setPrivate('lastlocation', ...) updates the users row.

assertQuery(
    'location updated user 510001: lastlocation is not null',
    $dbhr,
    'SELECT lastlocation FROM users WHERE id = ?',
    [510001],
    fn($rows) => count($rows) === 1 && $rows[0]['lastlocation'] !== null
);

// --- end assertions ---

if (count($failures) > 0) {
    fwrite(STDERR, "\n" . count($failures) . " assertion(s) failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}

echo "\nAll assertions passed.\n";
