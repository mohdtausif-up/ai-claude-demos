<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\NotFoundException;

/** The invitation builder's persistence: draft sections, published snapshots and version history. */
final class InvitationService
{
    /** @return array{id:int,theme:array,sections:list<array>,template_id:?int,published_version_id:?int,updated_at:string} */
    public function draft(int $eventId): array
    {
        $inv = Db::one('SELECT * FROM invitations WHERE event_id = ?', [$eventId]) ?? throw new NotFoundException();
        $rows = Db::all('SELECT type, content_json, hidden_on FROM invitation_sections WHERE invitation_id = ? ORDER BY position', [$inv['id']]);
        return [
            'id' => (int) $inv['id'],
            'theme' => BlockRegistry::sanitizeTheme(json_decode((string) $inv['theme_json'], true)),
            'sections' => array_map(static fn ($r) => [
                'type' => $r['type'],
                'content' => json_decode((string) $r['content_json'], true) ?: [],
                'hidden_on' => $r['hidden_on'],
            ], $rows),
            'template_id' => $inv['template_id'] !== null ? (int) $inv['template_id'] : null,
            'published_version_id' => $inv['published_version_id'] !== null ? (int) $inv['published_version_id'] : null,
            'updated_at' => $inv['updated_at'],
        ];
    }

    /** Create the invitation for a new event from a template (or blank). */
    public function createFromTemplate(int $eventId, ?string $templateSlug): int
    {
        $tpl = $templateSlug ? Db::one('SELECT * FROM invitation_templates WHERE slug = ? AND is_active = 1', [$templateSlug]) : null;
        $tpl ??= Db::one("SELECT * FROM invitation_templates WHERE slug = 'blank'");
        $theme = BlockRegistry::sanitizeTheme(json_decode((string) ($tpl['theme_json'] ?? '{}'), true));
        $sections = BlockRegistry::sanitizeSections(json_decode((string) ($tpl['sections_json'] ?? '[]'), true));
        $invId = Db::insert('invitations', [
            'event_id' => $eventId,
            'template_id' => $tpl['id'] ?? null,
            'theme_json' => json_out($theme),
            'updated_at' => utcnow(),
        ]);
        $this->writeSections($invId, $sections);
        return $invId;
    }

    private function writeSections(int $invId, array $sections): void
    {
        Db::exec('DELETE FROM invitation_sections WHERE invitation_id = ?', [$invId]);
        foreach ($sections as $i => $s) {
            Db::insert('invitation_sections', [
                'invitation_id' => $invId,
                'type' => $s['type'],
                'position' => $i,
                'content_json' => json_out($s['content']),
                'hidden_on' => $s['hidden_on'],
            ]);
        }
    }

    /**
     * Autosave target. Everything is re-sanitised server side; the client is never trusted.
     * @return array{warnings:list<string>,saved_at:string}
     */
    public function saveDraft(int $eventId, mixed $theme, mixed $sections): array
    {
        \App\Core\Container::get(ApprovalService::class)->onContentChanged($eventId);
        $draft = $this->draft($eventId);
        $theme = BlockRegistry::sanitizeTheme($theme);
        $sections = BlockRegistry::sanitizeSections($sections);
        Db::transaction(function () use ($draft, $theme, $sections) {
            Db::update('invitations', ['theme_json' => json_out($theme), 'updated_at' => utcnow()], ['id' => $draft['id']]);
            $this->writeSections($draft['id'], $sections);
        });
        return ['warnings' => BlockRegistry::themeWarnings($theme), 'saved_at' => utcnow()];
    }

