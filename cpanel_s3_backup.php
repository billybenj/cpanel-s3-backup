<?php
/**
 * cPanel S3-Compatible Multi-Destination Backup Script
 * 
 * Creates ONE cPanel backup, uploads to MULTIPLE storage destinations.
 * Supports: MinIO, Backblaze B2, AWS S3, Wasabi (all S3-compatible)
 * 
 * FEATURES:
 * - Full cPanel backup via API (files, databases, email, configs, SSL, cron, etc.)
 * - Multiple storage destinations in one run
 * - AWS Signature V4 (works with all modern S3-compatible storage)
 * - Configurable path per account for organization
 * - Retention cleanup on all destinations
 * - Email notifications with per-destination status
 * 
 * SETUP:
 * 1. Create cpanel_s3_config.ini with your credentials
 * 2. chmod 600 cpanel_s3_config.ini
 * 3. Place both files OUTSIDE public_html
 * 4. Cron: 0 3 * * * /usr/local/bin/php /home/user/scripts/cpanel_s3_backup.php
 * 
 * @version 2.1
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

// Check requirements
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    die("Requires PHP 7.4+. Current: " . PHP_VERSION . "\n");
}

foreach (['curl', 'json', 'openssl', 'hash'] as $ext) {
    if (!extension_loaded($ext)) {
        die("Missing PHP extension: {$ext}\n");
    }
}

// Load configuration
$config_file = __DIR__ . '/cpanel_s3_config.ini';
if (!file_exists($config_file)) {
    die("Config not found: {$config_file}\n");
}

$config = parse_ini_file($config_file, true);
if ($config === false) {
    die("Failed to parse config file.\n");
}

// Security check
$perms = fileperms($config_file) & 0777;
if ($perms > 0600) {
    error_log("WARNING: Config has insecure permissions: " . decoct($perms) . ". Use: chmod 600 {$config_file}");
}

// Extract configuration sections
$cp = $config['cpanel'] ?? [];
$backup_config = $config['backup'] ?? [];
$storage_config = $config['storage'] ?? [];
$notify = $config['notification'] ?? [];

// Set defaults
$backup_config['type'] = $backup_config['type'] ?? 'full';
// Note: retention_days is now per-destination in [destination:*] sections
$storage_config['path'] = trim($storage_config['path'] ?? '', '/');
$storage_config['multipart_threshold_mb'] = (int)($storage_config['multipart_threshold_mb'] ?? 20);
$storage_config['multipart_chunk_mb'] = (int)($storage_config['multipart_chunk_mb'] ?? 50);
$notify['debug'] = filter_var($notify['debug'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Parse destination sections
$destinations = [];
foreach ($config as $section => $values) {
    if (preg_match('/^destination:(.+)$/', $section, $matches)) {
        $name = $matches[1];
        $enabled = filter_var($values['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($enabled) {
            $values['name'] = $name;
            $values['use_path_style'] = filter_var($values['use_path_style'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $values['verify_ssl'] = filter_var($values['verify_ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $values['notify'] = strtolower(trim($values['notify'] ?? 'always'));
            // Validate notify setting
            if (!in_array($values['notify'], ['always', 'on_error', 'never'])) {
                $values['notify'] = 'always'; // Default to always if invalid
            }
            $destinations[$name] = $values;
        }
    }
}

if (empty($destinations)) {
    die("No enabled destinations found. Enable at least one [destination:*] section.\n");
}

// Initialize stats
$GLOBALS['stats'] = [
    'start_time' => date('Y-m-d H:i:s'),
    'temp_dir' => null, // Only created if HTTP download fallback is needed
    'destinations' => [],
];

// Logging
// $indent: 0 = always show, 1 = important info, 2 = debug only (verbose)
$log = [];
$log_file = __DIR__ . '/cpanel_backup.log';
$log_max_size = ((int)($notify['log_max_mb'] ?? 5)) * 1024 * 1024;
$log_keep_files = (int)($notify['log_keep_files'] ?? 3);

// Rotate log file if too large
if (file_exists($log_file) && filesize($log_file) > $log_max_size) {
    for ($i = $log_keep_files; $i >= 1; $i--) {
        $old = "{$log_file}.{$i}";
        $new = "{$log_file}." . ($i + 1);
        if ($i == $log_keep_files && file_exists($old)) {
            @unlink($old);
        } elseif (file_exists($old)) {
            @rename($old, $new);
        }
    }
    @rename($log_file, "{$log_file}.1");
}

function log_msg($message, $indent = 0) {
    global $log, $notify, $log_file;
    $is_debug = !empty($notify['debug']);

    // indent level 2 = debug only, skip if not in debug mode
    if ($indent >= 2 && !$is_debug) {
        return;
    }

    $prefix = str_repeat('  ', $indent);
    $entry = date('[Y-m-d H:i:s] ') . $prefix . $message;
    $log[] = $entry;

    // Write to log file immediately
    @file_put_contents($log_file, $entry . "\n", FILE_APPEND | LOCK_EX);
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

log_msg("=== cPanel Multi-Destination Backup ===");
log_msg("Enabled destinations: " . implode(', ', array_keys($destinations)));
log_msg("Storage path: " . ($storage_config['path'] ?: '(bucket root)'));

$backup_file = null;
$backup_filename = null;  // Original filename for cleanup via API

try {
    // 1. Generate cPanel backup (once)
    log_msg("Creating cPanel backup...");
    $backup_file = generate_cpanel_backup($cp, $backup_config);
    $backup_filename = basename($backup_file);
    $GLOBALS['stats']['backup_file'] = $backup_filename;
    $GLOBALS['stats']['backup_size'] = filesize($backup_file);
    $size_mb = round($GLOBALS['stats']['backup_size'] / 1024 / 1024, 2);
    log_msg("Backup created: {$backup_filename} ({$size_mb} MB)");

    // Check if this is a direct path (in home directory) vs temp copy
    $is_direct_path = strpos($backup_file, "/home/{$cp['username']}/") === 0;

    // 2. Upload to each destination
    log_msg("Uploading to " . count($destinations) . " destination(s)...");

    foreach ($destinations as $name => $dest) {
        log_msg("→ {$name} ({$dest['provider']})", 1);
        $GLOBALS['stats']['destinations'][$name] = ['status' => 'pending'];

        try {
            $object_key = upload_to_destination($backup_file, $dest, $storage_config);
            $GLOBALS['stats']['destinations'][$name] = [
                'status' => 'success',
                'key' => $object_key,
                'bucket' => $dest['bucket'],
            ];
            log_msg("✓ Uploaded: {$dest['bucket']}/{$object_key}", 2);

            // Cleanup old backups on this destination (per-destination retention)
            $dest_retention = (int)($dest['retention_days'] ?? 0);
            if ($dest_retention > 0) {
                $deleted = cleanup_old_backups($dest, $storage_config['path'], $dest_retention);
                $GLOBALS['stats']['destinations'][$name]['deleted'] = $deleted;
                if ($deleted > 0) {
                    log_msg("✓ Cleaned up {$deleted} old backup(s) (retention: {$dest_retention} days)", 2);
                }
            }

        } catch (Exception $e) {
            $GLOBALS['stats']['destinations'][$name] = [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ];
            log_msg("✗ Failed: " . $e->getMessage(), 2);
        }
    }

    $GLOBALS['stats']['success'] = true;

} catch (Exception $e) {
    log_msg("ERROR: " . $e->getMessage());
    $GLOBALS['stats']['success'] = false;
    $GLOBALS['stats']['error'] = $e->getMessage();
}

// Cleanup: Delete old backups from home directory based on cpanel_retention_days
if ($GLOBALS['stats']['success'] && ($backup_config['cpanel_retention_days'] ?? -1) >= 0) {
    $cpanel_retention = (int)$backup_config['cpanel_retention_days'];
    if ($cpanel_retention === 0) {
        // 0 = delete immediately after upload
        log_msg("Cleaning up backup from home directory (cpanel_retention_days=0)...", 1);
        delete_backup_from_homedir($cp, $backup_filename);
    } else {
        // > 0 = delete backups older than X days
        log_msg("Cleaning up backups older than {$cpanel_retention} days from home directory...", 1);
        $deleted = cleanup_old_homedir_backups($cp, $cpanel_retention);
        if ($deleted > 0) {
            log_msg("Deleted {$deleted} old backup(s) from home directory", 1);
        }
    }
    // -1 or not set = keep forever (do nothing)
}

// Cleanup temp directory (only if it was created for HTTP download fallback)
if ($GLOBALS['stats']['temp_dir'] && is_dir($GLOBALS['stats']['temp_dir'])) {
    @array_map('unlink', glob($GLOBALS['stats']['temp_dir'] . '/*'));
    @rmdir($GLOBALS['stats']['temp_dir']);
}

$GLOBALS['stats']['end_time'] = date('Y-m-d H:i:s');

// Summary
$success_count = 0;
$fail_count = 0;
foreach ($GLOBALS['stats']['destinations'] as $d) {
    if ($d['status'] === 'success') $success_count++;
    else $fail_count++;
}

log_msg("=== Complete: {$success_count} succeeded, {$fail_count} failed ===");

// Send notification (only if global email set AND at least one destination wants notification)
if (!empty($notify['email'])) {
    $should_notify = false;
    $notify_reasons = [];
    
    foreach ($GLOBALS['stats']['destinations'] as $name => $d) {
        $dest_config = $destinations[$name] ?? [];
        $notify_pref = $dest_config['notify'] ?? 'always';
        
        if ($notify_pref === 'always') {
            $should_notify = true;
            $notify_reasons[] = "{$name} (always)";
        } elseif ($notify_pref === 'on_error' && $d['status'] !== 'success') {
            $should_notify = true;
            $notify_reasons[] = "{$name} (failed)";
        }
        // 'never' = skip this destination for notification
    }
    
    if ($should_notify) {
        log_msg("Sending notification: " . implode(', ', $notify_reasons), 1);
        send_notification($notify, $log, $storage_config);
    } else {
        log_msg("Skipping notification: no destinations require notification based on their settings", 1);
    }
}

// Output log
echo implode("\n", $log) . "\n";

// Exit with error code if any failures
exit($fail_count > 0 ? 1 : 0);

// ============================================================================
// CPANEL BACKUP FUNCTIONS
// ============================================================================

function generate_cpanel_backup($cp, $backup_config) {
    $backup_pid = start_cpanel_backup($cp, $backup_config);
    
    $backup_filename = wait_for_backup($cp, $backup_pid);
    
    return download_backup($cp, $backup_filename);
}

function start_cpanel_backup($cp, $backup_config) {
    $secure = filter_var($cp['secure'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $protocol = $secure ? 'https' : 'http';
    $port = $secure ? 2083 : 2082;
    
    // Use fullbackup_to_homedir - this is the standard cPanel UAPI endpoint
    $url = "{$protocol}://{$cp['domain']}:{$port}/execute/Backup/fullbackup_to_homedir";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 120,
    ]);
    
    if (!empty($cp['api_token'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: cpanel {$cp['username']}:{$cp['api_token']}"
        ]);
    } else {
        curl_setopt($ch, CURLOPT_USERPWD, "{$cp['username']}:{$cp['password']}");
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("cURL error: {$curl_error}");
    }
    
    $result = json_decode($response, true);
    
    // Check for errors
    if (!empty($result['errors'])) {
        throw new Exception("Failed to start backup: " . implode(', ', $result['errors']));
    }
    
    if ($http_code !== 200) {
        throw new Exception("Failed to start backup: HTTP {$http_code}");
    }
    
    // The API returns the PID of the backup process
    $pid = $result['data']['pid'] ?? null;
    
    if ($pid) {
        log_msg("Backup process started (PID: {$pid})", 1);
        return $pid;
    }
    
    // Some versions return success without PID, backup starts immediately
    return 'started';
}

function wait_for_backup($cp, $backup_pid) {
    $max_wait = 7200;
    $start = time();
    $check_interval = 30;
    $script_start_time = time();
    $stable_checks_required = 3;  // File size must be stable for 3 consecutive checks

    // Track file sizes to detect when backup is complete
    $file_sizes = [];      // filename => last known size
    $stable_counts = [];   // filename => number of consecutive stable checks

    log_msg("Script start time: " . date('Y-m-d H:i:s', $script_start_time), 1);
    log_msg("Waiting for backup to complete...", 1);

    // Wait a bit for backup to start generating
    sleep(15);

    while (true) {
        $current_files = list_home_directory($cp);

        // Find all backup tar.gz files with their mtime and size
        $backups = [];
        foreach ($current_files as $file) {
            $filename = $file['file'] ?? $file['name'] ?? '';
            $mtime = $file['mtime'] ?? 0;
            $size = $file['size'] ?? 0;

            // Look for backup files
            if (preg_match('/\.tar\.gz$/i', $filename)) {
                $backups[] = [
                    'file' => $filename,
                    'mtime' => $mtime,
                    'size' => (int)$size,
                    'age' => time() - $mtime,
                ];
            }
        }

        // Debug: show backups found
        if (!empty($backups)) {
            foreach ($backups as $b) {
                $size_mb = round($b['size'] / 1024 / 1024, 2);
                log_msg("Found: {$b['file']} (size: {$size_mb} MB, modified {$b['age']}s ago)", 2);
            }
        }

        // Look for a backup created after our script started
        foreach ($backups as $b) {
            // If the file was modified recently, it's probably our backup
            if ($b['mtime'] >= $script_start_time) {
                $filename = $b['file'];
                $current_size = $b['size'];

                // Initialize tracking for this file if not seen before
                if (!isset($file_sizes[$filename])) {
                    $file_sizes[$filename] = 0;
                    $stable_counts[$filename] = 0;
                }

                // Check if size has stabilized
                if ($current_size > 0 && $current_size === $file_sizes[$filename]) {
                    $stable_counts[$filename]++;
                    $size_mb = round($current_size / 1024 / 1024, 2);
                    log_msg("File size stable: {$filename} ({$size_mb} MB) - check {$stable_counts[$filename]}/{$stable_checks_required}", 2);

                    if ($stable_counts[$filename] >= $stable_checks_required) {
                        log_msg("Backup file ready: {$filename} ({$size_mb} MB)", 1);
                        return $filename;
                    }
                } else {
                    // Size changed, reset stable counter
                    $stable_counts[$filename] = 0;
                    $file_sizes[$filename] = $current_size;
                    $size_mb = round($current_size / 1024 / 1024, 2);
                    log_msg("File still growing: {$filename} ({$size_mb} MB)", 2);
                }
            }
        }

        // Check elapsed time
        $elapsed = time() - $start;
        $elapsed_min = round($elapsed / 60, 1);
        log_msg("Still waiting... ({$elapsed_min} min)", 1);

        if ($elapsed > $max_wait) {
            throw new Exception("Backup timed out after {$max_wait} seconds");
        }

        sleep($check_interval);
    }
}

function list_home_directory($cp) {
    $secure = filter_var($cp['secure'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $protocol = $secure ? 'https' : 'http';
    $port = $secure ? 2083 : 2082;
    
    // Use UAPI Fileman::list_files - empty dir = home directory root
    $url = "{$protocol}://{$cp['domain']}:{$port}/execute/Fileman/list_files?dir=";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    
    if (!empty($cp['api_token'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: cpanel {$cp['username']}:{$cp['api_token']}"
        ]);
    } else {
        curl_setopt($ch, CURLOPT_USERPWD, "{$cp['username']}:{$cp['password']}");
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    // Debug first call
    static $shown_debug = false;
    if (!$shown_debug) {
        log_msg("DEBUG Fileman::list_files HTTP {$http_code}", 1);
        log_msg("DEBUG response: " . substr($response, 0, 500), 1);
        $shown_debug = true;
    }
    
    return $result['data'] ?? [];
}

function download_backup($cp, $backup_filename) {
    log_msg("Accessing backup: {$backup_filename}", 1);

    // Method 1: Direct filesystem access (if script runs on cPanel server)
    // The backup file is in the user's home directory
    $home_dir = "/home/{$cp['username']}";
    $direct_path = "{$home_dir}/{$backup_filename}";

    if (file_exists($direct_path) && is_readable($direct_path)) {
        log_msg("Using direct filesystem access: {$direct_path}", 2);
        $size = filesize($direct_path);

        if ($size > 1048576) {
            log_msg("Backup file accessible: " . round($size / 1024 / 1024, 2) . " MB", 1);
            return $direct_path;
        }
    }

    // Method 2: Try HTTP download methods if direct access failed (script on different server)
    log_msg("Direct access failed, attempting HTTP download fallback...", 1);
    
    // Create temp directory for HTTP download
    if (!$GLOBALS['stats']['temp_dir']) {
        $GLOBALS['stats']['temp_dir'] = sys_get_temp_dir() . '/cpanel_backup_' . uniqid();
        if (!mkdir($GLOBALS['stats']['temp_dir'], 0700, true)) {
            throw new Exception("Failed to create temp directory: {$GLOBALS['stats']['temp_dir']}");
        }
        log_msg("Created temp directory: {$GLOBALS['stats']['temp_dir']}", 2);
    }
    
    $output_file = $GLOBALS['stats']['temp_dir'] . '/' . basename($backup_filename);
    $secure = filter_var($cp['secure'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $protocol = $secure ? 'https' : 'http';
    $port = $secure ? 2083 : 2082;

    $download_methods = [
        // Method 2a: Session-based download with password
        function() use ($cp, $backup_filename, $output_file, $protocol, $port) {
            if (empty($cp['password'])) {
                log_msg("Method 2a skipped: No password configured", 2);
                return false;
            }

            // Get a cpsess token via password authentication
            $session_url = "{$protocol}://{$cp['domain']}:{$port}/login/?login_only=1";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $session_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'user' => $cp['username'],
                    'pass' => $cp['password'],
                ]),
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HEADER => true,
            ]);

            $response = curl_exec($ch);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($response, $header_size);
            curl_close($ch);

            $result = json_decode($body, true);
            $security_token = $result['security_token'] ?? '';

            if (empty($security_token)) {
                log_msg("Method 2a: Failed to get session token. Response: " . substr($body, 0, 200), 2);
                return false;
            }

            // Use the session token to download
            $backup_url = "{$protocol}://{$cp['domain']}:{$port}{$security_token}/download?file=" . rawurlencode($backup_filename);
            log_msg("Download URL (session): {$backup_url}", 2);

            return download_file_curl($backup_url, $output_file, [], false);
        },

        // Method 2b: Direct download with API token
        function() use ($cp, $backup_filename, $output_file, $protocol, $port) {
            $backup_url = "{$protocol}://{$cp['domain']}:{$port}/download?file=" . rawurlencode($backup_filename);
            log_msg("Download URL (direct): {$backup_url}", 2);
            return download_file_curl($backup_url, $output_file, $cp, true);
        },

        // Method 2c: Try with home directory prefix in path
        function() use ($cp, $backup_filename, $output_file, $protocol, $port) {
            $home_path = "/home/{$cp['username']}/{$backup_filename}";
            $backup_url = "{$protocol}://{$cp['domain']}:{$port}/download?file=" . rawurlencode($home_path);
            log_msg("Download URL (full path): {$backup_url}", 2);
            return download_file_curl($backup_url, $output_file, $cp, true);
        },
    ];

    $last_error = "Direct filesystem access failed and no HTTP methods succeeded";

    foreach ($download_methods as $index => $method) {
        $method_num = $index + 1;
        log_msg("Trying HTTP download method {$method_num}...", 2);

        try {
            $result = $method();
            if ($result !== false && file_exists($output_file)) {
                $size = filesize($output_file);
                if ($size > 1048576) {
                    log_msg("Downloaded: " . round($size / 1024 / 1024, 2) . " MB (HTTP method {$method_num})", 1);
                    return $output_file;
                } else {
                    $content = file_get_contents($output_file);
                    $last_error = "HTTP method {$method_num}: File too small ({$size} bytes). Content: " . substr($content, 0, 300);
                    log_msg($last_error, 2);
                    @unlink($output_file);
                }
            }
        } catch (Exception $e) {
            $last_error = "HTTP method {$method_num}: " . $e->getMessage();
            log_msg($last_error, 2);
        }
    }

    throw new Exception("All download methods failed. Direct path tried: {$direct_path}. Last HTTP error: {$last_error}");
}

function download_file_curl($url, $output_file, $cp, $use_auth_header) {
    $ch = curl_init();
    $fp = fopen($output_file, 'wb');

    if ($fp === false) {
        throw new Exception("Cannot create output file");
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT => 7200,
        CURLOPT_CONNECTTIMEOUT => 30,
    ]);

    if ($use_auth_header && !empty($cp)) {
        if (!empty($cp['api_token'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: cpanel {$cp['username']}:{$cp['api_token']}"
            ]);
        } else if (!empty($cp['password'])) {
            curl_setopt($ch, CURLOPT_USERPWD, "{$cp['username']}:{$cp['password']}");
        }
    }

    $success = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($curl_error) {
        @unlink($output_file);
        throw new Exception("cURL error: {$curl_error}");
    }

    if ($http_code >= 400) {
        $content = file_get_contents($output_file);
        @unlink($output_file);
        throw new Exception("HTTP {$http_code}: " . substr($content, 0, 200));
    }

    return $success;
}

function delete_backup_from_homedir($cp, $backup_filename) {
    // Direct filesystem deletion (script runs on cPanel server with account access)
    $home_dir = "/home/{$cp['username']}";
    $file_path = "{$home_dir}/{$backup_filename}";
    
    if (!file_exists($file_path)) {
        log_msg("File not found for deletion: {$backup_filename}", 2);
        return false;
    }
    
    if (!is_writable($file_path)) {
        log_msg("No write permission for {$backup_filename} (check file ownership/permissions)", 1);
        return false;
    }
    
    if (@unlink($file_path)) {
        log_msg("Deleted {$backup_filename} from home directory", 2);
        return true;
    } else {
        $error = error_get_last();
        log_msg("Failed to delete {$backup_filename}: " . ($error['message'] ?? 'unknown error'), 1);
        return false;
    }
}

function cleanup_old_homedir_backups($cp, $retention_days) {
    // Get list of files in home directory
    $files = list_home_directory($cp);
    $cutoff_time = time() - ($retention_days * 86400);
    $deleted = 0;

    foreach ($files as $file) {
        $filename = $file['file'] ?? $file['name'] ?? '';
        $mtime = $file['mtime'] ?? 0;

        // Only delete backup tar.gz files older than retention period
        if (preg_match('/^backup-.*\.tar\.gz$/i', $filename) && $mtime <= $cutoff_time) {
            $age_days = round((time() - $mtime) / 86400);
            log_msg("Deleting old backup: {$filename} (age: {$age_days} days)", 1);
            if (delete_backup_from_homedir($cp, $filename)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

// ============================================================================
// S3 UPLOAD FUNCTIONS (AWS Signature V4)
// ============================================================================

function upload_to_destination($file_path, $dest, $storage_config) {
    $filename = basename($file_path);
    $storage_path = $storage_config['path'] ?? '';
    $object_key = $storage_path ? "{$storage_path}/{$filename}" : $filename;

    $file_size = filesize($file_path);
    $threshold_mb = $storage_config['multipart_threshold_mb'] ?? 20;
    $chunk_mb = $storage_config['multipart_chunk_mb'] ?? 50;
    $multipart_threshold = $threshold_mb * 1024 * 1024;

    if ($file_size > $multipart_threshold) {
        log_msg("Using multipart upload for large file (" . round($file_size / 1024 / 1024, 2) . " MB, threshold: {$threshold_mb} MB)", 2);
        return s3_multipart_upload($dest, $object_key, $file_path, $chunk_mb);
    } else {
        return s3_put_object($dest, $object_key, $file_path);
    }
}

function get_endpoint_info($dest) {
    $provider = strtolower($dest['provider'] ?? 'minio');
    $region = $dest['region'] ?? 'us-east-1';
    
    switch ($provider) {
        case 'backblaze':
            $endpoint = $dest['endpoint'] ?? "https://s3.{$region}.backblazeb2.com";
            break;
        case 'wasabi':
            $endpoint = "https://s3.{$region}.wasabisys.com";
            break;
        case 'aws':
            $endpoint = "https://s3.{$region}.amazonaws.com";
            break;
        case 'minio':
        default:
            $endpoint = rtrim($dest['endpoint'] ?? '', '/');
            if (empty($endpoint)) {
                throw new Exception("MinIO endpoint is required");
            }
            break;
    }
    
    $parsed = parse_url($endpoint);
    $scheme = $parsed['scheme'] ?? 'https';
    $host = $parsed['host'];
    $port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
    $port_suffix = ($port != 443 && $port != 80) ? ":{$port}" : "";
    
    return [
        'endpoint' => $endpoint,
        'scheme' => $scheme,
        'host' => $host,
        'port' => $port,
        'port_suffix' => $port_suffix,
        'region' => $region,
    ];
}

function s3_sign_request($dest, $method, $canonical_uri, $query_params, $headers, $payload_hash) {
    $region = $dest['region'] ?? 'us-east-1';
    $access_key = $dest['access_key'];
    $secret_key = $dest['secret_key'];
    
    $service = 's3';
    $algorithm = 'AWS4-HMAC-SHA256';
    $amz_date = gmdate('Ymd\THis\Z');
    $date_stamp = gmdate('Ymd');
    
    // Add required headers
    $headers['x-amz-content-sha256'] = $payload_hash;
    $headers['x-amz-date'] = $amz_date;
    
    ksort($headers);
    
    // Build canonical headers
    $canonical_headers = '';
    $signed_headers_list = [];
    foreach ($headers as $key => $value) {
        $canonical_headers .= strtolower($key) . ':' . trim($value) . "\n";
        $signed_headers_list[] = strtolower($key);
    }
    $signed_headers = implode(';', $signed_headers_list);
    
    // Build canonical query string
    ksort($query_params);
    $canonical_querystring = http_build_query($query_params);
    $canonical_querystring = str_replace(['+', '%7E'], ['%20', '~'], $canonical_querystring);
    
    // Build canonical request
    $canonical_request = "{$method}\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$payload_hash}";
    
    // Build string to sign
    $credential_scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
    $string_to_sign = "{$algorithm}\n{$amz_date}\n{$credential_scope}\n" . hash('sha256', $canonical_request);
    
    // Calculate signature
    $k_date = hash_hmac('sha256', $date_stamp, "AWS4{$secret_key}", true);
    $k_region = hash_hmac('sha256', $region, $k_date, true);
    $k_service = hash_hmac('sha256', $service, $k_region, true);
    $k_signing = hash_hmac('sha256', 'aws4_request', $k_service, true);
    $signature = hash_hmac('sha256', $string_to_sign, $k_signing);
    
    $authorization = "{$algorithm} Credential={$access_key}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";
    
    return [
        'authorization' => $authorization,
        'amz_date' => $amz_date,
        'payload_hash' => $payload_hash,
        'headers' => $headers,
    ];
}

function s3_put_object($dest, $object_key, $file_path) {
    $info = get_endpoint_info($dest);
    $bucket = $dest['bucket'];
    $use_path_style = $dest['use_path_style'] ?? true;
    
    // Build URL and canonical URI
    if ($use_path_style) {
        $url = "{$info['scheme']}://{$info['host']}{$info['port_suffix']}/{$bucket}/{$object_key}";
        $canonical_uri = "/{$bucket}/{$object_key}";
        $host = $info['host'] . $info['port_suffix'];
    } else {
        $host = "{$bucket}.{$info['host']}";
        $url = "{$info['scheme']}://{$host}/{$object_key}";
        $canonical_uri = "/{$object_key}";
    }
    
    // Read file
    $file_content = file_get_contents($file_path);
    if ($file_content === false) {
        throw new Exception("Failed to read file");
    }
    
    $payload_hash = hash('sha256', $file_content);
    $content_length = strlen($file_content);
    
    $headers = [
        'host' => $host,
        'content-length' => $content_length,
        'content-type' => 'application/octet-stream',
    ];
    
    $sign = s3_sign_request($dest, 'PUT', $canonical_uri, [], $headers, $payload_hash);
    
    $verify_ssl = $dest['verify_ssl'] ?? true;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $file_content,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_TIMEOUT => 7200,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Content-Type: application/octet-stream",
            "Content-Length: {$content_length}",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        throw new Exception("Upload failed: {$curl_error}");
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        // Extract error message from XML response
        if (preg_match('/<Message>([^<]+)<\/Message>/', $response, $m)) {
            throw new Exception("Upload failed: {$m[1]}");
        }
        throw new Exception("Upload failed: HTTP {$http_code}");
    }
    
    return $object_key;
}

function s3_multipart_upload($dest, $object_key, $file_path, $chunk_mb = 50) {
    $info = get_endpoint_info($dest);
    $bucket = $dest['bucket'];
    $use_path_style = $dest['use_path_style'] ?? true;
    $verify_ssl = $dest['verify_ssl'] ?? true;
    $part_size = $chunk_mb * 1024 * 1024; // Configurable chunk size in MB

    // Build base URL and canonical URI
    if ($use_path_style) {
        $base_url = "{$info['scheme']}://{$info['host']}{$info['port_suffix']}/{$bucket}/{$object_key}";
        $canonical_uri = "/{$bucket}/{$object_key}";
        $host = $info['host'] . $info['port_suffix'];
    } else {
        $host = "{$bucket}.{$info['host']}";
        $base_url = "{$info['scheme']}://{$host}/{$object_key}";
        $canonical_uri = "/{$object_key}";
    }

    // Step 1: Initiate multipart upload
    $headers = ['host' => $host];
    $query_params = ['uploads' => ''];
    $sign = s3_sign_request($dest, 'POST', $canonical_uri, $query_params, $headers, hash('sha256', ''));

    $init_url = $base_url . '?uploads';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $init_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        throw new Exception("Failed to initiate multipart upload: HTTP {$http_code} - " . substr($response, 0, 200));
    }

    // Extract UploadId from response
    if (!preg_match('/<UploadId>([^<]+)<\/UploadId>/', $response, $matches)) {
        throw new Exception("Failed to get UploadId from response");
    }
    $upload_id = $matches[1];
    log_msg("Multipart upload initiated (UploadId: " . substr($upload_id, 0, 20) . "...)", 2);

    // Step 2: Upload parts
    $file_size = filesize($file_path);
    $fp = fopen($file_path, 'rb');
    if (!$fp) {
        throw new Exception("Cannot open file for reading");
    }

    $parts = [];
    $part_number = 1;
    $uploaded = 0;

    while (!feof($fp)) {
        $part_data = fread($fp, $part_size);
        if ($part_data === false || strlen($part_data) === 0) {
            break;
        }

        $part_hash = hash('sha256', $part_data);
        $content_length = strlen($part_data);

        $headers = [
            'host' => $host,
            'content-length' => $content_length,
        ];
        $query_params = [
            'partNumber' => $part_number,
            'uploadId' => $upload_id,
        ];

        $sign = s3_sign_request($dest, 'PUT', $canonical_uri, $query_params, $headers, $part_hash);

        $part_url = $base_url . "?partNumber={$part_number}&uploadId=" . rawurlencode($upload_id);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $part_url,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $part_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $verify_ssl,
            CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
            CURLOPT_TIMEOUT => 3600,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: {$sign['authorization']}",
                "Content-Length: {$content_length}",
                "Host: {$host}",
                "x-amz-content-sha256: {$sign['payload_hash']}",
                "x-amz-date: {$sign['amz_date']}",
            ],
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers_str = substr($response, 0, $header_size);
        curl_close($ch);

        if ($http_code != 200) {
            fclose($fp);
            // Abort the upload
            s3_abort_multipart_upload($dest, $object_key, $upload_id);
            throw new Exception("Failed to upload part {$part_number}: HTTP {$http_code}");
        }

        // Extract ETag from response headers
        if (preg_match('/ETag:\s*"?([^"\r\n]+)"?/i', $headers_str, $etag_match)) {
            $etag = trim($etag_match[1], '"');
            $parts[] = ['PartNumber' => $part_number, 'ETag' => $etag];
        } else {
            fclose($fp);
            s3_abort_multipart_upload($dest, $object_key, $upload_id);
            throw new Exception("No ETag in response for part {$part_number}");
        }

        $uploaded += $content_length;
        $percent = round(($uploaded / $file_size) * 100);
        log_msg("Uploaded part {$part_number} ({$percent}%)", 2);

        $part_number++;
    }

    fclose($fp);

    // Step 3: Complete multipart upload
    $complete_xml = "<CompleteMultipartUpload>";
    foreach ($parts as $part) {
        $complete_xml .= "<Part><PartNumber>{$part['PartNumber']}</PartNumber><ETag>\"{$part['ETag']}\"</ETag></Part>";
    }
    $complete_xml .= "</CompleteMultipartUpload>";

    $payload_hash = hash('sha256', $complete_xml);
    $content_length = strlen($complete_xml);

    $headers = [
        'host' => $host,
        'content-length' => $content_length,
        'content-type' => 'application/xml',
    ];
    $query_params = ['uploadId' => $upload_id];

    $sign = s3_sign_request($dest, 'POST', $canonical_uri, $query_params, $headers, $payload_hash);

    $complete_url = $base_url . "?uploadId=" . rawurlencode($upload_id);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $complete_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $complete_xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Content-Length: {$content_length}",
            "Content-Type: application/xml",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200) {
        throw new Exception("Failed to complete multipart upload: HTTP {$http_code} - " . substr($response, 0, 200));
    }

    log_msg("Multipart upload completed successfully", 2);
    return $object_key;
}

function s3_abort_multipart_upload($dest, $object_key, $upload_id) {
    $info = get_endpoint_info($dest);
    $bucket = $dest['bucket'];
    $use_path_style = $dest['use_path_style'] ?? true;
    $verify_ssl = $dest['verify_ssl'] ?? true;

    if ($use_path_style) {
        $base_url = "{$info['scheme']}://{$info['host']}{$info['port_suffix']}/{$bucket}/{$object_key}";
        $canonical_uri = "/{$bucket}/{$object_key}";
        $host = $info['host'] . $info['port_suffix'];
    } else {
        $host = "{$bucket}.{$info['host']}";
        $base_url = "{$info['scheme']}://{$host}/{$object_key}";
        $canonical_uri = "/{$object_key}";
    }

    $headers = ['host' => $host];
    $query_params = ['uploadId' => $upload_id];
    $sign = s3_sign_request($dest, 'DELETE', $canonical_uri, $query_params, $headers, hash('sha256', ''));

    $abort_url = $base_url . "?uploadId=" . rawurlencode($upload_id);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $abort_url,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);

    curl_exec($ch);
    curl_close($ch);

    log_msg("Aborted multipart upload", 2);
}

// ============================================================================
// CLEANUP FUNCTIONS
// ============================================================================

function cleanup_old_backups($dest, $storage_path, $retention_days) {
    $prefix = $storage_path ? "{$storage_path}/" : '';
    
    try {
        $objects = s3_list_objects($dest, $prefix);
    } catch (Exception $e) {
        log_msg("Warning: Could not list objects for cleanup: " . $e->getMessage(), 2);
        return 0;
    }
    
    $cutoff_time = time() - ($retention_days * 86400);
    $deleted = 0;
    
    foreach ($objects as $obj) {
        // Only delete backup files (tar.gz)
        if (!preg_match('/\.tar\.gz$/i', $obj['Key'])) {
            continue;
        }
        
        $last_modified = strtotime($obj['LastModified']);
        if ($last_modified <= $cutoff_time) {
            try {
                if (s3_delete_object($dest, $obj['Key'])) {
                    $deleted++;
                }
            } catch (Exception $e) {
                // Continue on delete errors
            }
        }
    }
    
    return $deleted;
}

function s3_list_objects($dest, $prefix = '') {
    $info = get_endpoint_info($dest);
    $bucket = $dest['bucket'];
    $use_path_style = $dest['use_path_style'] ?? true;
    $verify_ssl = $dest['verify_ssl'] ?? true;
    
    $query_params = ['list-type' => '2'];
    if ($prefix) {
        $query_params['prefix'] = $prefix;
    }
    
    if ($use_path_style) {
        $url = "{$info['scheme']}://{$info['host']}{$info['port_suffix']}/{$bucket}?" . http_build_query($query_params);
        $canonical_uri = "/{$bucket}";
        $host = $info['host'] . $info['port_suffix'];
    } else {
        $host = "{$bucket}.{$info['host']}";
        $url = "{$info['scheme']}://{$host}?" . http_build_query($query_params);
        $canonical_uri = "/";
    }
    
    $payload_hash = hash('sha256', '');
    $headers = ['host' => $host];
    
    $sign = s3_sign_request($dest, 'GET', $canonical_uri, $query_params, $headers, $payload_hash);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200) {
        throw new Exception("List objects failed: HTTP {$http_code}");
    }
    
    $objects = [];
    if (preg_match_all('/<Key>([^<]+)<\/Key>.*?<LastModified>([^<]+)<\/LastModified>/s', $response, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $objects[] = [
                'Key' => html_entity_decode($match[1]),
                'LastModified' => $match[2],
            ];
        }
    }
    
    return $objects;
}

function s3_delete_object($dest, $object_key) {
    $info = get_endpoint_info($dest);
    $bucket = $dest['bucket'];
    $use_path_style = $dest['use_path_style'] ?? true;
    $verify_ssl = $dest['verify_ssl'] ?? true;
    
    if ($use_path_style) {
        $url = "{$info['scheme']}://{$info['host']}{$info['port_suffix']}/{$bucket}/{$object_key}";
        $canonical_uri = "/{$bucket}/{$object_key}";
        $host = $info['host'] . $info['port_suffix'];
    } else {
        $host = "{$bucket}.{$info['host']}";
        $url = "{$info['scheme']}://{$host}/{$object_key}";
        $canonical_uri = "/{$object_key}";
    }
    
    $payload_hash = hash('sha256', '');
    $headers = ['host' => $host];
    
    $sign = s3_sign_request($dest, 'DELETE', $canonical_uri, [], $headers, $payload_hash);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $verify_ssl,
        CURLOPT_SSL_VERIFYHOST => $verify_ssl ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            "Authorization: {$sign['authorization']}",
            "Host: {$host}",
            "x-amz-content-sha256: {$sign['payload_hash']}",
            "x-amz-date: {$sign['amz_date']}",
        ],
    ]);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return $http_code === 204 || $http_code === 200;
}

// ============================================================================
// NOTIFICATION
// ============================================================================

function send_notification($notify, $log, $storage_config) {
    $stats = $GLOBALS['stats'];
    $success = $stats['success'] ?? false;
    
    // Count successes/failures
    $success_count = 0;
    $fail_count = 0;
    foreach ($stats['destinations'] as $d) {
        if ($d['status'] === 'success') $success_count++;
        else $fail_count++;
    }
    
    $overall_status = ($success && $fail_count === 0) ? 'SUCCESS' : (($success_count > 0) ? 'PARTIAL' : 'FAILED');
    $status_icon = match($overall_status) {
        'SUCCESS' => '✓',
        'PARTIAL' => '⚠',
        default => '✗',
    };
    
    $subject = "{$status_icon} cPanel Backup - " . date('Y-m-d');
    if (!empty($notify['server_id'])) {
        $subject .= " [{$notify['server_id']}]";
    }
    
    if (!empty($notify['html_email']) && filter_var($notify['html_email'], FILTER_VALIDATE_BOOLEAN)) {
        $colors = [
            'SUCCESS' => '#28a745',
            'PARTIAL' => '#ffc107', 
            'FAILED' => '#dc3545',
        ];
        $color = $colors[$overall_status];
        
        $html = "<!DOCTYPE html><html><head><style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; color: #333; max-width: 800px; margin: 0 auto; }
            .header { background: {$color}; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
            .content { padding: 20px; background: #fff; border: 1px solid #ddd; border-top: none; }
            .stats { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .dest { margin: 10px 0; padding: 10px; border-left: 4px solid #ddd; background: #fafafa; }
            .dest.success { border-color: #28a745; }
            .dest.failed { border-color: #dc3545; }
            .log { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: 'SF Mono', Monaco, monospace; font-size: 11px; white-space: pre-wrap; overflow-x: auto; }
            .badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; }
            .badge.success { background: #d4edda; color: #155724; }
            .badge.failed { background: #f8d7da; color: #721c24; }
        </style></head><body>
        <div class='header'><h2 style='margin:0;'>cPanel Backup {$overall_status}</h2></div>
        <div class='content'>
            <div class='stats'>
                <strong>Storage Path:</strong> " . ($storage_config['path'] ?: '(bucket root)') . "<br>
                <strong>Start:</strong> {$stats['start_time']}<br>
                <strong>End:</strong> {$stats['end_time']}<br>";
        
        if (isset($stats['backup_size'])) {
            $size_mb = round($stats['backup_size'] / 1024 / 1024, 2);
            $html .= "<strong>Backup Size:</strong> {$size_mb} MB<br>";
        }
        
        if (isset($stats['backup_file'])) {
            $html .= "<strong>Backup File:</strong> {$stats['backup_file']}<br>";
        }
        
        $html .= "</div><h3>Destinations</h3>";
        
        foreach ($stats['destinations'] as $name => $d) {
            $class = $d['status'] === 'success' ? 'success' : 'failed';
            $badge = $d['status'] === 'success' 
                ? "<span class='badge success'>SUCCESS</span>"
                : "<span class='badge failed'>FAILED</span>";
            
            $html .= "<div class='dest {$class}'><strong>{$name}</strong> {$badge}<br>";
            
            if ($d['status'] === 'success') {
                $html .= "<small>Location: {$d['bucket']}/{$d['key']}</small>";
                if (isset($d['deleted']) && $d['deleted'] > 0) {
                    $html .= "<br><small>Old backups deleted: {$d['deleted']}</small>";
                }
            } else {
                $html .= "<small style='color:#dc3545;'>Error: " . htmlspecialchars($d['error'] ?? 'Unknown') . "</small>";
            }
            
            $html .= "</div>";
        }
        
        $html .= "<h3>Log</h3><div class='log'>" . htmlspecialchars(implode("\n", $log)) . "</div>
        </div></body></html>";
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: cPanel Backup <" . ($notify['from_email'] ?? 'backup@localhost') . ">\r\n";
        
        return mail($notify['email'], $subject, $html, $headers);
    } else {
        // Plain text email
        $message = "cPanel Backup Report\n" . str_repeat("=", 40) . "\n\n";
        $message .= "Status: {$overall_status}\n";
        $message .= "Storage Path: " . ($storage_config['path'] ?: '(bucket root)') . "\n";
        $message .= "Start: {$stats['start_time']}\n";
        $message .= "End: {$stats['end_time']}\n";
        
        if (isset($stats['backup_size'])) {
            $size_mb = round($stats['backup_size'] / 1024 / 1024, 2);
            $message .= "Backup Size: {$size_mb} MB\n";
        }
        
        $message .= "\nDestinations:\n" . str_repeat("-", 30) . "\n";
        
        foreach ($stats['destinations'] as $name => $d) {
            $status = strtoupper($d['status']);
            $message .= "\n[{$status}] {$name}\n";
            
            if ($d['status'] === 'success') {
                $message .= "  Location: {$d['bucket']}/{$d['key']}\n";
                if (isset($d['deleted']) && $d['deleted'] > 0) {
                    $message .= "  Old backups deleted: {$d['deleted']}\n";
                }
            } else {
                $message .= "  Error: " . ($d['error'] ?? 'Unknown') . "\n";
            }
        }
        
        $message .= "\nLog:\n" . str_repeat("-", 30) . "\n";
        $message .= implode("\n", $log);
        
        $headers = "From: cPanel Backup <" . ($notify['from_email'] ?? 'backup@localhost') . ">\r\n";
        
        return mail($notify['email'], $subject, $message, $headers);
    }
}
