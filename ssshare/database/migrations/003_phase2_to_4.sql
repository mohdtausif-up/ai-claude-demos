-- Phases 2-4: collections, comments, versions, webhooks, resumable uploads, workspaces,
-- plans and billing, 2FA, advanced access rules, video, encryption, OCR, administration.

-- ---------------------------------------------------------------- plans & billing
CREATE TABLE plans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(64) NOT NULL,
    price_cents INT UNSIGNED NOT NULL DEFAULT 0,
    storage_bytes BIGINT UNSIGNED NOT NULL,
    max_file_bytes BIGINT UNSIGNED NOT NULL,
    api_monthly_uploads INT UNSIGNED NULL,
    max_expiry_seconds INT UNSIGNED NULL,
    history_days INT UNSIGNED NULL,
    features JSON NOT NULL,
    sort_order SMALLINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO plans (code, name, price_cents, storage_bytes, max_file_bytes, api_monthly_uploads, max_expiry_seconds, history_days, features, sort_order, created_at) VALUES
('free', 'Free', 0, 1073741824, 26214400, 100, 31536000, 30, '["basic_annotation","folders","password","burn"]', 1, UTC_TIMESTAMP()),
('plus', 'Plus', 500, 10737418240, 104857600, 1000, 31536000, 365, '["basic_annotation","advanced_annotation","folders","password","burn","custom_expiry","versions","basic_analytics","custom_slugs","no_ads"]', 2, UTC_TIMESTAMP()),
('pro', 'Pro', 1500, 107374182400, 104857600, NULL, NULL, 730, '["basic_annotation","advanced_annotation","folders","password","burn","custom_expiry","versions","basic_analytics","full_analytics","custom_slugs","custom_domains","branding","webhooks","auto_redaction","public_galleries","advanced_access","no_ads"]', 3, UTC_TIMESTAMP()),
('business', 'Business', 4900, 536870912000, 104857600, NULL, NULL, NULL, '["basic_annotation","advanced_annotation","folders","password","burn","custom_expiry","versions","basic_analytics","full_analytics","custom_slugs","custom_domains","branding","webhooks","auto_redaction","public_galleries","advanced_access","workspaces","rbac","sso","audit_export","regional_storage","retention_policies","priority_processing","no_ads"]', 4, UTC_TIMESTAMP());

CREATE TABLE workspaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    name VARCHAR(80) NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    brand_name VARCHAR(80) NULL,
    brand_color CHAR(7) NULL,
    brand_logo_key VARCHAR(255) NULL,
    custom_domain VARCHAR(255) NULL UNIQUE,
    domain_token VARCHAR(64) NULL,
    domain_verified_at DATETIME NULL,
    storage_region VARCHAR(32) NOT NULL DEFAULT 'default',
    retention_max_seconds INT UNSIGNED NULL,
    retention_default VARCHAR(8) NULL,
    require_sso TINYINT(1) NOT NULL DEFAULT 0,
    sso_config TEXT NULL,
    allow_anonymous_comments TINYINT(1) NOT NULL DEFAULT 0,
    ai_enabled TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ws_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ws_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE workspace_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'admin', 'member', 'viewer') NOT NULL DEFAULT 'member',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_ws_member (workspace_id, user_id),
    CONSTRAINT fk_wsm_ws FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
    CONSTRAINT fk_wsm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE workspace_invitations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    workspace_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('admin', 'member', 'viewer') NOT NULL DEFAULT 'member',
    token_hash CHAR(64) NOT NULL UNIQUE,
    invited_by BIGINT UNSIGNED NOT NULL,
    accepted_at DATETIME NULL,
    revoked_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_inv_ws FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    workspace_id BIGINT UNSIGNED NULL,
    plan_id BIGINT UNSIGNED NOT NULL,
    status ENUM('trialing', 'active', 'past_due', 'canceled') NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_ref VARCHAR(128) NULL UNIQUE,
    current_period_end DATETIME NULL,
    cancel_at DATETIME NULL,
    refunded_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_sub_user (user_id),
    KEY idx_sub_ws (workspace_id),
    CONSTRAINT fk_sub_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE billing_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(128) NOT NULL,
    type VARCHAR(64) NOT NULL,
    payload JSON NOT NULL,
    processed_at DATETIME NULL,
    error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_billing_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- users
