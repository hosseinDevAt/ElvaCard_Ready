# ElvaCard Production Deployment Runbook

**Target Environment:** Pars Web Server (cPanel / Linux / CloudLinux / LiteSpeed / PHP 8.3 / MySQL 8.x)
**Deployment Repository:** `https://github.com/hosseinDevAt/ElvaCard_Ready.git`

---

## 1. Fast-Track Deployment Steps (SSH / cPanel)

### Step 1: Clone Repository
Connect via SSH to your hosting account:
```bash
cd ~
git clone https://github.com/hosseinDevAt/ElvaCard_Ready.git elvacard
cd ~/elvacard
```

### Step 2: Configure Environment File
```bash
cp .env.example .env
nano .env   # or edit via cPanel File Manager
```

Set the essential production variables:
```dotenv
APP_NAME=ElvaCard
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cpaneluser_elvacard
DB_USERNAME=cpaneluser_dbuser
DB_PASSWORD="your-strong-db-password"

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
```

### Step 3: Install Production PHP Dependencies
```bash
composer install --no-dev --optimize-autoloader
```
*(Pre-compiled frontend assets are already present in `public/build/`; running npm/vite on the hosting server is not required).*

### Step 4: Generate Application Key
```bash
php artisan key:generate
```

### Step 5: Run Database Migrations
```bash
php artisan migrate --force
```

### Step 6: Create Storage Symlink & Set Permissions
```bash
php artisan storage:link
chmod -R 775 storage bootstrap/cache
chmod 600 .env
```

### Step 7: Cache Configuration, Routes, and Views
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Step 8: Document Root Configuration (cPanel)
In cPanel **Domains** / **Subdomains**:
- Point Document Root of your primary domain to:
  `/home/cpaneluser/elvacard/public`
*(If your primary domain is restricted to `public_html`, configure a symlink from `public_html` to `elvacard/public` or move `elvacard/public/*` into `public_html` while updating `index.php` paths).*

---

## 2. Administrator Account Setup

Do **not** run `db:seed` in production. Create the administrative account securely via Tinker:

```bash
php artisan tinker
```
```php
$admin = App\Models\User::firstOrCreate(
    ['phone' => '09376608789'],
    ['name' => 'مدیر سامانه', 'email' => 'admin@elvacard.com', 'password' => Hash::make('YourStrongPasswordHere')]
);
$admin->forceFill(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()])->save();
```

---

## 3. Operational Notes & Caching

### Post-Deployment Commands
Whenever code is updated in the future:
```bash
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Incident & Rollback Runbook
- Put application in maintenance mode:
  ```bash
  php artisan down --secret="emergency-bypass-key"
  ```
- Inspect recent error logs:
  ```bash
  tail -n 50 storage/logs/laravel.log
  ```
- Bring application back online:
  ```bash
  php artisan up
  ```
