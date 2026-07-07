<?php
/**
 * Custom phpMyAdmin configuration for Yesterday (behind 2FA proxy)
 * This file is loaded AFTER the main config.inc.php
 */

// Enable proxy support - phpMyAdmin will trust X-Forwarded headers
$cfg['PmaAbsoluteUri'] = 'https://yesterday.ilovefreegle.org:8447/';

// Set a fixed blowfish secret for consistent encryption
$cfg['blowfish_secret'] = 'YesterdayPhpMyAdmin2FASecure32';

// Configure session save path
$cfg['SessionSavePath'] = '/sessions';

// Trust proxy headers for IP detection
$cfg['TrustedProxies'] = ['172.18.0.0/16', '172.19.0.0/16', '172.19.0.0/16'];