ALTER TABLE users
    ADD COLUMN plan_id BIGINT UNSIGNED NULL AFTER role,
    ADD COLUMN current_workspace_id BIGINT UNSIGNED NULL AFTER plan_id,
    ADD COLUMN totp_secret TEXT NULL,
    ADD COLUMN totp_enabled_at DATETIME NULL,
    ADD COLUMN recovery_codes JSON NULL,
    ADD COLUMN analytics_opt_out TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN ai_consent_at DATETIME NULL,
    ADD COLUMN email_notifications TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN sso_subject VARCHAR(255) NULL UNIQUE;

CREATE TABLE user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) NOT NULL UNIQUE,
    ip_hash CHAR(64) NULL,
    device VARCHAR(16) NULL,
    browser VARCHAR(24) NULL,
    method VARCHAR(16) NOT NULL DEFAULT 'password',
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    KEY idx_sessions_user (user_id, revoked_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- uploads & links
ALTER TABLE uploads
    MODIFY content_type ENUM('image', 'text', 'video', 'encrypted') NOT NULL,
    MODIFY visibility ENUM('unlisted', 'private', 'public') NOT NULL DEFAULT 'unlisted',
    ADD COLUMN comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN anonymous_comments TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN keep_old_versions TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN retain_comments TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN review_status ENUM('none', 'pending', 'approved', 'changes_requested') NOT NULL DEFAULT 'none',
    ADD COLUMN expire_on_review_complete TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN expire_when_recipients_viewed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN storage_region VARCHAR(32) NOT NULL DEFAULT 'default',
    ADD COLUMN ocr_text MEDIUMTEXT NULL,
    ADD COLUMN ocr_status ENUM('none', 'pending', 'done', 'failed') NOT NULL DEFAULT 'none',
    ADD COLUMN summary TEXT NULL,
    ADD COLUMN summary_generated_at DATETIME NULL,
    ADD COLUMN encryption_meta JSON NULL,
    ADD FULLTEXT KEY ft_uploads_search (title, ocr_text),
    ADD CONSTRAINT fk_uploads_ws FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE SET NULL;

ALTER TABLE upload_files
    MODIFY role ENUM('display', 'thumb', 'text', 'video', 'poster', 'captions', 'original', 'cipher') NOT NULL,
    ADD COLUMN duration_ms INT UNSIGNED NULL;

ALTER TABLE share_links
    ADD COLUMN audience ENUM('anyone', 'emails', 'domains', 'workspace') NOT NULL DEFAULT 'anyone' AFTER access_mode,
    ADD COLUMN audience_list JSON NULL AFTER audience,
    ADD COLUMN recipient_email VARCHAR(255) NULL AFTER audience_list,
    ADD COLUMN label VARCHAR(80) NULL AFTER recipient_email,
    ADD COLUMN max_sessions INT UNSIGNED NULL,
    ADD COLUMN download_until DATETIME NULL,
    ADD COLUMN captcha_required TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE link_sessions (
    share_link_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (share_link_id, session_hash),
    CONSTRAINT fk_ls_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE access_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    share_link_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_codes_link (share_link_id, email),
    CONSTRAINT fk_codes_link FOREIGN KEY (share_link_id) REFERENCES share_links (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE expiration_rules
    MODIFY rule_type ENUM('expires_at', 'burn_after_view', 'max_unique_views', 'max_total_views', 'max_downloads', 'inactivity', 'download_until') NOT NULL;

CREATE TABLE tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_tag (owner_user_id, name),
    CONSTRAINT fk_tags_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE upload_tags (
    upload_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (upload_id, tag_id),
    CONSTRAINT fk_ut_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE,
    CONSTRAINT fk_ut_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- collections
CREATE TABLE collections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    description VARCHAR(2000) NULL,
    layout ENUM('grid', 'masonry', 'slideshow', 'list') NOT NULL DEFAULT 'grid',
    cover_upload_id BIGINT UNSIGNED NULL,
    password_hash VARCHAR(255) NULL,
    visibility ENUM('unlisted', 'public', 'private') NOT NULL DEFAULT 'unlisted',
    custom_slug VARCHAR(64) NULL UNIQUE,
    allow_download_all TINYINT(1) NOT NULL DEFAULT 1,
    comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
    branded TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'expired', 'deleted') NOT NULL DEFAULT 'active',
    expires_at DATETIME NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_collections_owner (owner_user_id, status),
    CONSTRAINT fk_coll_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE collection_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collection_id BIGINT UNSIGNED NOT NULL,
    upload_id BIGINT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    hidden TINYINT(1) NOT NULL DEFAULT 0,
    password_hash VARCHAR(255) NULL,
    caption VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_coll_item (collection_id, upload_id),
    CONSTRAINT fk_ci_coll FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- comments & review
CREATE TABLE comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    upload_id BIGINT UNSIGNED NULL,
    upload_version_id BIGINT UNSIGNED NULL,
    collection_id BIGINT UNSIGNED NULL,
    parent_id BIGINT UNSIGNED NULL,
    author_user_id BIGINT UNSIGNED NULL,
    author_name VARCHAR(80) NOT NULL,
    author_hash CHAR(64) NOT NULL,
    body VARCHAR(5000) NOT NULL,
    pin_x DECIMAL(7, 5) NULL,
    pin_y DECIMAL(7, 5) NULL,
    status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    hidden_by_moderator TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_comments_upload (upload_id, created_at),
    KEY idx_comments_collection (collection_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE comment_mentions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comment_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_mention (comment_id, user_id),
    CONSTRAINT fk_mention_comment FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE,
    CONSTRAINT fk_mention_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE comment_reactions (
    comment_id BIGINT UNSIGNED NOT NULL,
    reactor_hash CHAR(64) NOT NULL,
    emoji VARCHAR(16) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (comment_id, reactor_hash, emoji),
    CONSTRAINT fk_reaction_comment FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE review_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    upload_id BIGINT UNSIGNED NOT NULL,
    upload_version_id BIGINT UNSIGNED NULL,
    reviewer_user_id BIGINT UNSIGNED NULL,
    reviewer_name VARCHAR(80) NOT NULL,
    decision ENUM('pending', 'approved', 'changes_requested') NOT NULL,
    note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_decisions_upload (upload_id, created_at),
    CONSTRAINT fk_decisions_upload FOREIGN KEY (upload_id) REFERENCES uploads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(48) NOT NULL,
    title VARCHAR(200) NOT NULL,
    body VARCHAR(1000) NULL,
    url VARCHAR(500) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_notifications_user (user_id, read_at, created_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- webhooks & integrations
CREATE TABLE webhooks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    workspace_id BIGINT UNSIGNED NULL,
    kind ENUM('generic', 'slack', 'teams') NOT NULL DEFAULT 'generic',
    url VARCHAR(1000) NOT NULL,
    secret TEXT NOT NULL,
    events JSON NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_success_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_webhooks_user (user_id),
    CONSTRAINT fk_webhooks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    webhook_id BIGINT UNSIGNED NOT NULL,
    event VARCHAR(48) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    status ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    response_code SMALLINT UNSIGNED NULL,
    response_body VARCHAR(2000) NULL,
    next_attempt_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_deliveries_due (status, next_attempt_at),
    KEY idx_deliveries_webhook (webhook_id, created_at),
    CONSTRAINT fk_deliveries_webhook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- resumable uploads
CREATE TABLE upload_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(24) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    total_bytes BIGINT UNSIGNED NOT NULL,
    received_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    filename VARCHAR(255) NULL,
    options JSON NOT NULL,
    status ENUM('open', 'complete', 'aborted') NOT NULL DEFAULT 'open',
    upload_id BIGINT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_sessions_expiry (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------- administration
CREATE TABLE settings (
    `key` VARCHAR(64) PRIMARY KEY,
    value JSON NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE feature_flags (
    `key` VARCHAR(64) PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO feature_flags (`key`, enabled, description, updated_at) VALUES
('anonymous_uploads', 1, 'Allow guests to share without an account', UTC_TIMESTAMP()),
('screen_recording', 1, 'Allow screen recordings', UTC_TIMESTAMP()),
('zero_knowledge', 1, 'Allow end-to-end encrypted shares', UTC_TIMESTAMP()),
('ocr', 1, 'Index image text for search and redaction', UTC_TIMESTAMP()),
('ai_features', 0, 'AI redaction and summaries (requires ANTHROPIC_API_KEY and per-workspace consent)', UTC_TIMESTAMP()),
('anonymous_comments', 1, 'Allow owners to enable anonymous comments', UTC_TIMESTAMP());

CREATE TABLE announcements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message VARCHAR(500) NOT NULL,
    level ENUM('info', 'warning', 'danger') NOT NULL DEFAULT 'info',
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE email_templates (
    `key` VARCHAR(64) PRIMARY KEY,
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO email_templates (`key`, subject, body, updated_at) VALUES
('share_link', '{{sender}} shared something with you on SSShare', 'Hi,\n\n{{sender}} shared a {{type}} with you:\n{{url}}\n\n{{summary}}\n\n{{message}}', UTC_TIMESTAMP()),
('workspace_invitation', 'Join {{workspace}} on SSShare', 'Hi,\n\n{{inviter}} invited you to join the {{workspace}} workspace as {{role}}.\n\nAccept the invitation: {{url}}\n\nThis invitation expires on {{expires}}.', UTC_TIMESTAMP()),
('access_code', 'Your SSShare access code', 'Your code to open the shared {{type}} is: {{code}}\n\nIt expires in 10 minutes. If you did not request it, ignore this email.', UTC_TIMESTAMP()),
('mention', '{{author}} mentioned you on SSShare', '{{author}} mentioned you in a comment:\n\n"{{body}}"\n\nOpen: {{url}}', UTC_TIMESTAMP()),
('comment', 'New comment on {{title}}', '{{author}} commented:\n\n"{{body}}"\n\nOpen: {{url}}', UTC_TIMESTAMP()),
('review_decision', '{{reviewer}} {{decision}} {{title}}', '{{reviewer}} marked "{{title}}" as {{decision}}.\n\n{{note}}\n\nOpen: {{url}}', UTC_TIMESTAMP()),
('data_export', 'Your SSShare data export is ready', 'Your export is ready. Download it within 24 hours:\n{{url}}', UTC_TIMESTAMP());

CREATE TABLE mail_outbox (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email VARCHAR(255) NOT NULL,
    template VARCHAR(64) NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    KEY idx_outbox_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE legal_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(16) NOT NULL UNIQUE,
    kind ENUM('takedown', 'subpoena', 'preservation', 'data_request', 'other') NOT NULL,
    requester VARCHAR(255) NOT NULL,
    reference VARCHAR(128) NULL,
    received_at DATETIME NOT NULL,
    upload_refs JSON NULL,
    status ENUM('open', 'in_review', 'complied', 'rejected', 'closed') NOT NULL DEFAULT 'open',
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE bandwidth_usage (
    day DATE NOT NULL,
    owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    requests INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, owner_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE data_exports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(24) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending', 'ready', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    object_key VARCHAR(255) NULL,
    bytes BIGINT UNSIGNED NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_exports_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE moderation_actions
    MODIFY action ENUM('takedown', 'dismiss', 'block_hash', 'suspend_user', 'hide_comment') NOT NULL;
