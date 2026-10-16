-- Polls, automations, webhooks, MFA, OAuth identities, seating, albums, report sharing, saved filters, approvals.

CREATE TABLE polls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    type ENUM('date','venue','menu','choice','ranked','availability','yesno') NOT NULL DEFAULT 'choice',
    title VARCHAR(200) NOT NULL,
    description VARCHAR(500) NULL,
    anonymous TINYINT(1) NOT NULL DEFAULT 0,
    allow_multiple TINYINT(1) NOT NULL DEFAULT 0,
    max_choices TINYINT UNSIGNED NULL,
    allow_change TINYINT(1) NOT NULL DEFAULT 1,
    results_visibility ENUM('host','after_vote','public') NOT NULL DEFAULT 'after_vote',
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    status ENUM('open','closed') NOT NULL DEFAULT 'open',
    winner_option_id BIGINT UNSIGNED NULL,
    auto_select TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_polls_uuid (uuid),
    KEY ix_polls_event (event_id, status),
    CONSTRAINT fk_polls_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE poll_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(200) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    KEY ix_poll_options (poll_id, position),
    CONSTRAINT fk_poll_options_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per voter. Named polls store event_guest_id; anonymous polls store only an HMAC voter_hash (no link back to the guest).
CREATE TABLE poll_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NULL,
    voter_hash CHAR(64) NOT NULL,
    choices_json JSON NOT NULL,                -- {"<option_id>": 1 | rank | "yes"|"no"|"maybe"}
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_poll_voter (poll_id, voter_hash),
    CONSTRAINT fk_poll_resp_poll FOREIGN KEY (poll_id) REFERENCES polls(id) ON DELETE CASCADE,
    CONSTRAINT fk_poll_resp_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE automations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    rule_key VARCHAR(40) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    params_json JSON NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_automation (event_id, rule_key),
    CONSTRAINT fk_automations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Makes every automation fire at most once per (event, rule, reference).
CREATE TABLE automation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    rule_key VARCHAR(40) NOT NULL,
    ref VARCHAR(80) NOT NULL,
    detail VARCHAR(300) NULL,
    ran_at DATETIME NOT NULL,
    UNIQUE KEY uq_automation_run (event_id, rule_key, ref),
    CONSTRAINT fk_automation_runs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(500) NOT NULL,
    secret VARCHAR(80) NOT NULL,
    events_json JSON NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_webhooks_uuid (uuid),
    KEY ix_webhooks_ws (workspace_id, active),
    CONSTRAINT fk_webhooks_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    payload_json MEDIUMTEXT NOT NULL,
    status ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    response_code SMALLINT NULL,
    last_error VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    delivered_at DATETIME NULL,
    KEY ix_deliveries_endpoint (endpoint_id, id),
    CONSTRAINT fk_deliveries_endpoint FOREIGN KEY (endpoint_id) REFERENCES webhook_endpoints(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_mfa (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    secret_enc VARCHAR(255) NOT NULL,          -- AES-256-GCM under APP_KEY
    enabled_at DATETIME NULL,
    recovery_hashes JSON NULL,
    last_step BIGINT NULL,                     -- replay protection: last accepted 30s time-step
    CONSTRAINT fk_mfa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE oauth_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL,
    subject VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_oauth (provider, subject),
    CONSTRAINT fk_oauth_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seating_tables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    capacity SMALLINT UNSIGNED NOT NULL DEFAULT 8,
    position INT NOT NULL DEFAULT 0,
    notes VARCHAR(200) NULL,
    UNIQUE KEY uq_tables_uuid (uuid),
    KEY ix_tables_event (event_id, position),
    CONSTRAINT fk_tables_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seat_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    table_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_seat_guest (event_guest_id),
    KEY ix_seat_table (table_id),
    CONSTRAINT fk_seat_table FOREIGN KEY (table_id) REFERENCES seating_tables(id) ON DELETE CASCADE,
    CONSTRAINT fk_seat_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guest-contributed photos for the event album (moderated by the host).
CREATE TABLE guest_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    caption VARCHAR(200) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_guest_media_uuid (uuid),
    KEY ix_guest_media_event (event_id, status),
    CONSTRAINT fk_gm_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_media FOREIGN KEY (media_id) REFERENCES media_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_gm_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE event_settings
    ADD COLUMN album_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN album_moderated TINYINT(1) NOT NULL DEFAULT 1;

-- Read-only report links (token stored only as a hash) and scheduled email reports.
CREATE TABLE report_shares (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(100) NULL,
    created_by BIGINT UNSIGNED NULL,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_report_share (token_hash),
    CONSTRAINT fk_report_shares_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    frequency ENUM('daily','weekly') NOT NULL DEFAULT 'weekly',
    last_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_scheduled_report (event_id, user_id),
    CONSTRAINT fk_sched_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_sched_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE saved_filters (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    query_string VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_saved_filter (event_id, user_id, name),
    CONSTRAINT fk_filters_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_filters_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Client approval workflow for agencies: comments + approval state.
CREATE TABLE event_comments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY ix_comments_event (event_id, id),
    CONSTRAINT fk_comments_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE events
    ADD COLUMN approval_status ENUM('none','requested','approved','changes_requested') NOT NULL DEFAULT 'none' AFTER moderation_note;
