# DoodhDesk SaaS - Production Deployment & Operations Guide

This guide provides the complete, production-grade instructions for deploying and running **DoodhDesk** on a production server (Ubuntu/Debian VPS, AWS EC2, DigitalOcean Droplet, Linode, or Docker).

---

## 1. System Requirements & Architecture

| Component | Minimum Specification | Recommended Production |
|---|---|---|
| **Operating System** | Ubuntu 22.04 LTS / Debian 12 | Ubuntu 24.04 LTS |
| **CPU / RAM** | 1 vCPU / 1 GB RAM | 2 vCPU / 2 GB RAM (for Dompdf PDF rendering) |
| **PHP** | PHP 8.2 | PHP 8.4 with PHP-FPM & Zend OPcache |
| **PHP Extensions** | `pdo_mysql`, `gd`, `intl`, `dom`, `zip`, `curl`, `mbstring`, `opcache` | All enabled |
| **Database** | MySQL 8.0+ or MariaDB 10.5+ | MariaDB 10.11+ / MySQL 8.0 with InnoDB |
| **Web Server** | Nginx or Apache 2.4 | Nginx with PHP-FPM |
| **SSL / TLS** | TLS 1.2+ | Free Let's Encrypt SSL via Certbot |

---

## 2. Step-by-Step Server Setup (Ubuntu / Debian VPS)

### Step 1: System Packages Installation
Connect to your remote server via SSH:

```bash
ssh root@your-server-ip
```

Update system repositories and install Nginx, MariaDB, PHP 8.4, and required extensions:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip

# Add Ondřej Surý PHP PPA (for Ubuntu/Debian)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install Nginx, MariaDB, and PHP 8.4-FPM
sudo apt install -y nginx mariadb-server mariadb-client \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-gd php8.4-intl \
    php8.4-dom php8.4-zip php8.4-curl php8.4-mbstring php8.4-opcache
```

---

### Step 2: Database Configuration
Secure MariaDB and create your production database and dedicated database user:

```bash
sudo mysql_secure_installation
```

Log in to MySQL as root:

```bash
sudo mysql -u root -p
```

Execute the database provisioning queries (replace `YourSecureDbPassword123!` with a strong password):

```sql
CREATE DATABASE doodhdesk_prod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'doodhdesk_user'@'localhost' IDENTIFIED BY 'YourSecureDbPassword123!';
GRANT ALL PRIVILEGES ON doodhdesk_prod.* TO 'doodhdesk_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

### Step 3: Deploy Application Code & Configure Permissions
Clone your repository to `/var/www/doodhdesk`:

```bash
sudo mkdir -p /var/www/doodhdesk
sudo chown -R $USER:$USER /var/www/doodhdesk
cd /var/www/doodhdesk

# Clone your project code:
git clone <your-repository-url> .
```

Install Composer production dependencies (without dev packages):

```bash
php composer.phar install --no-dev --optimize-autoloader
```

Set appropriate directory permissions for the web server (`www-data`):

```bash
sudo chown -R www-data:www-data /var/www/doodhdesk/storage
sudo chown -R www-data:www-data /var/www/doodhdesk/public/uploads
sudo chmod -R 775 /var/www/doodhdesk/storage
sudo chmod -R 775 /var/www/doodhdesk/public/uploads
```

---

### Step 4: Environment Configuration (`.env`)
Create your live `.env` configuration file from the template:

```bash
cp .env.example .env
nano .env
```

Configure your production parameters:

```ini
APP_NAME="DoodhDesk SaaS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.doodhdesk.com
APP_TIMEZONE=Asia/Kolkata

# Strictly set to false to disable demo logins and test bars
ALLOW_DEMO_MODE=false

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=doodhdesk_prod
DB_USERNAME=doodhdesk_user
DB_PASSWORD="YourSecureDbPassword123!"

APP_USE_INDEX_IN_URL=false
APP_REWRITE_ENABLED=true
SESSION_SECURE_COOKIE=true
```

Save and exit (`Ctrl+O`, `Enter`, `Ctrl+X`).

Secure file permissions of `.env` so other system users cannot read your database credentials:

