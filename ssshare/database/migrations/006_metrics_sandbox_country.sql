-- Coarse country (ISO 3166-1 alpha-2) from a trusted edge header; never derived from stored IPs.
ALTER TABLE view_events ADD COLUMN country CHAR(2) NULL AFTER browser;

-- Sandbox API keys (sss_test_...): shares expire within an hour and do not use quota.
ALTER TABLE api_keys ADD COLUMN environment ENUM('live', 'test') NOT NULL DEFAULT 'live' AFTER name;

-- Launch metrics (PRD §18): one row per upload attempt.
CREATE TABLE upload_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    via ENUM('web', 'api', 'mcp') NOT NULL,
    kind VARCHAR(16) NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    upload_id BIGINT UNSIGNED NULL,
    ok TINYINT(1) NOT NULL,
    error_code VARCHAR(48) NULL,
    processing_ms INT UNSIGNED NULL,
    client_ms INT UNSIGNED NULL,
    non_default_expiry TINYINT(1) NOT NULL DEFAULT 0,
    password TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    KEY idx_attempts_time (created_at),
    KEY idx_attempts_upload (upload_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE nps_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    score TINYINT UNSIGNED NOT NULL,
    comment VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
