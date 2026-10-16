-- SSShare MVP schema. All times are UTC. External references use uuid/public_id, never id.

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    storage_quota_bytes BIGINT UNSIGNED NOT NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_folder_owner_name (owner_user_id, name),
    CONSTRAINT fk_folders_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE uploads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,
    owner_user_id BIGINT UNSIGNED NULL,
    workspace_id BIGINT UNSIGNED NULL,
    folder_id BIGINT UNSIGNED NULL,
    content_type ENUM('image', 'text') NOT NULL,
    title VARCHAR(160) NULL,
    description VARCHAR(1000) NULL,
    status ENUM('active', 'expired', 'revoked', 'deleted', 'blocked') NOT NULL DEFAULT 'active',
    visibility ENUM('unlisted', 'private') NOT NULL DEFAULT 'unlisted',
    storage_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    current_version_id BIGINT UNSIGNED NULL,
    manage_token_hash CHAR(64) NOT NULL,
    created_via ENUM('web', 'api') NOT NULL DEFAULT 'web',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    soft_deleted_at DATETIME NULL,
    purge_at DATETIME NULL,
    deleted_at DATETIME NULL,
    delete_reason VARCHAR(32) NULL,
    KEY idx_uploads_owner_status (owner_user_id, status, created_at),
    KEY idx_uploads_purge (purge_at),
    CONSTRAINT fk_uploads_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_uploads_folder FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE upload_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    uploader_user_id BIGINT UNSIGNED NULL,
    change_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_upload_version (upload_id, version_number),
    CONSTRAINT fk_versions_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE upload_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT UNSIGNED NOT NULL,
    upload_version_id BIGINT UNSIGNED NOT NULL,
    role ENUM('display', 'thumb', 'text') NOT NULL,
    object_key VARCHAR(255) NOT NULL,
    mime VARCHAR(64) NOT NULL,
    ext VARCHAR(8) NOT NULL,
    bytes BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    sha256 CHAR(64) NOT NULL,
    source_sha256 CHAR(64) NULL,
    text_language VARCHAR(24) NULL,
    text_format ENUM('plain', 'markdown', 'code') NULL,
    text_lines INT UNSIGNED NULL,
    original_name VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    KEY idx_files_upload (upload_id, role),
    KEY idx_files_source_hash (source_sha256),
    CONSTRAINT fk_files_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE,
    CONSTRAINT fk_files_version FOREIGN KEY (upload_version_id) REFERENCES upload_versions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE share_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT UNSIGNED NOT NULL,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    custom_slug VARCHAR(64) NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    access_mode ENUM('open', 'password') NOT NULL DEFAULT 'open',
    burn_after_view TINYINT(1) NOT NULL DEFAULT 0,
    view_limit INT UNSIGNED NULL,
    view_limit_type ENUM('unique', 'total') NOT NULL DEFAULT 'unique',
    download_limit INT UNSIGNED NULL,
    disable_download TINYINT(1) NOT NULL DEFAULT 0,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    unique_view_count INT UNSIGNED NOT NULL DEFAULT 0,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    inactive_after_seconds INT UNSIGNED NULL,
    status ENUM('active', 'paused', 'expired', 'consumed', 'revoked') NOT NULL DEFAULT 'active',
    end_reason VARCHAR(32) NULL,
    starts_at DATETIME NULL,
    expires_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    ended_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_links_status_expiry (status, expires_at),
    KEY idx_links_upload (upload_id),
    CONSTRAINT fk_links_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Source of truth for compound expiration. share_links holds denormalised copies for indexing.
