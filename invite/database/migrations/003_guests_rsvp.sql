-- Guests, RSVP, messaging, queue, check-in.

CREATE TABLE guest_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_groups_uuid (uuid),
    UNIQUE KEY uq_groups_name (event_id, name),
    CONSTRAINT fk_groups_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A person in a workspace's address book.
CREATE TABLE guests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(32) NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    anonymized_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_guests_uuid (uuid),
    KEY ix_guests_ws_email (workspace_id, email),
    CONSTRAINT fk_guests_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE guest_tags (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    tag VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_guest_tags (event_guest_id, tag),
    KEY ix_guest_tags_tag (tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A guest's invitation to one event. Personal link / QR tokens are derived (HMAC) from uuid + token_version,
-- so nothing reversible is stored and bumping token_version revokes every old link.
CREATE TABLE event_guests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    guest_id BIGINT UNSIGNED NOT NULL,
    group_id BIGINT UNSIGNED NULL,
    source ENUM('manual','import','public_link') NOT NULL DEFAULT 'manual',
    token_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    plus_one_limit TINYINT UNSIGNED NULL,      -- per-guest override of the event limit
    child_limit TINYINT UNSIGNED NULL,
    is_vip TINYINT(1) NOT NULL DEFAULT 0,
    host_notes VARCHAR(500) NULL,              -- visible to hosts only
    do_not_contact TINYINT(1) NOT NULL DEFAULT 0,
    invite_sent_at DATETIME NULL,
    delivery_status ENUM('none','queued','sent','delivered','failed','bounced') NOT NULL DEFAULT 'none',
    delivery_error VARCHAR(300) NULL,
    first_opened_at DATETIME NULL,
    last_opened_at DATETIME NULL,
    open_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_channel VARCHAR(20) NULL,
    anonymized_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_event_guests_uuid (uuid),
    UNIQUE KEY uq_event_guests_pair (event_id, guest_id),
    KEY ix_event_guests_event (event_id, delivery_status),
    CONSTRAINT fk_eg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_eg_guest FOREIGN KEY (guest_id) REFERENCES guests(id),
    CONSTRAINT fk_eg_group FOREIGN KEY (group_id) REFERENCES guest_groups(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE guest_tags ADD CONSTRAINT fk_guest_tags_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE;

-- Per-sub-event eligibility.
CREATE TABLE sub_event_guests (
    sub_event_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (sub_event_id, event_guest_id),
    CONSTRAINT fk_seg_sub FOREIGN KEY (sub_event_id) REFERENCES sub_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_seg_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Current RSVP. sub_event_id NULL = the main event. Headcount = 1 (the guest) + plus_ones + children.
CREATE TABLE rsvp_responses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    sub_event_id BIGINT UNSIGNED NULL,
    sub_event_scope BIGINT UNSIGNED AS (IFNULL(sub_event_id, 0)) VIRTUAL,
    status ENUM('attending','declined','maybe','waitlist') NOT NULL,
    plus_ones TINYINT UNSIGNED NOT NULL DEFAULT 0,
    children TINYINT UNSIGNED NOT NULL DEFAULT 0,
    comment VARCHAR(500) NULL,
    show_name TINYINT(1) NOT NULL DEFAULT 0,   -- guest consented to their name appearing on the public guest list
    responded_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_responses_uuid (uuid),
    UNIQUE KEY uq_responses_guest (event_guest_id, sub_event_scope),
    KEY ix_responses_event (event_id, sub_event_scope, status),
    CONSTRAINT fk_resp_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_resp_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE,
    CONSTRAINT fk_resp_sub FOREIGN KEY (sub_event_id) REFERENCES sub_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named plus-ones and children (names are optional; rows exist for every counted companion).
CREATE TABLE rsvp_companions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('plus_one','child') NOT NULL,
    name VARCHAR(150) NULL,
    age TINYINT UNSIGNED NULL,
    KEY ix_companions_resp (response_id),
    CONSTRAINT fk_companions_resp FOREIGN KEY (response_id) REFERENCES rsvp_responses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rsvp_answers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    answer TEXT NOT NULL,                      -- plain text, or a JSON array for multiple_choice
    UNIQUE KEY uq_answers (response_id, question_id),
    KEY ix_answers_q (question_id),
    CONSTRAINT fk_answers_resp FOREIGN KEY (response_id) REFERENCES rsvp_responses(id) ON DELETE CASCADE,
    CONSTRAINT fk_answers_q FOREIGN KEY (question_id) REFERENCES rsvp_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only audit of every RSVP change.
CREATE TABLE rsvp_response_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NOT NULL,
    old_plus_ones TINYINT UNSIGNED NULL,
    new_plus_ones TINYINT UNSIGNED NOT NULL,
    old_children TINYINT UNSIGNED NULL,
    new_children TINYINT UNSIGNED NOT NULL,
    old_meal VARCHAR(200) NULL,
    new_meal VARCHAR(200) NULL,
    changes_json JSON NULL,
    actor_type ENUM('guest','host','system') NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    KEY ix_history_resp (response_id, created_at),
    KEY ix_history_eg (event_guest_id, created_at),
    CONSTRAINT fk_history_resp FOREIGN KEY (response_id) REFERENCES rsvp_responses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NULL,
    kind ENUM('invitation','reminder','update','thank_you') NOT NULL,
    channel ENUM('email','whatsapp','sms') NOT NULL DEFAULT 'email',
    subject VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    segment VARCHAR(30) NOT NULL,
    scheduled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_messages_uuid (uuid),
    KEY ix_messages_event (event_id, created_at),
    CONSTRAINT fk_messages_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_recipients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued','sent','delivered','failed','bounced','skipped') NOT NULL DEFAULT 'queued',
    error VARCHAR(300) NULL,
    sent_at DATETIME NULL,
    UNIQUE KEY uq_recipients (message_id, event_guest_id),
    KEY ix_recipients_eg (event_guest_id),
    CONSTRAINT fk_recipients_msg FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_recipients_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(40) NOT NULL,
    payload JSON NOT NULL,
    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL,
    reserved_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    KEY ix_jobs_pick (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NULL,
    event_guest_id BIGINT UNSIGNED NULL,
    message_id BIGINT UNSIGNED NULL,
    channel VARCHAR(20) NOT NULL,
    recipient VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL,
    provider_message_id VARCHAR(190) NULL,
    error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    KEY ix_nlogs_event (event_id, created_at),
    KEY ix_nlogs_provider (provider_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mapping_json JSON NULL,
    preview_json JSON NULL,
    status ENUM('uploaded','queued','processing','done','failed') NOT NULL DEFAULT 'uploaded',
    on_duplicate ENUM('skip','update') NOT NULL DEFAULT 'skip',
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_rows INT UNSIGNED NOT NULL DEFAULT 0,
    updated_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    errors_json JSON NULL,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    UNIQUE KEY uq_imports_uuid (uuid),
    CONSTRAINT fk_imports_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE qr_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    token_version SMALLINT UNSIGNED NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_qr_hash (token_hash),
    KEY ix_qr_eg (event_guest_id),
    CONSTRAINT fk_qr_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checkins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    sub_event_id BIGINT UNSIGNED NULL,
    event_guest_id BIGINT UNSIGNED NOT NULL,
    headcount SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    method ENUM('qr','manual') NOT NULL,
    entry_point VARCHAR(60) NULL,
    staff_user_id BIGINT UNSIGNED NULL,
    checked_in_at DATETIME NOT NULL,
    reversed_at DATETIME NULL,
    reversed_by BIGINT UNSIGNED NULL,
    KEY ix_checkins_event (event_id, reversed_at, checked_in_at),
    KEY ix_checkins_eg (event_guest_id),
    CONSTRAINT fk_checkins_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_checkins_eg FOREIGN KEY (event_guest_id) REFERENCES event_guests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
