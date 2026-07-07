<?php
/**
 * Creates realistic test data for the Freegle mobile app demo.
 * Run with: docker exec freegle-apiv1 php /var/www/iznik/install/create-mobile-test-data.php
 *
 * Creates 20 realistic items (offers and wanted) around Edinburgh,
 * with realistic descriptions, so the mobile app has something to show.
 */

namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

error_log("Creating mobile app test data...");

// Find existing group and user from testenv.php
$grows = $dbhm->preQuery("SELECT id, lat, lng FROM `groups` WHERE nameshort = 'FreeglePlayground' LIMIT 1");
if (empty($grows)) {
    die("Run testenv.php first to create the FreeglePlayground group\n");
}
$gid = $grows[0]['id'];
$glat = 55.9533;
$glng = -3.1883;

// Get the test postcode/location id
$lrows = $dbhm->preQuery("SELECT id FROM locations WHERE lat BETWEEN 55.9 AND 56.0 AND lng BETWEEN -3.3 AND -3.0 LIMIT 1");
$locationid = !empty($lrows) ? $lrows[0]['id'] : null;

// Test user IDs from testenv.php
$urows = $dbhm->preQuery("SELECT id FROM users ORDER BY id LIMIT 5");
$userIds = array_column($urows, 'id');

if (empty($userIds)) {
    die("Run testenv.php first to create test users\n");
}

// Realistic Edinburgh-area item data
$offers = [
    ['subject' => 'Blue IKEA Billy bookcase', 'body' => 'Good condition, just moving house. White with glass doors. 80cm wide. You will need a car as it comes flat-packed.', 'lat' => 55.9487, 'lng' => -3.1894],
    ['subject' => 'John Lewis 3-seater sofa', 'body' => 'Light grey fabric, 5 years old but in great condition. No tears or stains. Non-smoking home. Collection from EH3 - need two people to carry it.', 'lat' => 55.9621, 'lng' => -3.2011],
    ['subject' => 'Children\'s bikes x2', 'body' => 'Two kids\' bikes, one 16 inch and one 20 inch. Both need a clean and the tyres could do with some air but otherwise work fine. Ages 5-9 roughly.', 'lat' => 55.9399, 'lng' => -3.1654],
    ['subject' => 'Bag of women\'s clothes size 12', 'body' => 'Mixture of tops, jeans, jumpers. All clean and good condition. Happy to photograph specific items if you\'re interested.', 'lat' => 55.9551, 'lng' => -3.1723],
    ['subject' => 'Old iPhone SE (2020)', 'body' => 'Fully working, 64GB, screen has a small scratch in the corner but display is fine. Comes with charger. Factory reset, ready to use.', 'lat' => 55.9476, 'lng' => -3.2104],
    ['subject' => 'Rowing machine', 'body' => 'Bought it with good intentions! Water resistance type, works perfectly. Folds flat for storage. Collection only from EH14 - it\'s heavy!', 'lat' => 55.9298, 'lng' => -3.2456],
    ['subject' => 'Large bag of LEGO', 'body' => 'About 5kg of mixed LEGO from the 1990s-2000s. No instructions. Great for creative play. Collection from Morningside.', 'lat' => 55.9283, 'lng' => -3.2034],
    ['subject' => 'Kitchen table + 4 chairs', 'body' => 'Pine kitchen table with 4 matching chairs. Some marks on the table surface but structurally solid. Ideal for a first home.', 'lat' => 55.9614, 'lng' => -3.1545],
    ['subject' => 'Box of books - crime & thrillers', 'body' => 'About 30 paperbacks, mostly crime/thriller genre. Lee Child, Val McDermid, Ian Rankin, etc. Take as many or as few as you like.', 'lat' => 55.9512, 'lng' => -3.1988],
    ['subject' => 'Baby bouncer chair', 'body' => 'Fisher Price baby bouncer with vibration setting. Clean, washed cover. Used for about 6 months. My little one has grown out of it!', 'lat' => 55.9447, 'lng' => -3.1712],
    ['subject' => 'Garden tools - spade, fork, rake', 'body' => 'Three tools in good usable condition. Nothing fancy but all work fine. Collection from back garden, EH16.', 'lat' => 55.9333, 'lng' => -3.1545],
    ['subject' => 'Yoga mat and blocks', 'body' => 'Purple yoga mat (6mm thick) and two cork blocks. Used a handful of times. Good quality, just not getting the use.', 'lat' => 55.9589, 'lng' => -3.1834],
];

