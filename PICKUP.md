# Pickup — lg-stripe-billing

*Last worked: 2026-04-25*

## Production deploy ownership (flag for later)

Dev install lives under `/home/ccdev/lg-stripe-billing` (ccdev owned).
**Production install must live under a path owned by `ubuntu`** —
matches the rest of our prod ops surface and avoids privilege drift
when systemd/php-fpm pools talk to each other. Likely target:
`/var/www/billing/lg-stripe-billing` or `/home/ubuntu/lg-stripe-billing`.
Pool config + systemd units will need the matching user at cutover.

## Architecture (current)

Two services, sharing one database:

- **Slim app (this repo)** — user-facing HTTP only.
  - `POST /v1/checkout` — create Stripe Checkout session
  - `POST /v1/portal` — create Customer Portal session
  - `GET  /v1/return` — handle Stripe redirect, immediate grant
  - `GET  /health`
  - Reads/writes `lg_membership` on the synchronous user paths.
  - **Does not** receive Stripe webhooks. Does not run cron.

- **WP plugin (`lg-member-sync`, separate repo, not yet built)** — async event processing.
  - WP cron polls Stripe Events API + Patreon OAuth
  - Writes to `lg_membership` (customers, subscriptions, entitlements)
  - Owns role arbitration (`lg_role_sources` table)
  - Only place that writes `wp_capabilities`
  - Absorbs the existing Patreon plugin's polling logic

Source of truth for access checks: `lg_membership.entitlements`. WP `wp_capabilities` is a synced projection of that.

## Slim status

Done:
- Slim 4 scaffold + DI + `/health` endpoint.
- Domain DTOs (`Customer`, `Subscription`, `Entitlement`).
- Repository interfaces (`CustomerRepository`, `SubscriptionRepository`, `EntitlementRepository`, `ProductRepository`).
- `SettingsStore` interface (trimmed to user-facing API needs).
- `StripeGateway` interface (typed facade over Stripe SDK).
- Core services: `CheckoutService`, `CustomerManager`, `EntitlementManager`.
- Schema (`db/schema.sql` + `db/migrations/001_init.sql`) — entitlement-centric, future-proof for tickets / lifetime memberships / regional pricing.
- System map doc at `docs/system-map.html`.

Removed during pivot to polling architecture (commit `cleanup`):
- `WebhookHandler`, `Reconciler`, `WpRoleSync`, `IdempotencyStore`,
  `UserRecord`/`UserRepository`/`UserCreateException` (WP-bridge legacy),
  `Notifier`, and the `processed_events` table.

## Next steps

1. **Adapters** in `src/Adapters/`:
   - `PdoCustomerRepository`
   - `PdoSubscriptionRepository`
   - `PdoEntitlementRepository`
   - `PdoProductRepository` (region-aware price resolution)
   - `LiveStripeGateway`
   - `EnvSettingsStore`
2. **Routes + controllers**: `/v1/checkout`, `/v1/portal`, `/v1/return`, plus a small `/v1/tiers` for the frontend join-page.
3. **DI wiring** in `config/container.php`.
4. **Apply schema** to dev MySQL: `mysql lg_membership < db/schema.sql`.
5. **Seed** initial products + prices + region tags (one-off SQL script).
6. **nginx + php-fpm pool** for `/billing/` subpath (sudo on EC2).
7. **End-to-end test** of checkout flow (without poller — checkout session + return URL grant).
8. **WP plugin** in a separate repo. Polling + arbiter + role writes.
9. **Cutover**: stop the old plugin's webhook, retire it after the new poller is solid.

## Server access

`ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77`

## Local dev

```bash
cd C:/Users/ianda/git-repos/lg-stripe-billing
php -l $(find src -name '*.php')   # syntax check
# composer install — already done on server
```

## Smoke test (when adapters exist)

```bash
php -S 127.0.0.1:9099 -t public &
curl http://127.0.0.1:9099/health
```
