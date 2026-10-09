# Tiamir Drive — Internal File Sharing

Tiamir Drive is a lightweight PHP/MySQL file workspace for internal data exchange. The current application includes file listing and uploads, resumable chunk upload support, downloads, share links, and browser-based file preview flows. Its backend is plain PHP/PDO rather than Laravel.

## Stack and requirements
- PHP 8.2+ with PDO MySQL enabled (match PHP-FPM and CLI versions)
- MySQL/MariaDB
- Nginx or Apache configured to serve the `public/` entry point and route API requests
- Writable private `storage/files` and `storage/temp` directories

## Local/VPS setup
1. Clone the repository:
   ```bash
   git clone https://github.com/MREZA-MJDi/driver.git personal-drive
   cd personal-drive
   ```
2. Create a dedicated MySQL database and least-privilege user. Import the schema:
   ```bash
   mysql -u YOUR_DB_USER -p YOUR_DB_NAME < database/schema.sql
   ```
3. Create the local config from the example:
   ```bash
   cp api/.env.example.php api/.env.php
   ```
   Edit `api/.env.php` with the database host, database name, username, and password. This file contains secrets and must never be committed.
4. Create writable storage directories:
   ```bash
   mkdir -p storage/files storage/temp
   chown -R www-data:www-data storage
   chmod -R 775 storage
   ```
5. Configure the web server to use `public/` as the document root and adapt `deploy/nginx.conf.example` to the host, PHP-FPM socket, and deployment paths. The example configuration is a starting point, not a drop-in production config.
6. Validate PHP syntax and configuration, then test upload, interrupted-upload resume, download, share-link expiry, preview, and deletion.

## Upload behavior
The deployment notes describe chunked uploads (8 MB chunks) and large-file support. Server limits should accommodate one chunk at a time, and application-level limits/storage capacity still need to be reviewed for your environment.

## Security checklist
- Do not expose `storage/` directly over HTTP.
- Keep `api/.env.php` private and outside version control.
- Use a dedicated database account, not MySQL root.
- Confirm authorization and expiration behavior for shared links.
- Apply HTTPS, rate limits, upload validation, backups, and monitoring before exposing the service beyond a trusted network.

## Deployment notes
See [deploy/README.md](deploy/README.md) and [Nginx example](deploy/nginx.conf.example).

## Repository
https://github.com/MREZA-MJDi/driver
