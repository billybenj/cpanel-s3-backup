# cPanel S3-Compatible Backup Script

Automated backup solution for cPanel accounts that uploads to multiple S3-compatible storage destinations (MinIO, Backblaze B2, AWS S3, Wasabi).

## Features

- **Multi-destination backups** - Upload to multiple storage providers simultaneously
- **S3-compatible** - Works with MinIO, Backblaze B2, AWS S3, Wasabi, and others
- **Independent retention policies** - Different retention periods for each destination
- **Multipart upload** - Handles large files efficiently (>100 MB)
- **Email notifications** - Detailed backup reports
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
retention_days = 30  ; Delete backups older than 30 days

[destination:backblaze]
enabled = true
provider = "backblaze"
endpoint = "https://s3.us-west-004.backblazeb2.com"
region = "us-west-004"
bucket = "your-b2-bucket"
access_key = "your_b2_keyID"
secret_key = "your_b2_applicationKey"
use_path_style = true
retention_days = 90  ; Keep for 90 days
```

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
Files larger than 100 MB are uploaded in 50 MB chunks using S3 multipart upload protocol for efficiency and reliability.

### Direct Filesystem Access
When running on the cPanel server itself, the script accesses backup files directly from `/home/username/` instead of downloading via HTTP.

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
