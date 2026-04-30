# lg-stripe-billing — Claude instructions

At the start of every session, read `PICKUP.md` before doing anything else.
It contains current state, outstanding TODOs in priority order, server access,
quick test commands, and DB snapshot. Do not ask what to work on — the next
item is always labeled **NEXT** in the TODO list.

## Stack
- Slim 4 PHP API, PHP-DI autowiring, PDO/MySQL
- Stripe PHP SDK (embedded Checkout, webhooks)
- Deployed on EC2 at `/home/ccdev/lg-stripe-billing/` (dev)
- Companion WP plugin: `lg-patreon-stripe-poller` at `/var/www/dev/wp-content/plugins/`

## Dev deploy
```bash
ssh -i "C:/Users/ianda/git-repos/ssh keys/ccdev_key" ccdev@54.157.13.77
cd /home/ccdev/lg-stripe-billing && git pull && composer install
```

## Conventions
- No comments unless the WHY is non-obvious
- New DB columns → `db/migrations/NNN_description.sql`
- Keep `PICKUP.md` updated at end of each session
