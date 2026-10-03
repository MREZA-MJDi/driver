# Personal Drive — VPS deployment

## 1. Checkout

```bash
cd /var/www

if [ -d /var/www/personal-drive/.git ]; then
    cd /var/www/personal-drive
    git fetch origin
    git checkout fix/personal-drive-production
    git pull origin fix/personal-drive-production
else
    git clone --branch fix/personal-drive-production https://github.com/MREZA-MJDi/driver.git personal-drive
    cd /var/www/personal-drive
fi
```

## 2. Database

Create a dedicated database user instead of using MySQL root:

```bash
sudo mysql
```

Then:

```sql
CREATE DATABASE IF NOT EXISTS personal_drive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'personal_drive'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON personal_drive.* TO 'personal_drive'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Import the schema:

```bash
mysql -u personal_drive -p personal_drive < /var/www/personal-drive/database/schema.sql
```

Create the private local database configuration:

```bash
cp /var/www/personal-drive/api/.env.example.php /var/www/personal-drive/api/.env.php
nano /var/www/personal-drive/api/.env.php
```

Set the real password in that file. It is ignored by Git.

## 3. Storage permissions

```bash
mkdir -p /var/www/personal-drive/storage/files
mkdir -p /var/www/personal-drive/storage/temp
chown -R www-data:www-data /var/www/personal-drive/storage
chmod -R 775 /var/www/personal-drive/storage
```

## 4. Nginx

```bash
cp /var/www/personal-drive/deploy/nginx.conf.example /etc/nginx/sites-available/personal-drive
ln -sfn /etc/nginx/sites-available/personal-drive /etc/nginx/sites-enabled/personal-drive
nginx -t
systemctl reload nginx
```

The example listens on port 8090 and blocks HTTP access to storage.

## 5. PHP-FPM

The app uses 8 MB resumable chunks. PHP/Nginx only need to accept one chunk, not the full 5 GB file.

```bash
systemctl restart php8.5-fpm
```

If the server uses a different PHP-FPM socket, update deploy/nginx.conf.example accordingly.

## 6. Smoke test

```bash
curl -i http://127.0.0.1:8090/
curl -i http://127.0.0.1:8090/api/files.php
```

Then test upload, refresh, download, share, video preview, delete, and interrupted-upload resume.

The app stores chunks in storage/temp/<file-id>/ and completed files in storage/files/.