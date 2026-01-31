# cPanel S3 Backup Script Analysis

## Problem: Small/Incomplete Backups Being Uploaded

### Root Cause

The script has a **race condition** in the `wait_for_backup()` function (lines 271-330). It detects a backup file too early - **while cPanel is still writing it** - and proceeds to download and upload an incomplete file.

### How the Bug Works

1. Script calls `fullbackup_to_homedir` API (line 218)
2. `wait_for_backup()` polls the home directory looking for `.tar.gz` files
3. **The problem**: It only checks if the file's `mtime` is recent (line 313):
   ```php
   if ($b['mtime'] >= ($script_start_time - 60)) {
       log_msg("Backup file ready: {$b['file']}", 1);
       return $b['file'];  // Returns immediately!
   }
   ```
4. As soon as cPanel **creates** the backup file, its mtime qualifies - but the file is still being written
5. Script immediately downloads the partial file and uploads it

### Why This Causes Small Backups

- cPanel creates the tar.gz file and starts writing to it
- The script sees the file (with recent mtime) and assumes it's complete
- Downloads whatever portion has been written so far (could be MBs instead of GBs)
- Uploads this incomplete backup to S3

### Evidence in Code

There's no verification that:
- The file size has stabilized
- cPanel's backup process has finished
- The backup file is complete/valid

### Recommended Fixes

#### Option 1: Wait for File Size to Stabilize
```php
function wait_for_backup($cp, $backup_pid) {
    // ... existing code ...

    $last_size = 0;
    $stable_count = 0;

    foreach ($backups as $b) {
        if ($b['mtime'] >= ($script_start_time - 60)) {
            // Get current file size
            $current_size = get_file_size($cp, $b['file']);

            // Wait until size stops changing for 2+ checks
            if ($current_size === $last_size && $current_size > 0) {
                $stable_count++;
                if ($stable_count >= 2) {
                    log_msg("Backup file ready: {$b['file']} ({$current_size} bytes)", 1);
                    return $b['file'];
                }
            } else {
                $stable_count = 0;
                $last_size = $current_size;
            }
        }
    }
}
```

#### Option 2: Check if Backup Process is Still Running
Use cPanel's `Backup::list_backups` or check if the PID is still active before proceeding.

#### Option 3: Look for Completion Indicator
cPanel backup files follow a pattern. Check for:
- File size > minimum threshold (e.g., 10MB for a typical cPanel account)
- Validate tar.gz header/footer integrity

### Additional Issues Found

1. **Line 313**: The `-60` tolerance is backwards - it allows files modified 60 seconds BEFORE script start, not after. Should probably be checking `mtime >= $script_start_time` without the minus.

2. **No file size tracking in polling loop**: The loop logs file age but never checks if size is growing.

3. **30-second polling interval may be too long**: Could miss the window between file creation and partial download.

### Quick Test to Confirm

Add debug logging to see the file size at detection time:
```php
log_msg("Found: {$b['file']} (size: " . get_file_size($cp, $b['file']) . " bytes, modified {$b['age']}s ago)", 2);
```

If you see small sizes (< 100MB for a typical full backup), the race condition is confirmed.

---

## Fix Applied (2026-01-26)

The `wait_for_backup()` function has been updated to wait for file size stabilization before proceeding.

### Changes Made

1. **Added file size tracking**: Now tracks file sizes across polling intervals
2. **Stability check**: Requires file size to remain unchanged for 3 consecutive checks (90 seconds at 30-second intervals)
3. **Fixed mtime comparison**: Changed from `>= ($script_start_time - 60)` to `>= $script_start_time`
4. **Enhanced logging**: Shows file size in MB and stability progress

### How It Works Now

```
[2026-01-26 03:15:30] Found: backup-1-26-2026_user.tar.gz (size: 50.25 MB, modified 15s ago)
[2026-01-26 03:15:30] File still growing: backup-1-26-2026_user.tar.gz (50.25 MB)
[2026-01-26 03:16:00] Found: backup-1-26-2026_user.tar.gz (size: 150.75 MB, modified 45s ago)
[2026-01-26 03:16:00] File still growing: backup-1-26-2026_user.tar.gz (150.75 MB)
[2026-01-26 03:16:30] Found: backup-1-26-2026_user.tar.gz (size: 250.00 MB, modified 75s ago)
[2026-01-26 03:16:30] File size stable: backup-1-26-2026_user.tar.gz (250.00 MB) - check 1/3
[2026-01-26 03:17:00] File size stable: backup-1-26-2026_user.tar.gz (250.00 MB) - check 2/3
[2026-01-26 03:17:30] File size stable: backup-1-26-2026_user.tar.gz (250.00 MB) - check 3/3
[2026-01-26 03:17:30] Backup file ready: backup-1-26-2026_user.tar.gz (250.00 MB)
```

