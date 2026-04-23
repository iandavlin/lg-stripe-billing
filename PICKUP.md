# Pickup — lg-stripe-billing Phase 2

*Last worked: 2026-04-22*

## Where we left off

Phase 1 (in-place WP refactor) is done and tested end-to-end. Phase 2 (extract to standalone Slim service) has a working scaffold.

**Verified today:**
- Checkout REST → session create → Stripe payment → `handleReturn` → user meta set. All green.
- Fixed regression: `LGSM_WP_User_Repository::setRole()` was calling `$user->set_role()` which *replaces* all roles. Now swaps only `looth1–4` tiers, preserves `administrator` / `bbp_participant`. Admin role was restored on user 1.
- Phase 2 scaffold boots: `GET /health` returns `200 OK` with JSON body.

## Scaffold location

- **Local repo:** `C:\Users\ianda\git-repos\lg-stripe-billing\` (git-init'd, one initial commit)
- **Server:** `ccdev@54.157.13.77:~/lg-stripe-billing/` (composer install already ran)

## Architecture decisions (locked in)

- **URL:** `/billing/*` subpath on loothgroup.com (nginx routes to new php-fpm pool).
- **DB:** separate `lg_membership` MySQL database, same MySQL instance. Reads `wp_users` / `wp_usermeta` cross-DB during transition.
- **Deploy:** git-pull on server for now. Build artifacts later if it hurts.
- **No Docker** for now. Plain php-fpm pool + nginx location block.
- **Role sync:** Slim writes role changes back to `wp_usermeta.wp_capabilities` during transition so WP role-based gating keeps working.
- **Repo naming:** `lg-stripe-billing` (short, matches URL path).

## Next steps (in order)

1. **Port interfaces** from plugin → `src/Contracts/`
   - Source: `/var/www/dev/wp-content/plugins/lg-stripe-membership/includes/contracts/`
   - Five files: `interface-user-repository.php`, `interface-settings-store.php`, `interface-notifier.php`, `interface-idempotency-store.php`, `class-user-record.php`, `class-user-create-exception.php`
   - Convert to namespaced PHP (`LGSB\Contracts\*`), drop `LGSM_` prefix, use proper interface/class syntax.

2. **Port core classes** from plugin → `src/Core/`
   - `class-user-manager.php` → `UserManager.php`
   - `class-webhook-handler.php` → `WebhookHandler.php` (dispatch logic only, HTTP layer goes in controller)
   - `class-checkout.php` session-creation logic → `CheckoutService.php` (HTTP layer goes in controller)
   - `class-reconciler.php` → `Reconciler.php` (no more WP cron; drive from systemd timer or cron)

3. **Build Slim-native adapters** → `src/Adapters/`
   - `PdoUserRepository` — reads `wp_users` initially; writes new `members` table when it exists
   - `EnvSettingsStore` — reads from `$_ENV` (loaded from `.env` at boot)
   - `MailNotifier` — SES or Postmark; FluentCRM tagging via WP REST call
   - `PdoIdempotencyStore` — dedicated `processed_events` table

4. **Wire routes** in `config/routes.php`
   - `POST /checkout` → create session
   - `GET /return` → handle Stripe return
   - `POST /portal` → customer portal
   - `POST /webhook` → Stripe webhook

5. **Stand up the pool + vhost on the server** (sudo steps — you run)
   - `/etc/php/8.3/fpm/pool.d/lg-billing.conf` (new pool, own user `lg-billing`)
   - nginx: add `location ^~ /billing/ { ... }` block to the loothgroup.com vhost, proxy to the pool
   - `systemctl restart php8.3-fpm nginx`

6. **Cutover-safe rollout**
   - Keep plugin running in WP. Point Stripe webhook at the Slim `/billing/webhook` endpoint first (reversible in Stripe dashboard).
   - Monitor parity with plugin's webhook log for a few days.
   - Move checkout UI next. Retire plugin last.

## Open questions for next session

- Do we copy the JS/CSS from `assets/` into the Slim service (served as static from `/billing/assets/`), or keep the plugin shortcode rendering the embed and just call the Slim service for checkout session creation? **Probably the latter during transition** — WP still hosts the join page UI, Slim handles server-side Stripe calls.
- JWT auth between WP and Slim, or session-cookie bridge, or just IP-trust localhost-to-localhost calls during transition? Simplest = trust localhost.
- `lg_membership` DB schema — `members`, `subscriptions`, `processed_events`. Design before coding adapters.

## Files of interest

- Plugin (source of truth for business logic): `/var/www/dev/wp-content/plugins/lg-stripe-membership/includes/`
- Scaffold: `C:\Users\ianda\git-repos\lg-stripe-billing\`
- Extraction plan (original): `/var/www/dev/wp-content/plugins/lg-stripe-membership/EXTRACTION-PLAN.md`

## Server access

`ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77`

## Smoke test the scaffold

```bash
ssh -i "..." ccdev@54.157.13.77
cd ~/lg-stripe-billing
php -S 127.0.0.1:9099 -t public &
curl http://127.0.0.1:9099/health
# → {"status":"ok","service":"lg-stripe-billing",...}
```
