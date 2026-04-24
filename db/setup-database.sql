-- One-time database + user provisioning. Run as a MySQL root user.
--
-- Required GRANTs:
--   * Full access to lg_membership (read + write)
--   * SELECT only on looth_dev for reading wp_users + wp_usermeta during transition
--
-- Replace 'CHANGE_ME' with a real password before running.

CREATE DATABASE IF NOT EXISTS lg_membership
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'lg_membership'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME';

GRANT ALL PRIVILEGES ON lg_membership.* TO 'lg_membership'@'127.0.0.1';

-- Cross-DB read for the bridge layer (WP plugin will use this same user
-- if it shares MySQL host; if WP plugin uses a different user, omit).
GRANT SELECT ON looth_dev.wp_users    TO 'lg_membership'@'127.0.0.1';
GRANT SELECT ON looth_dev.wp_usermeta TO 'lg_membership'@'127.0.0.1';

FLUSH PRIVILEGES;