The script will only proceed to download once the backup file has stopped growing for at least 90 seconds.

---

## Second Fix Applied (2026-01-26) - Download Failure

### Problem Discovered

After the first fix, logs showed the backup file was correctly detected at 284.81 MB, but the **download only got 0.02 MB**. The file size stabilization was working, but the download itself was failing.

```
[2026-01-26 11:00:48]   Backup file ready: backup-1.26.2026_12-59-02_crowsnestpride.tar.gz (284.81 MB)
[2026-01-26 11:00:48]   Downloading backup: backup-1.26.2026_12-59-02_crowsnestpride.tar.gz
[2026-01-26 11:00:48]     Download URL: https://localhost:2083/download?file=...
[2026-01-26 11:00:48]   Downloaded: 0.02 MB   <-- WRONG! Should be 284.81 MB
```

### Root Cause

The cPanel `/download?file=` endpoint was returning an error page (HTML) instead of the actual file. The 0.02 MB was an error page, not the backup.

Possible causes:
- API token authentication not accepted by download endpoint
- Session/cookie required for file downloads
- File path format incorrect

### Fix Applied

Rewrote `download_backup()` to try **multiple download methods** in sequence:

1. **Session-based download**: Gets a cPanel session token first, then uses it for download
2. **Direct download with API token**: Original method with Authorization header
3. **Full path download**: Tries with `/home/username/` prefix in the file path
4. **xfercpanel endpoint**: Alternative cPanel file transfer endpoint

The function now:
- Tries each method until one succeeds
- Validates downloaded file is >1MB (rejects error pages)
- Logs which method worked
- Shows detailed error info if all methods fail

### Expected Log Output

```
[2026-01-26 11:00:48]   Downloading backup: backup-1.26.2026_12-59-02_crowsnestpride.tar.gz
[2026-01-26 11:00:48]     Trying download method 1...
[2026-01-26 11:00:48]     Download URL (session): https://localhost:2083/cpsessXXXX/download?file=...
[2026-01-26 11:00:50]   Downloaded: 284.81 MB (method 1)
```

### If Downloads Still Fail

Check these in `cpanel_s3_config.ini`:
1. `domain` - Should be the actual server hostname, not `localhost` (unless running on the server itself)
2. `api_token` - Make sure it has "Full Access" or at minimum access to Backup and Fileman APIs
3. `password` - If using password auth, ensure it's correct
4. Try setting `debug = true` to see detailed error messages

---

## Third Fix Applied (2026-01-26) - HTTP 403 Forbidden

### Problem Discovered

All HTTP download methods returned **403 Forbidden**:
- API token works for UAPI calls (Fileman::list_files) but NOT for `/download` endpoint
- Session-based download failed because it requires a **password** (not API token)

```
Method 2: HTTP 403: <!DOCTYPE html><html><head><title>Forbidden</title>...
Method 3: HTTP 403: ...
Method 4: HTTP 403: ...
```

### Root Cause

The script runs **on the cPanel server itself** (localhost:2083). The backup file exists at `/home/username/backup.tar.gz` - we don't need HTTP at all!

### Fix Applied

Completely rewrote `download_backup()` to:

1. **First try direct filesystem access** - Check if `/home/username/backup.tar.gz` exists and is readable
2. **Fall back to HTTP only if direct access fails** - For cases where script runs on a different server

Also fixed cleanup logic:
- Track the original backup filename separately
- Delete backup from home directory via UAPI after all uploads complete (not before)
- Properly clean up temp directory

### Expected Behavior Now

```
[2026-01-26 11:08:47]   Downloading backup: backup-1.26.2026_13-07-01_crowsnestpride.tar.gz
[2026-01-26 11:08:47]     Using direct filesystem access: /home/crowsnestpride/backup-1.26.2026_13-07-01_crowsnestpride.tar.gz
[2026-01-26 11:08:47]   Backup file accessible: 284.81 MB
[2026-01-26 11:08:47] Backup created: backup-1.26.2026_13-07-01_crowsnestpride.tar.gz (284.81 MB)
[2026-01-26 11:08:50]     ✓ Uploaded: cpanel-backups/crowsnestpride/backup-1.26.2026_13-07-01_crowsnestpride.tar.gz
[2026-01-26 11:08:50]   Cleaning up backup from home directory...
```

The script now reads the backup file directly from disk instead of trying to download it via HTTP.

