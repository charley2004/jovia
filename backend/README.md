# Jovia Network — backend (Laravel + Filament)

Laravel 12 · Filament 3 · MySQL · PHP 8.2+

One Laravel app runs everything:

- **`/api/...`** — the member API used by the website and dashboard (session cookie + CSRF)
- **`/admin`** — the Filament admin panel
- **everything else** — the static website in the folder above `backend/` (`index.html`, `dashboard.html`, `css/`, `js/`, `games/` …), served by `SiteController`

## What it does

| Area | How it works |
| --- | --- |
| Accounts | Sign-up (all 54 African countries), sign-in by email or phone, session cookie, hashed passwords, password reset by email (when a mailer is set) or by an admin |
| Activation | Member pays by bank transfer and submits it; admin approves in Filament (or activates the member from the Members list) → account active, ₦15,000 Welcome Bonus |
| Refunds | A member whose account was never activated requests a refund from the dashboard; admin marks it refunded |
| Task timers | Video, music, gameplay and meta modules. Time only counts while the dashboard is open (heartbeats every 5 s); YouTube/Spotify time only while playing; games pause after 20 s idle. The server decides when a session is complete; daily caps reset at midnight Lagos time |
| Meta tasks | Instructions + link; tasks marked "requires proof" reserve the reward and pay when an admin approves |
| Marketplace | Listings with photos (admin approval pays ₦3,000). Buy with the wallet in full or "pay small-small" (50% deposit, balance within 14 days). Money is held until the buyer confirms delivery; auto-released 7 days after shipping; overdue instalments are cancelled and the deposit returned. Disputes resolved in Filament |
| Friday Bonus | Each Friday at 12:00 Lagos time a number appears; the first 10 members to claim win ₦10,000 |
| Wallet | Ledger of every credit and debit; withdrawals to a saved bank account; admin marks paid or rejects (rejected amounts go back to the balance) |
| Notifications | Dashboard bell for every approval, payout and sale; also emailed when a mailer is configured |
| AI Assistant | Google Gemini (free tier, `gemini-3.8-flash`) answers members' questions from the live platform rules. Free-tier prompts may be used by Google, so no names, contact details or balances are ever sent. Needs a free `GEMINI_API_KEY` from aistudio.google.com; 40 messages per member per day |

### Filament admin (`/admin`)

| Section | Pages |
| --- | --- |
| Queues | Activation payments (approve / reject / mark refunded) · Withdrawals (mark paid / reject) · Marketplace listings · Task proofs · Orders & disputes (refund buyer / pay seller) |
| Members | Search, suspend / restore, reset password, adjust balance |
| Platform | Task modules (rates, limits, focus rule) with their content · Settings (fees, bonuses, activation bank account, Friday Bonus, marketplace terms, assistant limit, support links) |

All business logic lives in `app/Services` (`Wallet`, `Orders`, `AdminActions`); controllers and Filament actions both call it.

## Run locally

```bash
cd backend
composer install
cp .env.example .env          # set DB_*, ADMIN_EMAIL, ADMIN_PASSWORD
php artisan key:generate
php artisan migrate --seed    # tables, task modules, built-in games, settings, admin account
php artisan storage:link      # marketplace photos at /storage/...
php artisan serve             # http://localhost:8000  ·  admin: http://localhost:8000/admin
```

Tests: create an empty `jovia_test` MySQL database, then `php artisan test`.

## Deploy on HostAfrica (DirectAdmin, via GitHub)

Layout on the server:

- `~/domains/joviaplatform.ng/app`: this repository (`git clone https://github.com/charley2004/jovia.git app`)
- `~/domains/joviaplatform.ng/public_html`: the web root, written by the deploy scripts. It holds the website files (HTML, CSS, JS, images, fonts, games, blog), served directly by the web server without PHP; Filament's `css/filament` and `js/filament`; the `.htaccess` from `backend/deploy/web-root.htaccess`; and a small `index.php` that hands `/api`, `/admin`, `/sanctum`, `/livewire` and `/storage` to Laravel.

First time, in DirectAdmin → Terminal (PHP 8.2+, database created in MySQL Management):

```bash
cd ~/domains/joviaplatform.ng && git clone https://github.com/charley2004/jovia.git app
DB_NAME=… DB_USER=… DB_PASS='…' bash app/backend/deploy/setup.sh
```

It installs packages, writes `.env`, migrates and seeds, publishes the web root and prints a temporary admin password plus the cron line (every minute: `php …/artisan schedule:run`).

After every push: `bash ~/domains/joviaplatform.ng/app/backend/deploy/update.sh` (pull, packages, migrations, web root, caches). Run it again after editing `.env`.

Marketplace photos are served by Laravel at `/storage/...`, so no storage symlink is required.

## Deploy (other hosts with SSH)

1. Upload the whole `Jovia` folder (site files + `backend/`).
2. Point the domain's document root at **`backend/public`**.
3. Create a MySQL database; fill in `.env` from `.env.example` with `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://joviaplatform.ng`, `SANCTUM_STATEFUL_DOMAINS=joviaplatform.ng,www.joviaplatform.ng`, `SESSION_SECURE_COOKIE=true`.
4. Run `composer install --no-dev --optimize-autoloader`, `php artisan key:generate`, `php artisan migrate --seed --force`, `php artisan storage:link`, `php artisan optimize`.
5. Add the scheduler cron (settles marketplace orders hourly):
   `* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1`
6. Sign in at `/admin` with the admin email and fill in **Settings**: activation bank account, support link and email.
7. Optional: set `MAIL_*` for emailed notifications and password resets, and `GEMINI_API_KEY` (free, from aistudio.google.com) for the assistant.

If the site files live somewhere else (e.g. `public_html`), set `SITE_PATH` in `.env`.

## Back up

The MySQL database and `storage/app/public` (marketplace photos).
