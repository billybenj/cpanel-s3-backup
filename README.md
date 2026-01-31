# cPanel S3-Compatible Backup Script

Automated backup solution for cPanel accounts that uploads to multiple S3-compatible storage destinations (MinIO, Backblaze B2, AWS S3, Wasabi).

## Features

- **Multi-destination backups** - Upload to multiple storage providers simultaneously
- **S3-compatible** - Works with MinIO, Backblaze B2, AWS S3, Wasabi, and others
- **Independent retention policies** - Different retention periods for each destination
- **Per-destination notifications** - Control email alerts per provider (always, on_error, never)
- **Multipart upload** - Handles large files efficiently (>100 MB)
- **Email notifications** - Detailed HTML or plain-text backup reports
- **Flexible cleanup** - Separate retention for cPanel home directory and cloud storage
- **Comprehensive logging** - Detailed logs with rotation

## Requirements

- PHP 7.4+ with cURL support
- cPanel account with API access
- S3-compatible storage credentials

## Installation

1. Upload `cpanel_s3_backup.php` to your cPanel account (outside public_html)
2. Copy `cpanel_s3_config.ini.example` to `cpanel_s3_config.ini`
3. Set permissions: `chmod 600 cpanel_s3_config.ini`
4. Edit configuration file with your credentials

## Configuration

### Basic Setup

```ini
[cpanel]
username = "your_cpanel_username"
api_token = "your_api_token_here"  ; Create at cPanel > Security > Manage API Tokens
domain = "your-server.com"
secure = true

[backup]
type = "full"  ; Options: full, home, mysql

[storage]
path = "my-cpanel-account"  ; Folder in bucket for backups

[notification]
email = "your-email@example.com"
```

### Storage Destinations

Define multiple destinations with independent settings:

```ini
[destination:minio]
enabled = true
provider = "minio"
endpoint = "https://your-truenas-ip:9000"
region = "us-east-1"
bucket = "cpanel-backups"
access_key = "your_minio_access_key"
secret_key = "your_minio_secret_key"
use_path_style = true
verify_ssl = false       ; Allow self-signed certificates
retention_days = 30      ; Delete backups older than 30 days
notify = "always"        ; Send email on every backup

[destination:backblaze]
enabled = true
provider = "backblaze"
endpoint = "https://s3.us-west-004.backblazeb2.com"
region = "us-west-004"
bucket = "your-b2-bucket"
access_key = "your_b2_keyID"
secret_key = "your_b2_applicationKey"
use_path_style = true
verify_ssl = true        ; Verify SSL certificate (recommended for production)
retention_days = 90      ; Keep for 90 days
notify = "on_error"      ; Only notify if this destination fails
```

### Upload Performance

Control multipart upload behavior in the `[storage]` section:

```ini
[storage]
path = "my-cpanel-account"
multipart_threshold_mb = 20   ; Files larger than this use multipart upload (default: 20)
multipart_chunk_mb = 50       ; Size of each upload chunk in MB (default: 50)
```

**Recommendations:**
- **Slow connections:** Lower `multipart_chunk_mb` to 20-30 MB to avoid timeouts
- **Fast connections:** Increase to 100+ MB for better performance
- **Small backups:** Raise `multipart_threshold_mb` if your backups are typically <100 MB

### Retention Policies

**cPanel Home Directory:**
```ini
[backup]
cpanel_retention_days = -1  ; Keep forever (default)
cpanel_retention_days = 0   ; Delete immediately after upload
cpanel_retention_days = 7   ; Keep for 7 days
```

**Storage Destinations:**
```ini
[destination:minio]
retention_days = 0   ; Keep forever
retention_days = 30  ; Delete backups older than 30 days
```

### Email Notifications

**Per-Destination Notification Control:**

Each destination can independently control when it triggers email notifications:

```ini
[destination:minio]
notify = "always"    ; Send email on every backup (success or failure)

[destination:backblaze]
notify = "on_error"  ; Only send email if this destination fails

[destination:aws]
notify = "never"     ; Never send email for this destination
```

**How it works:**
- Email is sent if **any** destination matches its notification preference
- One consolidated email shows all destinations (for context)
- Email subject shows overall status: ✓ SUCCESS, ⚠ PARTIAL, or ✗ FAILED

**Examples:**

| Scenario | MinIO (always) | B2 (on_error) | Result |
|----------|----------------|---------------|---------|
| All succeed | ✓ Success | ✓ Success | **Email sent** (MinIO=always) |
| B2 fails | ✓ Success | ✗ Failed | **Email sent** (both: MinIO=always, B2=failed) |
| All set to "never" | — | — | **No email** |

**Global email setting:**
```ini
[notification]
email = "your-email@example.com"  ; Must be set for any notifications
from_email = "backup@your-domain.com"
html_email = true
```

If `email` is empty, no notifications are sent regardless of destination settings.

## Usage

### Manual Run
```bash
php cpanel_s3_backup.php
```

### Automated (Cron)
```bash
# Daily backup at 2 AM
0 2 * * * cd /path/to/script && php cpanel_s3_backup.php > /dev/null 2>&1
```

## How It Works

1. **Creates backup** - Calls cPanel's fullbackup API
2. **Waits for completion** - Monitors file size until stable
3. **Accesses backup** - Direct filesystem access (or HTTP fallback)
4. **Uploads to destinations** - Multipart upload for large files
5. **Cleans up** - Removes old backups based on retention policies
6. **Sends notification** - Email report with status

## Technical Details

### File Size Stabilization
The script waits for backup file size to remain unchanged for 90 seconds (3 consecutive checks) to ensure cPanel has finished writing the file.

### Multipart Upload
Files larger than the configured threshold (default: 20 MB) are uploaded in configurable chunks (default: 50 MB) using S3 multipart upload protocol for efficiency and reliability. Both values can be customized in the `[storage]` section.

### Direct Filesystem Access
When running on the cPanel server itself, the script accesses backup files directly from `/home/username/` instead of downloading via HTTP. This eliminates memory overhead and temp directory usage.

**Temp directory behavior:**
- ✅ **Local execution:** No temp directory created (direct filesystem access)
- ⚠️ **Remote execution:** Temp directory created only when HTTP download fallback is needed

### SSL Certificate Verification
Each destination can independently control SSL certificate verification via the `verify_ssl` setting:
- **`verify_ssl = true`** (default): Verifies SSL certificates — recommended for production services (AWS, Backblaze, Wasabi)
- **`verify_ssl = false`**: Allows self-signed certificates — useful for self-hosted MinIO/TrueNAS

If SSL verification fails with production services, ensure your server's CA certificates are up to date:
```bash
# Update CA certificates (Ubuntu/Debian)
sudo apt update && sudo apt install ca-certificates

# Update CA certificates (CentOS/RHEL)
sudo yum update ca-certificates
```

## Troubleshooting

**Upload fails with HTTP 413:**
- Ensure the script has multipart upload support (files >100 MB)
- Check storage provider's upload limits

**Backup file not found:**
- Verify cPanel username in config
- Check backup type settings
- Review cPanel backup logs

**Authentication errors:**
- Verify API token has Backup and Fileman permissions
- Check storage credentials
- Ensure endpoint URLs are correct

## License

MIT

## Contributing

Issues and pull requests welcome!
