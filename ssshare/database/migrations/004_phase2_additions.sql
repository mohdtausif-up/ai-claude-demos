ALTER TABLE audit_logs ADD COLUMN workspace_id BIGINT UNSIGNED NULL AFTER actor_user_id, ADD KEY idx_audit_workspace (workspace_id, created_at);

ALTER TABLE collections ADD COLUMN expire_items TINYINT(1) NOT NULL DEFAULT 0 AFTER expires_at;

ALTER TABLE workspaces ADD COLUMN sso_domains VARCHAR(1000) NULL AFTER sso_config;
