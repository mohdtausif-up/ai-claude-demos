<?php

// Dev tool: print the slug of the newest published classic-wedding event (benchmark fixture).
require __DIR__ . '/../../bootstrap.php';
echo App\Core\Db::value("SELECT slug FROM events WHERE status = 'published' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