---

## Fourth Fix Applied (2026-01-26) - Separate Retention Policies

### Problem

The script was **always deleting backups from the cPanel home directory** immediately after upload. Users wanted to keep backups locally too.

### Fix Applied

Added separate retention settings for cPanel home directory vs S3 destinations:

**Config changes (`cpanel_s3_config.ini`):**
```ini
[backup]
; S3/Storage retention: Delete backups older than X days from S3 destinations
retention_days = 30

; cPanel home directory retention:
;   -1 or omit = keep forever (never delete from home directory)
;    0 = delete immediately after successful upload to S3
;   >0 = keep backups for X days, delete older ones
; cpanel_retention_days = -1
```

### Retention Behavior

| `cpanel_retention_days` | Behavior |
|------------------------|----------|
| Not set or `-1` | **Keep forever** - backups stay in home directory |
| `0` | Delete immediately after successful S3 upload |
| `7` | Keep for 7 days, delete older backups |
| `30` | Keep for 30 days, delete older backups |

### Example Config

To keep 7 days locally and 30 days on S3:
```ini
[backup]
retention_days = 30
cpanel_retention_days = 7
```

To never delete from home directory:
```ini
[backup]
; cpanel_retention_days = -1  (or just omit this line)
```

---

## Fifth Fix Applied (2026-01-26) - Per-Destination Retention

### Problem

Global `retention_days` applied to all S3 destinations. Users wanted different retention for different storage providers (e.g., keep 30 days on MinIO, 90 days on Backblaze).

### Fix Applied

Moved `retention_days` from `[backup]` section to each `[destination:*]` section.

**Config changes:**
```ini
[destination:minio]
enabled = true
provider = "minio"
; ... other settings ...
retention_days = 30    ; Keep 30 days on MinIO

[destination:backblaze]
enabled = true
provider = "backblaze"
; ... other settings ...
retention_days = 90    ; Keep 90 days on Backblaze

[destination:aws]
enabled = false
provider = "aws"
; ... other settings ...
retention_days = 365   ; Keep 1 year on AWS
```

### Retention Values

| `retention_days` | Behavior |
|-----------------|----------|
| `0` or omit | Keep forever (no cleanup) |
| `30` | Delete backups older than 30 days |
| `90` | Delete backups older than 90 days |
| `365` | Delete backups older than 1 year |

### Summary of All Retention Settings

| Setting | Location | Controls |
|---------|----------|----------|
| `cpanel_retention_days` | `[backup]` | cPanel home directory cleanup |
| `retention_days` | `[destination:*]` | Per-destination S3 cleanup |

---

## Sixth Fix Applied (2026-01-26) - HTTP 413 Upload Too Large

### Problem

Upload failed with **HTTP 413 - Request Entity Too Large**:
```
[2026-01-26 12:28:49]     ✗ Failed: Upload failed: HTTP 413
```

The script was trying to upload the entire 284 MB file in a single PUT request, but MinIO/S3 has limits on single-part upload size.

### Fix Applied

Added **S3 Multipart Upload** support for files larger than 100 MB.

**How it works:**
1. Files ≤ 100 MB: Single PUT request (original behavior)
2. Files > 100 MB: Multipart upload in 50 MB chunks

**Multipart upload process:**
1. **Initiate** - Start multipart upload, get UploadId
2. **Upload parts** - Upload file in 50 MB chunks, track ETags
3. **Complete** - Send completion request with all part ETags

### Expected Log Output

```
[2026-01-26 12:28:48]   → minio (minio)
[2026-01-26 12:28:48]     Using multipart upload for large file (298729472 bytes)
[2026-01-26 12:28:48]     Multipart upload initiated (UploadId: abc123...)
[2026-01-26 12:28:55]     Uploaded part 1 (17%)
[2026-01-26 12:29:02]     Uploaded part 2 (35%)
[2026-01-26 12:29:09]     Uploaded part 3 (52%)
[2026-01-26 12:29:16]     Uploaded part 4 (70%)
[2026-01-26 12:29:23]     Uploaded part 5 (87%)
[2026-01-26 12:29:30]     Uploaded part 6 (100%)
[2026-01-26 12:29:31]     Multipart upload completed successfully
[2026-01-26 12:29:31]     ✓ Uploaded: cpanel-backups/crowsnestpride/backup-...tar.gz
```

### Benefits

- **No size limit**: Can upload files of any size
- **Memory efficient**: Reads file in 50 MB chunks instead of loading entire file
- **Resumable**: If a part fails, only that part needs to be retried
- **Automatic cleanup**: Aborts incomplete uploads on failure
