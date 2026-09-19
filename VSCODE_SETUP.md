# FF Arena - VS Code Clean Setup

**Final Clean Build - 2026-09-17**
**74 Blade Views, 187 Web Routes, 88 API Routes, Profile/Avatar/Settings/Internet Check Restored**

## Quick Start in VS Code

### 1. Prerequisites
- PHP 8.4+ (with extensions: mbstring, curl, xml, sqlite3, pdo_sqlite, bcmath, zip, intl)
- Composer 2.x
- Node.js 18+ and npm
- SQLite (for local/test) or PostgreSQL (production)

### 2. Install Dependencies
```bash
# Clone / unzip
cd FF-Arena-Final-Clean

# PHP dependencies
composer install

# JS dependencies
npm install

# Environment
cp .env.example .env
php artisan key:generate

# Database (SQLite local)
touch database/database.sqlite
php artisan migrate --force

# Storage & cache
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache
php artisan storage:link

# Build frontend (Vite)
npm run build
# OR for dev
npm run dev

# Also create fallback manifest for testing (if not building)
mkdir -p public/build/assets
cp resources/css/app.css public/build/assets/app.css
cp resources/js/app.js public/build/assets/app.js
echo '{"resources/css/app.css":{"file":"assets/app.css","src":"resources/css/app.css","isEntry":true},"resources/js/app.js":{"file":"assets/app.js","src":"resources/js/app.js","isEntry":true}}' > public/build/manifest.json
```

### 3. Run
```bash
php artisan serve --host=0.0.0.0 --port=8000
# Visit http://localhost:8000
```

### 4. Test
```bash
php artisan test
# OR
vendor/bin/phpunit tests/Feature/R10 tests/Feature/ProfileAvatarInternetTest.php --testdox
```

Expected: 66 tests PASS (58 R10 + 8 Profile/Avatar/Internet)

### 5. Features Restored

- **Profile**: /profile, /profile/edit with avatar upload (private storage via /avatar/{user}), bio, username cooldown 30 days
- **Settings**: /settings/security, /settings/sessions, /settings/login-history, /settings/connected-accounts, /settings/payment-methods with internet status
- **Avatar**: x-avatar component, private serving, fallback SVG initials, 2MB max, 100x100 min
- **Internet Check**: offline-banner, data-internet-status, navigator.onLine + fetch /up, 30s interval, toast, data-require-online disable
- **Design System**: 16K CSS with :focus-visible, prefers-reduced-motion, 900px breakpoint, pointer:coarse, 44px touch targets, .table-wrap, skip-link
- **74 Views**: layouts/app with landmarks, home, auth, tournaments, teams, wallet, payment, notifications, admin/*, etc.
- **187 Web Routes**: from 2 to 187, all with CSRF, auth, active, admin middleware
- **88 API Routes**: real controllers (not stub __call), bearer auth, idempotency, throttling

### 6. Clean Duplicates Removed

- Removed duplicate folders: payment-gateway/ (partial copy of services/payment-gateway-go), security-service/ (partial of services/security-rust)
- Restored 21 Api/V1 stub controllers (__call fake) to real production implementations
- Removed .phpunit.result.cache, storage logs, cache data
- No duplicate class names in same namespace
- No duplicate functions

### 7. Project Structure
```
app/
  Http/Controllers/ (web: 20 controllers)
  Http/Controllers/Api/V1/ (api: 21 real controllers)
  Models/ (14 models: User, Wallet, Payment, etc.)
  Policies/ (UserPolicy, PaymentMethodPolicy)
resources/
  views/ (74 blade)
  css/app.css (16K)
  js/app.js (13K)
routes/
  web.php (187 routes)
  api.php (88 routes)
database/
  migrations/ (5 migrations)
public/
  build/manifest.json (Vite)
  css/app.css fallback
  js/app.js fallback
services/
  payment-gateway-go/ (full Go service)
  security-rust/ (full Rust service)
```

### 8. No Secrets

- .env.example contains placeholders only (CHANGE_ME_*)
- No hardcoded prod creds, no private keys committed
- Audit logs redacted, health responses no secrets

### 9. Production Ready

- SQLite works for local/test
- PostgreSQL ready (dual driver)
- Redis optional (database fallback)
- Financial integrity: ledger sum == wallet balance, one provider success = one internal success = one wallet credit
- Idempotency: X-Idempotency-Key + provider reference
- Webhook: raw body, HMAC, timestamp, replay protection, transactional

Enjoy coding in VS Code!
