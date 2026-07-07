<?php
/**
 * Update /etc/iznik.conf with runtime environment variables.
 * Called from the Dockerfile CMD at container start.
 *
 * Uses PHP preg_replace instead of sed — immune to shell quoting issues
 * when env var values contain @, \, ", or other special characters.
 */

$conf_path = '/etc/iznik.conf';
$conf = file_get_contents($conf_path);

if ($conf === false) {
    fwrite(STDERR, "ERROR: Cannot read $conf_path\n");
    exit(1);
}

/**
 * Replace a define() value in the config.
 * Handles: define('KEY', 'value'), define('KEY', "value"), define('KEY', NULL),
 *          define('KEY', FALSE), define('KEY', TRUE), define('KEY', 123)
 */
function set_config(string &$conf, string $key, ?string $envVar = null, ?string $envName = null): void {
    $val = $envVar ?? getenv($envName ?: $key);
    if ($val === false || $val === '') {
        return;
    }
    $escaped = addcslashes($val, "'\\");
    $pattern = "/define\('$key',\s*(?:'[^']*'|\"[^\"]*\"|NULL|FALSE|TRUE|\d+)\)/";
    $replacement = "define('$key', '$escaped')";
    $conf = preg_replace($pattern, $replacement, $conf);
}

function set_config_int(string &$conf, string $key): void {
    $val = getenv($key);
    if ($val === false || $val === '') {
        return;
    }
    $intVal = intval($val);
    $pattern = "/define\('$key',\s*\d+\)/";
    $replacement = "define('$key', $intVal)";
    $conf = preg_replace($pattern, $replacement, $conf);
}

function set_config_bool(string &$conf, string $key, bool $value): void {
    $from = $value ? 'FALSE' : 'TRUE';
    $to = $value ? 'TRUE' : 'FALSE';
    $pattern = "/define\('$key',\s*$from\)/";
    $replacement = "define('$key', $to)";
    $conf = preg_replace($pattern, $replacement, $conf);
}

// String config values from environment
$string_vars = [
    'LOVE_JUNK_API',
    'LOVE_JUNK_SECRET',
    'IMAGE_DOMAIN',
    'GOOGLE_CLIENT_ID',
    'GOOGLE_CLIENT_SECRET',
    'GOOGLE_PUSH_KEY',
    'GOOGLE_VISION_KEY',
    'GOOGLE_PERSPECTIVE_KEY',
    'GOOGLE_GEMINI_API_KEY',
    'GOOGLE_PROJECT',
    'GOOGLE_APP_NAME',
    'SPAMD_HOST',
    'TUS_UPLOADER',
    'IMAGE_DELIVERY',
    'SMTP_HOST',
    'LOKI_JSON_PATH',
    'SQLDB',
];

foreach ($string_vars as $var) {
    set_config($conf, $var);
}

// MAPBOX_TOKEN config key uses MAPBOX_KEY env var
set_config($conf, 'MAPBOX_TOKEN', getenv('MAPBOX_KEY') ?: null);

// Numeric values
set_config_int($conf, 'SMTP_PORT');

// Enable Loki logging (respect LOKI_ENABLED env var — phpunit container sets it to false)
$lokiEnv = getenv('LOKI_ENABLED');
if ($lokiEnv !== false && strtolower($lokiEnv) === 'false') {
    // Explicitly disabled — leave as FALSE (the config default)
} else {
    set_config_bool($conf, 'LOKI_ENABLED', true);
}

// Write updated config
if (file_put_contents($conf_path, $conf) === false) {
    fwrite(STDERR, "ERROR: Cannot write $conf_path\n");
    exit(1);
}

echo "Config updated successfully\n";