```bash
chmod 600 /var/www/doodhdesk/.env
```

---

### Step 5: Database Migration & Super-Admin Provisioning
Run the automated migration utility to create all tables and default platform rules:

```bash
php scripts/migrate.php
```

Provision your production Super-Admin administrator account:

```bash
php scripts/setup_admin.php --email="admin@yourdomain.com" --password="YourAdminPassword123" --name="Platform Administrator"
```

---

### Step 6: Web Server Configuration (Nginx + PHP-FPM)
Copy the production Nginx virtual host template:

```bash
sudo cp nginx.conf.example /etc/nginx/sites-available/doodhdesk.conf
sudo nano /etc/nginx/sites-available/doodhdesk.conf
```

> **IMPORTANT:** Ensure `server_name` is set to your live domain (e.g., `app.doodhdesk.com`) and `root` points strictly to `/var/www/doodhdesk/public`.

Enable the site and verify configuration:

```bash
sudo ln -s /etc/nginx/sites-available/doodhdesk.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

### Step 7: Free SSL Certificate (Let's Encrypt / Certbot)
Install Certbot and obtain an automated SSL certificate:

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d app.doodhdesk.com
```

Certbot will automatically configure HTTPS, HTTP-to-HTTPS redirect, and setup daily automated renewal via systemd timer.

---

### Step 8: Production Readiness Pre-Flight Audit
Run the built-in diagnostic auditor to confirm all requirements, file permissions, and security configurations:

```bash
php scripts/health_check.php
```

You should see:
```text
========================================================
   DOODHDESK SAAS - PRODUCTION READINESS AUDIT          
========================================================
• PHP Version >= 8.2 ......................... [OK]
• PHP Extension [pdo] ........................ [OK]
• PHP Extension [pdo_mysql] .................. [OK]
...
• Database connectivity ...................... [OK]
• Directory writable: storage/logs ........... [OK]
• Directory writable: storage/backups ........ [OK]
• Directory writable: public/uploads ......... [OK]
• Uploads folder script execution blocked .... [OK]
• Storage folder direct access blocked ....... [OK]
--------------------------------------------------------
AUDIT SUMMARY: 0 Error(s), 0 Warning(s)
[PERFECT] Application is 100% production ready!
```

Test the live health endpoint in your browser or with curl:

```bash
curl -i https://app.doodhdesk.com/health
```

---

### Step 9: Automated Cron Jobs (Backups & Operations)
Add automated daily database backups to cron:

```bash
sudo crontab -e
```

Add the following daily job at 02:00 AM:

```cron
# Daily database backup and 14-day retention rotation
0 2 * * * /usr/bin/php /var/www/doodhdesk/scripts/backup.php >> /var/www/doodhdesk/storage/logs/backup.log 2>&1
```

---

## 3. Alternative: Deploying with Docker Compose

If deploying on Docker (e.g. AWS ECS, DigitalOcean App Platform, Portainer, or single VPS):

1. Clone repository to server.
2. Build and start containers:
   ```bash
   docker compose up -d --build
   ```
3. Run migrations and provision admin:
   ```bash
   docker compose exec app php scripts/migrate.php
   docker compose exec app php scripts/setup_admin.php
   ```
4. Access app at `http://your-server-ip:8080` (or behind Cloudflare / Traefik / Nginx reverse proxy on port 443).

---

## 4. Key Production Checklist

- [x] `APP_ENV=production` & `APP_DEBUG=false` configured in `.env`.
- [x] `ALLOW_DEMO_MODE=false` (demo auto-logins & demo switchbars disabled).
- [x] DocumentRoot pointed strictly to `public/`.
- [x] Direct execution of scripts in `public/uploads/` blocked via `.htaccess` and Nginx rules.
- [x] Sensitive files (`.env`, `storage/`, `composer.*`) blocked from web access.
- [x] Session cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure`) enabled.
- [x] Security headers (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`) sent.
- [x] CSRF protection enforced on all web POST forms.
- [x] Application error logging configured to `storage/logs/error.log`.
- [x] Health checks monitored via `/health` or `/api/health`.
- [x] Database backups scheduled daily via `scripts/backup.php`.
