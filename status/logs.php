<?php
header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');

$container = $_GET['container'] ?? '';

// Sanitize container name
$container = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $container);

if (empty($container)) {
    http_response_code(400);
    echo "Container name required";
    exit;
}

// Get docker logs
$command = "docker logs --tail=100 " . escapeshellarg($container) . " 2>&1";
$output = shell_exec($command);

if ($output === null) {
    http_response_code(500);
    echo "Failed to get logs for container: $container";
} else {
    echo $output;
}
?>