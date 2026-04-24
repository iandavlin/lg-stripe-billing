# lg_membership database

**Authoritative state:** `schema.sql` — the current desired shape of the DB. Treat as source of truth when bootstrapping or inspecting.

**Change history:** `migrations/NNN_*.sql` — applied in order. Each file is additive; never edit a migration after it's been applied in any environment.

## Workflow

- **Change the schema:** edit `schema.sql`, then add a new `migrations/NNN_description.sql` with only the delta.
- **Bootstrap fresh:** `mysql lg_membership < schema.sql`.
- **Apply changes to an existing DB:** run the latest un-applied migration files in order.

`001_init.sql` is a snapshot of `schema.sql` at creation. Future migrations are deltas only.

## Tables

| Table | Purpose |
|---|---|
| `customers` | Identity. UUID + email anchored; `stripe_customer_id` linked when present. |
| `wp_user_bridge` | Transitional map to `wp_users`. Drop at cutover. |
| `products` / `prices` | Cached Stripe products + pricing rows. Region-aware. |
| `price_regions` | Country → region tag mapping for regional pricing tiers. |
| `subscriptions` | Recurring billing. One row per Stripe subscription (current + historical). |
| `orders` / `order_items` | One-time purchases: tickets, digital goods, donations, lifetime memberships. |
| `entitlements` | Generic "this customer has access to X right now". Source of truth for role sync. |
| `processed_events` | Webhook idempotency ledger. |
| `audit_log` | Anything that changes access or money. |
