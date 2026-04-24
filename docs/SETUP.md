# Setup — first-time deploy on EC2

Steps to bring the Slim app from "code on disk" → "responds to curl on the box."

## 1. Pull latest code on server

```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
cd ~/lg-stripe-billing
git pull
composer install --no-dev   # only if vendor/ is empty
```

## 2. Provision the database (one-time, needs root)

Edit `db/setup-database.sql` and replace `CHANGE_ME` with a strong password.

Apply as MySQL root (you'll be prompted for the root password):

```bash
sudo mysql < ~/lg-stripe-billing/db/setup-database.sql
```

Apply the schema using the new credentials:

```bash
mysql -u lg_membership -p lg_membership < ~/lg-stripe-billing/db/schema.sql
mysql -u lg_membership -p lg_membership < ~/lg-stripe-billing/db/seed.sql
```

## 3. Populate `.env` on the server

`~/lg-stripe-billing/.env` currently has placeholders. Fill in real values:

```ini
APP_ENV=dev
APP_DEBUG=true
APP_BASE_URL=https://dev.loothgroup.com/billing
APP_HOME_URL=https://dev.loothgroup.com

STRIPE_MODE=test
STRIPE_SECRET_KEY=<from wp option lgsm_test_secret_key>
STRIPE_PUBLISHABLE_KEY=<from wp option lgsm_test_publishable_key>

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=lg_membership
DB_USER=lg_membership
DB_PASSWORD=<the password set in step 2>
```

The Stripe test keys live in the existing WP plugin's options:

```bash
cd /var/www/dev
wp option get lgsm_test_secret_key
wp option get lgsm_test_publishable_key
```

## 4. Smoke test

Bind a local PHP server and curl `/health`:

```bash
cd ~/lg-stripe-billing
php -S 127.0.0.1:9099 -t public > /tmp/lgsb.log 2>&1 &
curl -s http://127.0.0.1:9099/health
# Expect: {"status":"ok","service":"lg-stripe-billing","time":"...","env":"dev"}
```

Then create a checkout session:

```bash
curl -s -X POST http://127.0.0.1:9099/v1/checkout \
  -H 'Content-Type: application/json' \
  -d '{"price_id":"price_1QlXoLHg6gcIV22bj141Eoke","email":"smoketest@example.com"}'
# Expect: {"clientSecret":"cs_test_..."}
```

If both succeed, the user-facing API is functional. Remaining work to make it live:

1. nginx `location ^~ /billing/` → php-fpm
2. dedicated `lg-billing` php-fpm pool
3. Build the WP polling plugin (separate repo)

Stop the local PHP server when done:

```bash
pkill -f "php -S 127.0.0.1:9099"
```

## Troubleshooting

- **`Access denied for user 'lg_membership'`** — `.env` `DB_PASSWORD` doesn't match what was set in step 2.
- **`No tier mapping for price ...`** — the price ID in your test request isn't in the `prices` table. Check `seed.sql`.
- **`No publishable key`** — `STRIPE_SECRET_KEY` not loaded from `.env`. Confirm the file is at `~/lg-stripe-billing/.env` (not `.env.local` etc).
- **Container errors at boot** — `tail -f ~/lg-stripe-billing/logs/app.log` and `tail -f /tmp/lgsb.log`.
