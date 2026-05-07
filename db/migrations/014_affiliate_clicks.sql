CREATE TABLE IF NOT EXISTS affiliate_clicks (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    affiliate_id INT UNSIGNED NOT NULL,
    clicked_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_affiliate (affiliate_id),
    KEY idx_clicked_at (clicked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