    /** Freeze the current draft as the version guests see. @return int version number */
    public function publish(int $eventId, ?int $userId): int
    {
        $draft = $this->draft($eventId);
        $sections = $draft['sections'];
        if (!array_filter($sections, static fn ($s) => $s['type'] === 'rsvp')) {
            $sections[] = ['type' => 'rsvp', 'content' => BlockRegistry::defaultContent('rsvp'), 'hidden_on' => 'none'];
        }
        $snapshot = json_out(['theme' => $draft['theme'], 'sections' => $sections]);
        return (int) Db::transaction(function () use ($draft, $snapshot, $userId) {
            $no = (int) Db::value('SELECT COALESCE(MAX(version_no), 0) + 1 FROM invitation_versions WHERE invitation_id = ? FOR UPDATE', [$draft['id']]);
            $vid = Db::insert('invitation_versions', [
                'invitation_id' => $draft['id'],
                'version_no' => $no,
                'snapshot_json' => $snapshot,
                'created_by' => $userId,
                'created_at' => utcnow(),
            ]);
            Db::update('invitations', ['published_version_id' => $vid], ['id' => $draft['id']]);
            return $no;
        });
    }

    /**
     * What guests should see: the published snapshot, or (for host previews) the live draft.
     * @return array{theme:array,sections:list<array>}|null
     */
    public function rendered(int $eventId, bool $preferDraft = false): ?array
    {
        if (!$preferDraft) {
            $json = Db::value(
                'SELECT v.snapshot_json FROM invitations i JOIN invitation_versions v ON v.id = i.published_version_id WHERE i.event_id = ?',
                [$eventId]
            );
            if ($json) {
                $snap = json_decode((string) $json, true);
                return [
                    'theme' => BlockRegistry::sanitizeTheme($snap['theme'] ?? []),
                    'sections' => BlockRegistry::sanitizeSections($snap['sections'] ?? []),
                ];
            }
            return null;
        }
        $d = $this->draft($eventId);
        if (!array_filter($d['sections'], static fn ($s) => $s['type'] === 'rsvp')) {
            $d['sections'][] = ['type' => 'rsvp', 'content' => BlockRegistry::defaultContent('rsvp'), 'hidden_on' => 'none'];
        }
        return ['theme' => $d['theme'], 'sections' => $d['sections']];
    }

    public function versions(int $eventId): array
    {
        return Db::all(
            'SELECT v.version_no, v.created_at, u.name AS created_by_name, (i.published_version_id = v.id) AS is_live
             FROM invitations i JOIN invitation_versions v ON v.invitation_id = i.id LEFT JOIN users u ON u.id = v.created_by
             WHERE i.event_id = ? ORDER BY v.version_no DESC LIMIT 30',
            [$eventId]
        );
    }

    /** Copy an old snapshot back into the draft (does not publish). */
    public function restoreVersion(int $eventId, int $versionNo): bool
    {
        $draft = $this->draft($eventId);
        $json = Db::value('SELECT snapshot_json FROM invitation_versions WHERE invitation_id = ? AND version_no = ?', [$draft['id'], $versionNo]);
        if (!$json) {
            return false;
        }
        $snap = json_decode((string) $json, true);
        $this->saveDraft($eventId, $snap['theme'] ?? [], $snap['sections'] ?? []);
        return true;
    }

    /** Copy draft + theme from one event's invitation into another (event cloning). */
    public function copy(int $fromEventId, int $toEventId): void
    {
        $d = $this->draft($fromEventId);
        $invId = Db::insert('invitations', [
            'event_id' => $toEventId,
            'template_id' => $d['template_id'],
            'theme_json' => json_out($d['theme']),
            'updated_at' => utcnow(),
        ]);
        $this->writeSections($invId, $d['sections']);
    }

    public function applyTemplate(int $eventId, string $slug): bool
    {
        $tpl = Db::one('SELECT * FROM invitation_templates WHERE slug = ? AND is_active = 1', [$slug]);
        if (!$tpl) {
            return false;
        }
        $this->saveDraft($eventId, json_decode((string) $tpl['theme_json'], true), json_decode((string) $tpl['sections_json'], true));
        Db::update('invitations', ['template_id' => $tpl['id']], ['event_id' => $eventId]);
        return true;
    }
}
