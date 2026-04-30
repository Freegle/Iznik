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

echo 'Seed complete. Users seeded: ' . count($seedUsers) . "\n";
echo "Running tn_sync.php...\n";

$command = sprintf('php %s > %s 2>&1', escapeshellarg($rootDir . '/iznik/scripts/cron/tn_sync.php'), escapeshellarg($logFile));
passthru($command, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "tn_sync failed with exit code $exitCode. Log: $logFile\n");
    exit($exitCode);
}

echo "tn_sync finished. Log: $logFile\n";