$wanted = [
    ['subject' => 'Single bed frame', 'body' => 'Looking for a single bed frame in reasonable condition for my son\'s room. Any style considered. We have a van so can collect.', 'lat' => 55.9467, 'lng' => -3.1923],
    ['subject' => 'Slow cooker', 'body' => 'Would love a slow cooker - any brand or size. Perfect for batch cooking! Happy to collect anywhere in Edinburgh.', 'lat' => 55.9534, 'lng' => -3.1756],
    ['subject' => 'Women\'s walking boots size 7', 'body' => 'After a pair of sturdy walking boots for a trip to the Highlands. Waterproof preferred. Can collect anywhere in the city.', 'lat' => 55.9612, 'lng' => -3.1892],
    ['subject' => 'Children\'s books ages 3-6', 'body' => 'Any picture books or early readers welcome. My daughter is going through books at an incredible rate!', 'lat' => 55.9388, 'lng' => -3.2123],
    ['subject' => 'Monitor - any size', 'body' => 'Need a second monitor for working from home. Any size from 21 inches up. Happy to collect.', 'lat' => 55.9501, 'lng' => -3.1634],
    ['subject' => 'Sewing machine', 'body' => 'Taking up sewing as a hobby and looking for a basic machine to practice on. Doesn\'t need all the bells and whistles!', 'lat' => 55.9445, 'lng' => -3.2234],
    ['subject' => 'Dog crate / cage', 'body' => 'Getting a puppy and need a crate for training. Medium or large size would suit. Happy to travel to collect.', 'lat' => 55.9677, 'lng' => -3.1712],
    ['subject' => 'Coffee table', 'body' => 'Looking for a coffee table for our living room - any style welcome. We have a car and can collect.', 'lat' => 55.9523, 'lng' => -3.1889],
];

$allItems = array_merge(
    array_map(fn($i) => array_merge($i, ['type' => 'Offer']), $offers),
    array_map(fn($i) => array_merge($i, ['type' => 'Wanted']), $wanted)
);

$createdIds = [];

foreach ($allItems as $idx => $item) {
    $userId = $userIds[$idx % count($userIds)];
    $type = $item['type'];
    $prefix = $type === 'Offer' ? 'OFFER' : 'WANTED';
    $subject = "$prefix: {$item['subject']} (Edinburgh)";

    // Vary the arrival time - spread over past 7 days
    $daysAgo = $idx % 7;
    $hoursAgo = ($idx * 3) % 24;
    $arrival = date('Y-m-d H:i:s', strtotime("-$daysAgo days -$hoursAgo hours"));

    // Insert into messages table
    $dbhm->preExec("INSERT INTO messages
        (arrival, date, source, fromuser, subject, type, textbody, lat, lng, availablenow)
        VALUES (?, ?, 'Platform', ?, ?, ?, ?, ?, ?, 1)",
        [$arrival, $arrival, $userId, $subject, $type, $item['body'], $item['lat'], $item['lng']]
    );
    $msgId = $dbhm->lastInsertId();

    if (!$msgId) {
        error_log("Failed to create message: $subject");
        continue;
    }

    // Insert into messages_groups
    $dbhm->preExec("INSERT INTO messages_groups
        (msgid, groupid, arrival, collection, approvedby, msgtype)
        VALUES (?, ?, ?, 'Approved', ?, ?)",
        [$msgId, $gid, $arrival, $userIds[0], $type]
    );

    // Insert into messages_spatial (for geo queries - uses SRID 3857 point geometry)
    $dbhm->preExec("INSERT INTO messages_spatial
        (msgid, groupid, point, successful, promised, msgtype, arrival)
        VALUES (?, ?, ST_GeomFromText(?, 3857), 0, 0, ?, ?)",
        [$msgId, $gid, "POINT({$item['lng']} {$item['lat']})", $type, $arrival]
    );

    // Insert location link if we have one
    if ($locationid) {
        $dbhm->preExec("UPDATE messages SET locationid = ? WHERE id = ?", [$locationid, $msgId]);
    }

    $createdIds[] = $msgId;
    error_log("Created: [$type] $subject (ID: $msgId, $daysAgo days ago)");
}

$total = count($createdIds);
echo "Created $total test messages in FreeglePlayground (Edinburgh area)\n";
echo "Messages are spread across the past 7 days\n";
echo "App should show items when postcode EH1, EH3, EH8, etc. is set\n";
