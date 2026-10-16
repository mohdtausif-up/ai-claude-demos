<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Core\Db;
use App\Core\Validator;
use App\Core\ValidationException;
use App\Repositories\EventRepository;
use App\Repositories\GuestRepository;
use App\Services\AuditLogger;
use App\Services\GuestService;
use App\Services\ImportService;

/** Processes an uploaded guest CSV in the background. */
final class ImportJob implements JobHandler
{
    public function __construct(
        private GuestService $guestService,
        private GuestRepository $guests,
        private ImportService $imports,
        private EventRepository $events,
        private AuditLogger $audit
    ) {
    }

    public function handle(array $p): void
    {
        $import = Db::one('SELECT * FROM imports WHERE id = ?', [$p['import_id']]);
        if (!$import || !in_array($import['status'], ['queued', 'processing'], true)) {
            return;
        }
        $event = $this->events->find((int) $import['event_id']);
        if (!$event) {
            return;
        }
        Db::update('imports', ['status' => 'processing'], ['id' => $import['id']]);
        $file = BASE_PATH . '/storage/imports/' . $import['file_path'];
        $rows = $this->imports->readRows($file);
        $header = array_shift($rows);
        $map = json_decode((string) $import['mapping_json'], true) ?: [];
        $cell = static fn (array $r, string $f) => isset($map[$f], $r[$map[$f]]) ? trim($r[$map[$f]]) : '';

        $created = $updated = $skipped = 0;
        $errors = [];
        foreach ($rows as $i => $r) {
            $line = $i + 2; // 1-based, header is line 1
            try {
                $first = $cell($r, 'first_name');
                $last = $cell($r, 'last_name');
                if ($first === '' && ($full = $cell($r, 'full_name')) !== '') {
                    $parts = preg_split('/\s+/', $full, 2);
                    [$first, $last] = [$parts[0], $parts[1] ?? $last];
                }
                $plus = $cell($r, 'plus_one_limit');
                $data = Validator::validate([
                    'first_name' => $first, 'last_name' => $last, 'email' => $cell($r, 'email'), 'phone' => $cell($r, 'phone'),
                    'language' => $event['language'], 'group_name' => $cell($r, 'group_name'), 'tags' => $cell($r, 'tags'),
                    'is_vip' => in_array(strtolower($cell($r, 'is_vip')), ['1', 'yes', 'y', 'true', 'vip'], true),
                    'host_notes' => $cell($r, 'host_notes'),
                    'plus_one_limit' => is_numeric($plus) ? min(10, max(0, (int) $plus)) : null,
                ], (new \App\Requests\GuestRequest(new \App\Core\Request('POST', '/', [], [], [], [], [])))->rules());
                $existing = !empty($data['email']) ? $this->guests->findByEmailInEvent((int) $event['id'], $data['email']) : null;
                if ($existing) {
                    if ($import['on_duplicate'] === 'update') {
                        $this->guestService->update($event, $existing, $data + ['do_not_contact' => $existing['do_not_contact']]);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }
                $this->guestService->add($event, $data, 'import');
                $created++;
            } catch (ValidationException $e) {
                $skipped++;
                if (count($errors) < 50) {
                    $errors[] = ['row' => $line, 'message' => implode(' ', array_merge(...array_values($e->errors)))];
                }
            }
            if (($i + 1) % 200 === 0) {
                Db::update('imports', ['created_rows' => $created, 'updated_rows' => $updated, 'skipped_rows' => $skipped], ['id' => $import['id']]);
            }
        }
        Db::update('imports', [
            'status' => 'done', 'total_rows' => count($rows), 'created_rows' => $created, 'updated_rows' => $updated,
            'skipped_rows' => $skipped, 'errors_json' => json_out($errors), 'finished_at' => utcnow(),
        ], ['id' => $import['id']]);
        @unlink($file);
        $this->audit->log('guests.imported', [
            'workspace_id' => (int) $event['workspace_id'], 'event_id' => (int) $event['id'], 'actor_user_id' => (int) $import['created_by'],
            'entity_type' => 'import', 'entity_uuid' => $import['uuid'], 'meta' => ['created' => $created, 'updated' => $updated, 'skipped' => $skipped],
        ]);
    }

    public function failed(array $p, string $error): void
    {
        Db::exec("UPDATE imports SET status = 'failed', errors_json = ?, finished_at = ? WHERE id = ?", [json_out([['row' => 0, 'message' => str_limit($error, 200)]]), utcnow(), $p['import_id']]);
    }
}