CREATE TABLE expiration_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    share_link_id BIGINT UNSIGNED NOT NULL,
    rule_type ENUM('expires_at', 'burn_after_view', 'max_unique_views', 'max_total_views', 'max_downloads', 'inactivity') NOT NULL,
    operator ENUM('or') NOT NULL DEFAULT 'or',
    threshold_integer INT UNSIGNED NULL,
    threshold_datetime DATETIME NULL,
    priority SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_rule_per_link (share_link_id, rule_type),
    CONSTRAINT fk_rules_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE view_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    share_link_id BIGINT UNSIGNED NOT NULL,
    outcome ENUM('ok', 'denied', 'password_failed') NOT NULL,
    viewer_hash CHAR(64) NOT NULL,
    is_unique TINYINT(1) NOT NULL DEFAULT 0,
    referrer_domain VARCHAR(255) NULL,
    device VARCHAR(16) NULL,
    browser VARCHAR(24) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_views_link_viewer (share_link_id, viewer_hash, created_at),
    CONSTRAINT fk_views_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE download_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    share_link_id BIGINT UNSIGNED NOT NULL,
    viewer_hash CHAR(64) NOT NULL,
    device VARCHAR(16) NULL,
    browser VARCHAR(24) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_downloads_link (share_link_id, created_at),
    CONSTRAINT fk_downloads_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE password_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    share_link_id BIGINT UNSIGNED NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_attempts_link_ip (share_link_id, ip_hash, created_at),
    CONSTRAINT fk_attempts_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- No foreign keys: audit records must outlive the objects they describe.
CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_type ENUM('guest', 'user', 'admin', 'api', 'system') NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    target_type VARCHAR(32) NULL,
    target_id VARCHAR(64) NULL,
    ip_hash CHAR(64) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_actor (actor_user_id, created_at),
    KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE api_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    prefix VARCHAR(24) NOT NULL,
    key_hash CHAR(64) NOT NULL UNIQUE,
    scopes JSON NOT NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_keys_user (user_id),
    CONSTRAINT fk_keys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE api_usage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    period CHAR(7) NOT NULL,
    uploads INT UNSIGNED NOT NULL DEFAULT 0,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_usage_period (user_id, period),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    api_key_id BIGINT UNSIGNED NOT NULL,
    idem_key VARCHAR(80) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_idem (api_key_id, idem_key),
    CONSTRAINT fk_idem_key FOREIGN KEY (api_key_id) REFERENCES api_keys (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE abuse_reports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    upload_id BIGINT UNSIGNED NULL,
    share_link_id BIGINT UNSIGNED NULL,
    reason ENUM('illegal', 'csam', 'copyright', 'harassment', 'personal_data', 'malware', 'spam', 'other') NOT NULL,
    details VARCHAR(2000) NULL,
    reporter_email VARCHAR(255) NULL,
    reporter_ip_hash CHAR(64) NOT NULL,
    status ENUM('open', 'actioned', 'dismissed') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_reports_status (status, created_at),
    CONSTRAINT fk_reports_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE moderation_actions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    abuse_report_id BIGINT UNSIGNED NULL,
    upload_id BIGINT UNSIGNED NULL,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    action ENUM('takedown', 'dismiss', 'block_hash', 'suspend_user') NOT NULL,
    note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Hash-based blocklist: SHA-256 of the original uploaded bytes.
CREATE TABLE blocked_hashes (
    sha256 CHAR(64) PRIMARY KEY,
    reason VARCHAR(255) NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One job per upload keeps cleanup idempotent.
CREATE TABLE deletion_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT UNSIGNED NOT NULL UNIQUE,
    reason VARCHAR(32) NOT NULL,
    status ENUM('pending', 'running', 'done', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(2000) NULL,
    run_after DATETIME NOT NULL,
    locked_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_jobs_due (status, run_after),
    CONSTRAINT fk_jobs_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rate_limits (
    bucket VARCHAR(190) PRIMARY KEY,
    hits INT UNSIGNED NOT NULL,
    window_start DATETIME NOT NULL,
    KEY idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE scheduler_heartbeats (
    task VARCHAR(64) PRIMARY KEY,
    last_run_at DATETIME NOT NULL,
    last_result VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
