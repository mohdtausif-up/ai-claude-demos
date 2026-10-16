<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\ForbiddenException;
use App\Core\ValidationException;
use App\Repositories\EventRepository;

final class EventService
{
    public const CATEGORIES = [
        'wedding' => 'Wedding', 'engagement' => 'Engagement', 'birthday' => 'Birthday', 'baby_shower' => 'Baby shower',
        'anniversary' => 'Anniversary', 'graduation' => 'Graduation', 'festival' => 'Festival', 'cultural' => 'Religious or cultural celebration',
        'corporate' => 'Corporate event', 'conference' => 'Conference', 'reunion' => 'Reunion', 'community' => 'Community event',
        'memorial' => 'Memorial', 'dinner' => 'Dinner', 'virtual' => 'Virtual event', 'save_the_date' => 'Save the date', 'other' => 'Other',
    ];

    private const EVENT_FIELDS = ['title', 'category', 'host_names', 'description', 'venue_name', 'venue_address', 'map_url', 'language', 'visibility', 'timezone'];
    private const SETTING_BOOLS = ['rsvp_open', 'allow_maybe', 'allow_waitlist', 'allow_response_edit', 'allow_children', 'collect_email', 'collect_phone', 'indexable', 'album_enabled', 'album_moderated'];

    public function __construct(
        private EventRepository $events,
        private InvitationService $invitations,
        private AuditLogger $audit,
        private AutomationService $automation,
        private ApprovalService $approval
    ) {
    }

