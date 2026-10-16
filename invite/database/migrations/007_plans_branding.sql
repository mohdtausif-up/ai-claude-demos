-- Plans, usage, branding (white-label) and custom domains.
ALTER TABLE workspaces
    ADD COLUMN plan VARCHAR(10) NULL AFTER name,                       -- NULL = DEFAULT_PLAN from the environment
    ADD COLUMN brand_name VARCHAR(100) NULL,
    ADD COLUMN brand_primary CHAR(7) NULL,
    ADD COLUMN brand_accent CHAR(7) NULL,
    ADD COLUMN brand_logo_path VARCHAR(255) NULL,
    ADD COLUMN hide_branding TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN custom_css TEXT NULL,
    ADD COLUMN custom_domain VARCHAR(190) NULL,
    ADD COLUMN domain_token CHAR(32) NULL,
    ADD COLUMN domain_verified_at DATETIME NULL,
    ADD UNIQUE KEY uq_workspaces_domain (custom_domain);

CREATE TABLE usage_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    metric VARCHAR(20) NOT NULL,
    period CHAR(7) NOT NULL,
    qty INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_usage (workspace_id, metric, period),
    CONSTRAINT fk_usage_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    plan VARCHAR(10) NOT NULL,
    status ENUM('active','past_due','cancelled') NOT NULL DEFAULT 'active',
    provider VARCHAR(20) NULL,
    provider_ref VARCHAR(120) NULL,
    current_period_end DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY ix_subs_ws (workspace_id, status),
    CONSTRAINT fk_subs_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;