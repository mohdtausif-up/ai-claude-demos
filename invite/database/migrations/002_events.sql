-- Events, settings, sub-events, invitation content, media.

CREATE TABLE events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(40) NOT NULL,                 -- unguessable public link segment
    title VARCHAR(200) NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'other',
    host_names VARCHAR(200) NULL,
    description TEXT NULL,
    start_at DATETIME NOT NULL,                -- UTC
    end_at DATETIME NULL,                      -- UTC
    timezone VARCHAR(64) NOT NULL,             -- IANA zone of the event
    venue_name VARCHAR(200) NULL,
    venue_address VARCHAR(300) NULL,
    map_url VARCHAR(500) NULL,
    cover_media_id BIGINT UNSIGNED NULL,
    language VARCHAR(10) NOT NULL DEFAULT 'en',
    visibility ENUM('public','private') NOT NULL DEFAULT 'public',
    status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
    moderation_status ENUM('ok','suspended') NOT NULL DEFAULT 'ok',
    moderation_note VARCHAR(300) NULL,
    cloned_from_id BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_events_uuid (uuid),
    UNIQUE KEY uq_events_slug (slug),
    KEY ix_events_ws (workspace_id, deleted_at, start_at),
    KEY ix_events_status (status, moderation_status),
    CONSTRAINT fk_events_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id),
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_settings (
    event_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    rsvp_open TINYINT(1) NOT NULL DEFAULT 1,
    rsvp_deadline DATETIME NULL,               -- UTC
    allow_maybe TINYINT(1) NOT NULL DEFAULT 1,
    allow_waitlist TINYINT(1) NOT NULL DEFAULT 1,
    allow_response_edit TINYINT(1) NOT NULL DEFAULT 1,
    plus_one_limit TINYINT UNSIGNED NOT NULL DEFAULT 0,
    allow_children TINYINT(1) NOT NULL DEFAULT 0,
    max_children TINYINT UNSIGNED NOT NULL DEFAULT 4,
    capacity INT UNSIGNED NULL,                -- total headcount cap; NULL = unlimited
    collect_email TINYINT(1) NOT NULL DEFAULT 1,
    collect_phone TINYINT(1) NOT NULL DEFAULT 0,
    -- hidden | total | first_names | accepted_names ; guest lists are hidden by default
    guest_list_visibility ENUM('hidden','total','first_names','accepted_names') NOT NULL DEFAULT 'hidden',
    indexable TINYINT(1) NOT NULL DEFAULT 0,   -- public search-engine indexing is opt-in
    access_code_hash VARCHAR(255) NULL,        -- optional code gate for private events
    thank_you_message VARCHAR(500) NULL,
    CONSTRAINT fk_settings_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sub_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    start_at DATETIME NOT NULL,
    end_at DATETIME NULL,
    venue_name VARCHAR(200) NULL,
    venue_address VARCHAR(300) NULL,
    map_url VARCHAR(500) NULL,
    capacity INT UNSIGNED NULL,
    rsvp_open TINYINT(1) NOT NULL DEFAULT 1,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_sub_events_uuid (uuid),
    KEY ix_sub_events_event (event_id, position),
    CONSTRAINT fk_sub_events_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media_assets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    workspace_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    path VARCHAR(255) NOT NULL,                -- relative to public/uploads
    mime VARCHAR(60) NOT NULL,
    bytes INT UNSIGNED NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    alt VARCHAR(200) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_media_uuid (uuid),
    KEY ix_media_event (event_id),
    CONSTRAINT fk_media_ws FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The editable (draft) invitation. One per event.
CREATE TABLE invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    theme_json JSON NOT NULL,
    published_version_id BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_invitations_event (event_id),
    CONSTRAINT fk_invitations_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_invitations_template FOREIGN KEY (template_id) REFERENCES invitation_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invitation_sections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    position INT NOT NULL,
    content_json JSON NOT NULL,
    hidden_on ENUM('none','mobile','desktop') NOT NULL DEFAULT 'none',
    KEY ix_sections_inv (invitation_id, position),
    CONSTRAINT fk_sections_inv FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Published snapshots. The public page renders the published version, never the draft.
CREATE TABLE invitation_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    invitation_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    snapshot_json MEDIUMTEXT NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_versions (invitation_id, version_no),
    CONSTRAINT fk_versions_inv FOREIGN KEY (invitation_id) REFERENCES invitations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RSVP form.
CREATE TABLE rsvp_questions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    sub_event_id BIGINT UNSIGNED NULL,
    type ENUM('single_choice','multiple_choice','short_text','long_text','number','date','dropdown','rating','consent','meal','dietary','transport','accommodation','song_request') NOT NULL,
    label VARCHAR(250) NOT NULL,
    help_text VARCHAR(300) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    position INT NOT NULL DEFAULT 0,
    -- Conditional logic, e.g. {"status":["attending"]} or {"question":"<uuid>","equals":"Yes"}
    condition_json JSON NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_questions_uuid (uuid),
    KEY ix_questions_event (event_id, position),
    CONSTRAINT fk_questions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_questions_sub FOREIGN KEY (sub_event_id) REFERENCES sub_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rsvp_question_options (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(200) NOT NULL,
    position INT NOT NULL DEFAULT 0,
    KEY ix_options_q (question_id, position),
    CONSTRAINT fk_options_q FOREIGN KEY (question_id) REFERENCES rsvp_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