    /**
     * @param array<string,mixed> $data validated EventRequest data
     * @return array<string,mixed> the new event
     */
    public function create(array $user, int $workspaceId, array $data, ?string $templateSlug): array
    {
        $this->assertTimes($data);
        $tz = $data['timezone'];
        $eventId = Db::transaction(function () use ($user, $workspaceId, $data, $tz, $templateSlug) {
            $now = utcnow();
            $id = Db::insert('events', [
                'uuid' => uuid4(),
                'workspace_id' => $workspaceId,
                'created_by' => (int) $user['id'],
                'slug' => $this->events->newSlug(),
                'title' => $data['title'],
                'category' => $data['category'],
                'host_names' => $data['host_names'] ?? null,
                'description' => $data['description'] ?? null,
                'start_at' => to_utc($data['start_at'], $tz),
                'end_at' => !empty($data['end_at']) ? to_utc($data['end_at'], $tz) : null,
                'timezone' => $tz,
                'venue_name' => $data['venue_name'] ?? null,
                'venue_address' => $data['venue_address'] ?? null,
                'map_url' => $data['map_url'] ?? null,
                'language' => $data['language'],
                'visibility' => $data['visibility'],
                'status' => 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::insert('event_settings', ['event_id' => $id] + $this->settingsFrom($data, true));
            $this->invitations->createFromTemplate($id, $templateSlug);
            return $id;
        });
        $event = $this->events->find($eventId);
        $this->audit->event($user, $event, 'event.created');
        return $event;
    }

    /** @param array<string,mixed> $data validated; only keys present are applied */
    public function update(array $user, array $event, array $data): array
    {
        $this->assertTimes($data + ['start_at' => to_local_input($event['start_at'], $event['timezone']), 'timezone' => $event['timezone']]);
        $tz = $data['timezone'] ?? $event['timezone'];
        $changes = [];
        foreach (self::EVENT_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $changes[$f] = $data[$f];
            }
        }
        if (isset($data['start_at'])) {
            $changes['start_at'] = to_utc($data['start_at'], $tz);
        }
        if (array_key_exists('end_at', $data)) {
            $changes['end_at'] = $data['end_at'] ? to_utc($data['end_at'], $tz) : null;
        }
        if ($changes) {
            $this->events->update((int) $event['id'], $changes);
        }
        $this->events->updateSettings((int) $event['id'], $this->settingsFrom($data, false, $tz));
        $before = $event;
        $event = $this->events->find((int) $event['id']);
        $this->audit->event($user, $event, 'event.updated', ['fields' => array_keys($data)]);
        $this->automation->onEventChanged($user, $before, $event);
        return $event;
    }

    /** @return array<string,mixed> columns for event_settings */
    private function settingsFrom(array $data, bool $full, string $tz = 'UTC'): array
    {
        $out = [];
        foreach (self::SETTING_BOOLS as $b) {
            if ($full ? true : array_key_exists($b, $data)) {
                $out[$b] = !empty($data[$b]) ? 1 : 0;
            }
        }
        foreach (['plus_one_limit' => 0, 'max_children' => 4] as $k => $default) {
            if (array_key_exists($k, $data) || $full) {
                $out[$k] = $data[$k] ?? $default;
            }
        }
        if (array_key_exists('capacity', $data) || $full) {
            $out['capacity'] = $data['capacity'] ?? null;
        }
        if (array_key_exists('rsvp_deadline', $data) || $full) {
            $out['rsvp_deadline'] = !empty($data['rsvp_deadline']) ? to_utc($data['rsvp_deadline'], $tz) : null;
        }
        if (isset($data['guest_list_visibility'])) {
            $out['guest_list_visibility'] = $data['guest_list_visibility'];
        }
        if (array_key_exists('thank_you_message', $data)) {
            $out['thank_you_message'] = $data['thank_you_message'];
        }
        if (!empty($data['access_code'])) {
            $out['access_code_hash'] = password_hash((string) $data['access_code'], PASSWORD_DEFAULT);
        } elseif (!empty($data['clear_access_code'])) {
            $out['access_code_hash'] = null;
        }
        return $out;
    }

    private function assertTimes(array $data): void
    {
        if (!empty($data['end_at']) && !empty($data['start_at']) && $data['end_at'] <= $data['start_at']) {
            throw new ValidationException(['end_at' => ['End must be after the start.']], $data);
        }
    }

    public function publish(array $user, array $event): int
    {
        if ($event['moderation_status'] === 'suspended') {
            throw new ForbiddenException('This event has been suspended by an administrator.');
        }
        $this->approval->assertPublishable($event);
        $version = $this->invitations->publish((int) $event['id'], (int) $user['id']);
        $this->events->update((int) $event['id'], ['status' => 'published', 'published_at' => $event['published_at'] ?? utcnow()]);
        $this->audit->event($user, $event, 'event.published', ['version' => $version]);
        return $version;
    }

    /** @param string $status draft (unpublish) | closed | published (reopen) | archived */
    public function setStatus(array $user, array $event, string $status): void
    {
        if ($status === 'published' && $event['moderation_status'] === 'suspended') {
            throw new ForbiddenException('This event has been suspended by an administrator.');
        }
        $this->events->update((int) $event['id'], ['status' => $status]);
        $this->audit->event($user, $event, 'event.status_changed', ['status' => $status]);
    }

    public function softDelete(array $user, array $event): void
    {
        $this->events->update((int) $event['id'], ['deleted_at' => utcnow()]);
        $this->audit->event($user, $event, 'event.deleted', ['purge_after_days' => RetentionService::days()]);
    }

    public function restore(array $user, array $event): bool
    {
        if ($event['deleted_at'] === null || $event['deleted_at'] < utcnow(-RetentionService::days() * 86400)) {
            return false;
        }
        $this->events->update((int) $event['id'], ['deleted_at' => null]);
        $this->audit->event($user, $event, 'event.restored');
        return true;
    }

    /** Duplicate an event's setup (details, invitation, RSVP form, sub-events); optionally the guest list without responses. */
    public function clone(array $user, array $event, bool $withGuests): array
    {
        $newId = Db::transaction(function () use ($user, $event, $withGuests) {
            $now = utcnow();
            $id = Db::insert('events', [
                'uuid' => uuid4(),
                'workspace_id' => $event['workspace_id'],
                'created_by' => (int) $user['id'],
                'slug' => $this->events->newSlug(),
                'title' => str_limit($event['title'], 190) . ' (copy)',
                'category' => $event['category'],
                'host_names' => $event['host_names'],
                'description' => $event['description'],
                'start_at' => $event['start_at'],
                'end_at' => $event['end_at'],
                'timezone' => $event['timezone'],
                'venue_name' => $event['venue_name'],
                'venue_address' => $event['venue_address'],
                'map_url' => $event['map_url'],
                'cover_media_id' => $event['cover_media_id'],
                'language' => $event['language'],
                'visibility' => $event['visibility'],
                'status' => 'draft',
                'cloned_from_id' => $event['id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $s = $this->events->settings((int) $event['id']);
            unset($s['event_id']);
            Db::insert('event_settings', ['event_id' => $id] + $s);
            $this->invitations->copy((int) $event['id'], $id);

            $subMap = [];
            foreach (Db::all('SELECT * FROM sub_events WHERE event_id = ? ORDER BY position', [$event['id']]) as $se) {
                $subMap[$se['id']] = Db::insert('sub_events', [
                    'uuid' => uuid4(), 'event_id' => $id, 'name' => $se['name'], 'description' => $se['description'],
                    'start_at' => $se['start_at'], 'end_at' => $se['end_at'], 'venue_name' => $se['venue_name'],
                    'venue_address' => $se['venue_address'], 'map_url' => $se['map_url'], 'capacity' => $se['capacity'],
                    'rsvp_open' => $se['rsvp_open'], 'position' => $se['position'], 'created_at' => $now,
                ]);
            }
            $qMap = [];
            foreach (Db::all('SELECT * FROM rsvp_questions WHERE event_id = ? ORDER BY position', [$event['id']]) as $q) {
                $qMap[$q['id']] = Db::insert('rsvp_questions', [
                    'uuid' => uuid4(), 'event_id' => $id, 'sub_event_id' => $q['sub_event_id'] ? ($subMap[$q['sub_event_id']] ?? null) : null,
                    'type' => $q['type'], 'label' => $q['label'], 'help_text' => $q['help_text'], 'is_required' => $q['is_required'],
                    'position' => $q['position'], 'condition_json' => $q['condition_json'], 'created_at' => $now,
                ]);
                foreach (Db::all('SELECT label, position FROM rsvp_question_options WHERE question_id = ?', [$q['id']]) as $o) {
                    Db::insert('rsvp_question_options', ['question_id' => $qMap[$q['id']], 'label' => $o['label'], 'position' => $o['position']]);
                }
            }
            // Conditions that reference another question by uuid must point at the copy.
            $uuidMap = [];
            foreach ($qMap as $oldId => $newQid) {
                $uuidMap[(string) Db::value('SELECT uuid FROM rsvp_questions WHERE id = ?', [$oldId])] = (string) Db::value('SELECT uuid FROM rsvp_questions WHERE id = ?', [$newQid]);
            }
            foreach (Db::all('SELECT id, condition_json FROM rsvp_questions WHERE event_id = ? AND condition_json IS NOT NULL', [$id]) as $q) {
                $c = json_decode((string) $q['condition_json'], true);
                if (isset($c['question']) && isset($uuidMap[$c['question']])) {
                    $c['question'] = $uuidMap[$c['question']];
                    Db::update('rsvp_questions', ['condition_json' => json_out($c)], ['id' => $q['id']]);
                }
            }

            if ($withGuests) {
                $groupMap = [];
                foreach (Db::all('SELECT * FROM guest_groups WHERE event_id = ?', [$event['id']]) as $g) {
                    $groupMap[$g['id']] = Db::insert('guest_groups', ['uuid' => uuid4(), 'event_id' => $id, 'name' => $g['name'], 'created_at' => $now]);
                }
                foreach (Db::all('SELECT * FROM event_guests WHERE event_id = ? AND anonymized_at IS NULL', [$event['id']]) as $eg) {
                    $newEg = Db::insert('event_guests', [
                        'uuid' => uuid4(), 'event_id' => $id, 'guest_id' => $eg['guest_id'],
                        'group_id' => $eg['group_id'] ? ($groupMap[$eg['group_id']] ?? null) : null,
                        'source' => 'manual', 'plus_one_limit' => $eg['plus_one_limit'], 'child_limit' => $eg['child_limit'],
                        'is_vip' => $eg['is_vip'], 'host_notes' => $eg['host_notes'], 'do_not_contact' => $eg['do_not_contact'],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    Db::exec('INSERT INTO guest_tags (event_guest_id, tag) SELECT ?, tag FROM guest_tags WHERE event_guest_id = ?', [$newEg, $eg['id']]);
                }
            }
            return $id;
        });
        $new = $this->events->find($newId);
        $this->audit->event($user, $new, 'event.cloned', ['from' => $event['uuid'], 'with_guests' => $withGuests]);
        return $new;
    }

    public function publicUrl(array $event): string
    {
        return app_url('i/' . $event['slug']);
    }
}
