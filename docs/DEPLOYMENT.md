# Store Inventory Management System (SIMS) - Deployment Guide

This guide outlines best practices and instructions for deploying the Store Inventory Management System to production environments, including cloud hosting platforms, VPS Linux servers, and containerized Docker environments.

---

## Production Readiness Checklist

Before deploying to production, verify all items on the release checklist:

1. **Environment Variables**: Ensure `.env` is configured for production:
   ```ini
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://your-domain.com
   ```
2. **Database Security**: Use strong, unique database credentials with restricted host access.
3. **HTTPS / SSL**: Enforce SSL encryption across all routes.
4. **Queue & Caching**: Configure Redis or database caching for optimal responsiveness.
5. **File Permissions**: Restrict filesystem permissions on web server directories.

---

## Recommended Hosting: Laravel Cloud / Forge

The fastest and most reliable way to deploy production Laravel applications is using **Laravel Cloud** or **Laravel Forge** provisioned onto AWS, DigitalOcean, or Hetzner VPS instances.

### Deployment via Laravel Forge
1. Connect your Git repository provider (GitHub/GitLab) to Laravel Forge.
2. Create a new site with **PHP 8.5** enabled.
3. Configure your environment variables in the Forge dashboard.
4. Use the following deployment script:
```bash
cd /home/forge/your-domain.com
git pull origin main
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
sudo -S service php8.5-fpm reload
```

---

## Manual VPS Linux Deployment (Ubuntu / Nginx)

### 1. Server Prerequisites
Install Nginx, PHP 8.5-FPM, MySQL 8, and Node.js 20:
```bash
sudo apt update && sudo apt install nginx mysql-server php8.5-fpm php8.5-mysql php8.5-xml php8.5-mbstring php8.5-curl php8.5-zip php8.5-gd php8.5-bcmath php8.5-intl git unzip nodejs npm -y
```

### 2. Configure Nginx Virtual Host
Create `/etc/nginx/sites-available/sims`:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/sims/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.5-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```
Enable the site and reload Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/sims /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### 3. Application Optimization
After deploying code to `/var/www/sims`, execute Laravel optimization commands:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## Containerized Deployment (Docker / Laravel Sail)

SIMS supports standard Docker deployment. For production containers, ensure:
1. Multi-stage builds are utilized to compile Vite assets in a Node builder image before copying into a slim PHP-FPM alpine container.
2. Persistent volumes are attached for `/storage/app` and MySQL data directories.
3. Database migrations (`php artisan migrate --force`) are executed as an initialization entrypoint command prior to starting FPM or Octane servers.

---

## Backup & Recovery

- **Database Backups**: Schedule automated daily MySQL dumps using `spatie/laravel-backup` or cron-driven `mysqldump` scripts stored offsite (AWS S3).
- **Audit Logs**: Ensure activity logs in the `activity_log` table are archived periodically to prevent unbounded database growth.
