-- lg_membership — canonical schema.
-- Source of truth for the standalone LGSB billing service.
--
-- Principles:
--   1. Identity separated from entitlement. Customers are who; entitlements are what.
--   2. One-time purchases (tickets, lifetime memberships, donations) are first-class via orders.
--   3. Money carries currency + integer cents. No floats.
--   4. UUIDs as external refs; autoinc PKs for joins.
--   5. Soft deletes where applicable; no ENUMs so new values don't need ALTER.
--   6. WP coupling lives in its own bridge table so it can be dropped at cutover.

-- ============================================================
-- Identity
-- ============================================================

CREATE TABLE customers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36)        NOT NULL,
    stripe_customer_id  VARCHAR(64)     NULL,
    email               VARCHAR(255)    NOT NULL,
    name                VARCHAR(255)    NULL,
    country             CHAR(2)         NULL,
    locale              VARCHAR(10)     NULL,
    metadata            JSON            NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL,
    UNIQUE KEY uk_uuid     (uuid),
    UNIQUE KEY uk_email    (email),
    UNIQUE KEY uk_customer (stripe_customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transitional bridge to WordPress users. Dropped after WP retirement.
CREATE TABLE wp_user_bridge (
    customer_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    wp_user_id  BIGINT UNSIGNED NOT NULL,
    synced_at   DATETIME        NULL,
    UNIQUE KEY uk_wp_user (wp_user_id),
    CONSTRAINT fk_bridge_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Products & prices (cache of Stripe objects, source of truth = Stripe)
-- ============================================================

CREATE TABLE products (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stripe_product_id   VARCHAR(64)     NOT NULL,
    kind                VARCHAR(32)     NOT NULL,   -- 'membership' | 'event_ticket' | 'digital' | 'donation'
    ref                 VARCHAR(64)     NULL,       -- tier slug / event slug / product slug
    name                VARCHAR(255)    NOT NULL,
    metadata            JSON            NULL,
    active              TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                            ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_stripe_product (stripe_product_id),
    KEY        idx_kind_ref      (kind, ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prices (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id           BIGINT UNSIGNED NOT NULL,
    stripe_price_id      VARCHAR(64)     NOT NULL,
    type                 VARCHAR(16)     NOT NULL,  -- 'recurring' | 'one_time'
    `interval`           VARCHAR(16)     NULL,      -- 'month' | 'year' | NULL
    unit_amount_cents    INT UNSIGNED    NOT NULL,
    currency             CHAR(3)         NOT NULL,  -- ISO-4217
    region_tag           VARCHAR(16)     NULL,      -- NULL = default fallback; else matches price_regions.region_tag
    priority             INT             NOT NULL DEFAULT 100,  -- lower wins in price resolution
    grants_duration_days INT UNSIGNED    NULL,      -- NULL = indefinite / lifetime; one-time only
    active               TINYINT(1)      NOT NULL DEFAULT 1,
    metadata             JSON            NULL,
    UNIQUE KEY uk_stripe_price   (stripe_price_id),
    KEY        idx_product       (product_id),
    KEY        idx_region_tag    (region_tag),
    CONSTRAINT fk_price_product FOREIGN KEY (product_id)
        REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Country → region tag mapping. A country can belong to multiple tags (e.g. DEV + EU).
CREATE TABLE price_regions (
    country_code CHAR(2)     NOT NULL,   -- ISO-3166 alpha-2
    region_tag   VARCHAR(16) NOT NULL,
    PRIMARY KEY (country_code, region_tag),
    KEY idx_tag (region_tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Recurring: subscriptions
-- ============================================================

CREATE TABLE subscriptions (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id             BIGINT UNSIGNED NOT NULL,
    stripe_subscription_id  VARCHAR(64)     NOT NULL,
    stripe_price_id         VARCHAR(64)     NOT NULL,
    status                  VARCHAR(32)     NOT NULL,   -- active | trialing | past_due | canceled | incomplete | ...
    cancel_at_period_end    TINYINT(1)      NOT NULL DEFAULT 0,
    current_period_start    DATETIME        NULL,
    current_period_end      DATETIME        NULL,
    canceled_at             DATETIME        NULL,
    metadata                JSON            NULL,
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_stripe_sub (stripe_subscription_id),
    KEY        idx_customer  (customer_id),
    KEY        idx_status    (status),
    CONSTRAINT fk_sub_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- One-time: orders (tickets, digital goods, donations, lifetime memberships)
-- ============================================================

CREATE TABLE orders (
    id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                       CHAR(36)        NOT NULL,
    customer_id                BIGINT UNSIGNED NOT NULL,
    stripe_checkout_session_id VARCHAR(64)     NULL,
    stripe_payment_intent_id   VARCHAR(64)     NULL,
    status                     VARCHAR(32)     NOT NULL,   -- pending | paid | refunded | partially_refunded | failed | canceled
    total_cents                INT UNSIGNED    NOT NULL,
    currency                   CHAR(3)         NOT NULL,
    metadata                   JSON            NULL,
    created_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_uuid    (uuid),
    UNIQUE KEY uk_session (stripe_checkout_session_id),
    UNIQUE KEY uk_pi      (stripe_payment_intent_id),
    KEY        idx_customer_status (customer_id, status),
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id          BIGINT UNSIGNED NOT NULL,
    price_id          BIGINT UNSIGNED NULL,           -- NULL for ad-hoc priced items (e.g. donations)
    product_kind      VARCHAR(32)     NOT NULL,       -- denormalized from products.kind for fast filtering
    product_ref       VARCHAR(64)     NULL,
    quantity          INT UNSIGNED    NOT NULL DEFAULT 1,
    unit_amount_cents INT UNSIGNED    NOT NULL,
    currency          CHAR(3)         NOT NULL,
    metadata          JSON            NULL,
    KEY        idx_order    (order_id),
    KEY        idx_kind_ref (product_kind, product_ref),
    CONSTRAINT fk_item_order FOREIGN KEY (order_id)
        REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_item_price FOREIGN KEY (price_id)
        REFERENCES prices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Entitlements — the generic "what can this customer do right now".
-- Source of truth for role sync back to WP (during transition).
-- ============================================================

CREATE TABLE entitlements (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36)        NOT NULL,
    customer_id     BIGINT UNSIGNED NOT NULL,
    kind            VARCHAR(32)     NOT NULL,   -- 'membership_tier' | 'event_access' | 'digital_access' | ...
    ref             VARCHAR(64)     NOT NULL,   -- 'looth2' | event slug | product slug
    source_type     VARCHAR(32)     NOT NULL,   -- 'subscription' | 'order' | 'manual' | 'comp'
    source_id       BIGINT UNSIGNED NULL,       -- polymorphic: no FK; points at subscriptions.id or orders.id
    starts_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME        NULL,       -- NULL = indefinite
    revoked_at      DATETIME        NULL,
    metadata        JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_uuid    (uuid),
    KEY        idx_active (customer_id, kind, ref, revoked_at, expires_at),
    CONSTRAINT fk_ent_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Audit log — anything that changes access or money
-- ============================================================

CREATE TABLE audit_log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type   VARCHAR(32)     NOT NULL,          -- 'system' | 'admin' | 'webhook' | 'cron'
    actor_ref    VARCHAR(64)     NULL,
    subject_type VARCHAR(32)     NOT NULL,          -- 'customer' | 'subscription' | 'order' | 'entitlement'
    subject_id   BIGINT UNSIGNED NOT NULL,
    action       VARCHAR(64)     NOT NULL,          -- 'created' | 'role_changed' | 'refunded' | ...
    details      JSON            NULL,
    created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_subject (subject_type, subject_id, created_at),
    KEY idx_action  (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
