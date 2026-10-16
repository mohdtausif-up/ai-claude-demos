INSERT INTO feature_flags (`key`, enabled, description, updated_at) VALUES
('url_capture', 1, 'Capture web pages as images (headless browser behind an egress-filtering proxy)', UTC_TIMESTAMP());

-- Owner analytics: link-copy and QR-code activity.
ALTER TABLE view_events MODIFY outcome ENUM('ok', 'denied', 'password_failed', 'link_copied', 'qr_shown') NOT NULL;
