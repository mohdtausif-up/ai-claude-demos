-- Sub-events can be open to every guest on the list, or restricted to hand-picked guests.
ALTER TABLE sub_events ADD COLUMN eligibility ENUM('all','selected') NOT NULL DEFAULT 'all' AFTER capacity;
