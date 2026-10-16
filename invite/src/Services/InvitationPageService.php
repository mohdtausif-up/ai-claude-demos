<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Container;
use App\Core\Db;
use App\Repositories\GuestRepository;

/** Assembles everything the invitation page (guest view and host preview) needs. */
final class InvitationPageService
{
    public function __construct(
        private InvitationService $invitations,
        private QuestionService $questions,
        private RsvpService $rsvp,
        private GuestRepository $guests,
        private LinkService $links,
        private \App\Repositories\EventRepository $events,
        private SubEventService $subEvents
    ) {
    }

    /**
     * @param array|null $eg guest row (BASE_SELECT) when opened via a personal link
     * @return array<string,mixed>|null null when the invitation has no published version and this is not a preview
     */
    public function context(array $event, array $settings, ?array $eg = null, bool $preview = false): ?array
    {
        $page = $this->invitations->rendered((int) $event['id'], $preview);
        if ($page === null) {
            return null;
        }
        $cover = $this->events->coverUrl($event);
        foreach ($page['sections'] as $s) {
            if ($s['type'] === 'hero' && !empty($s['content']['image'])) {
                $cover = $s['content']['image'];
            }
        }

        $response = null;
        $answers = [];
        $companions = [];
        if ($eg && !empty($eg['response_id'])) {
            $response = Db::one('SELECT * FROM rsvp_responses WHERE id = ?', [$eg['response_id']]);
            $byId = $this->guests->answers((int) $eg['response_id']);
            foreach ($this->questions->forEvent((int) $event['id'], null, false) as $q) {
                if (isset($byId[(int) $q['id']])) {
                    $raw = $byId[(int) $q['id']];
                    $answers[$q['uuid']] = $raw !== '' && $raw[0] === '[' ? (json_decode($raw, true) ?: []) : $raw;
                }
            }
            $companions = $this->guests->companions((int) $eg['response_id']);
        }

        $groupUuid = $eg['group_uuid'] ?? '';
        $questions = array_values(array_filter(
            $this->questions->forEvent((int) $event['id'], null, false),
            // Questions restricted to certain guest groups are only sent to those guests.
            static fn ($q) => empty($q['condition']['groups']) || in_array($groupUuid, $q['condition']['groups'], true)
        ));

        $blocked = $preview ? null : $this->rsvp->blockedReason($event, $settings, $response !== null);
        return [
            'event' => $event,
            'settings' => $settings,
            'theme' => $page['theme'],
            'themeCss' => self::themeCss($page['theme']) . "\n" . Container::get(BrandService::class)->forWorkspace((int) $event['workspace_id'])['css'],
            'sections' => $page['sections'],
            'cover' => $cover,
            'eg' => $eg,
            'response' => $response,
            'answers' => $answers,
            'companions' => $companions,
            'questions' => $questions,
            'blocked' => $blocked,
            'preview' => $preview,
            'maps' => $this->links->map($event),
            'google' => $this->links->googleCalendar($event),
            'ics' => $eg ? app_url('g/' . $this->links->guestToken($eg) . '/calendar.ics') : $this->links->ics($event),
            'publicList' => $this->publicList($event, $settings),
            'idem' => uuid4(),
            // Functions this guest may attend (everyone sees the open ones before they have a personal link).
            'subEvents' => $eg
                ? $this->subEvents->eligibleFor((int) $event['id'], (int) $eg['id'])
                : Db::all("SELECT * FROM sub_events WHERE event_id = ? AND eligibility = 'all' ORDER BY position, start_at", [$event['id']]),
            'subResponses' => $eg ? $this->subEvents->responsesFor((int) $eg['id']) : [],
        ];
    }

    /** Context for previewing a template with made-up event details (no database event, form disabled). */
    public function sampleContext(array $template): array
    {
        $start = gmdate('Y-m-d H:i:s', strtotime('next saturday 18:00'));
        $event = [
            'id' => 0, 'uuid' => uuid4(), 'slug' => 'sample', 'title' => 'Your Event Title', 'host_names' => 'Host names', 'description' => 'A short description of your event goes here.',
            'start_at' => $start, 'end_at' => null, 'timezone' => 'UTC', 'venue_name' => 'Venue name', 'venue_address' => '123 Sample Street, City',
            'map_url' => null, 'language' => 'en', 'visibility' => 'public', 'status' => 'published', 'moderation_status' => 'ok', 'deleted_at' => null,
        ];
        $settings = ['plus_one_limit' => 1, 'allow_children' => 1, 'max_children' => 2, 'allow_maybe' => 1, 'collect_email' => 1, 'collect_phone' => 0,
            'guest_list_visibility' => 'hidden', 'thank_you_message' => null, 'capacity' => null, 'indexable' => 0, 'rsvp_open' => 1, 'rsvp_deadline' => null];
        $theme = BlockRegistry::sanitizeTheme(json_decode((string) $template['theme_json'], true));
        return [
            'event' => $event, 'settings' => $settings, 'theme' => $theme, 'themeCss' => self::themeCss($theme),
            'sections' => BlockRegistry::sanitizeSections(json_decode((string) $template['sections_json'], true)),
            'cover' => null, 'eg' => null, 'response' => null, 'answers' => [], 'companions' => [], 'questions' => [], 'blocked' => null,
            'preview' => true, 'previewNote' => 'Sample preview of the “' . $template['name'] . '” template. Your own details will appear here.',
            'maps' => null, 'google' => '#', 'ics' => '#', 'publicList' => ['mode' => 'hidden'], 'idem' => '', 'subEvents' => [], 'subResponses' => [],
            'saved' => false, 'token' => null, 'formAction' => '#', 'emailSent' => false, 'langLinks' => [],
        ];
    }

    public function themeCssFor(array $event): string
    {
        $page = $this->invitations->rendered((int) $event['id'], true);
        return self::themeCss($page['theme'] ?? []) . "\n" . \App\Core\Container::get(BrandService::class)->forWorkspace((int) $event['workspace_id'])['css'];
    }

    /** CSS custom properties from a (validated) theme. Only hex colours and whitelisted font stacks are ever emitted. */
    public static function themeCss(array $t): string
    {
        $t = BlockRegistry::sanitizeTheme($t);
        $f = BlockRegistry::FONTS;
        return ':root{--inv-primary:' . $t['primary'] . ';--inv-accent:' . $t['accent'] . ';--inv-bg:' . $t['bg'] . ';--inv-text:' . $t['text']
            . ';--inv-font-heading:' . $f[$t['font_heading']][0] . ';--inv-font-body:' . $f[$t['font_body']][0] . '}';
    }

    /** What the host allows other guests to see about attendance. */
    private function publicList(array $event, array $settings): array
    {
        $mode = $settings['guest_list_visibility'] ?? 'hidden';
        if ($mode === 'hidden') {
            return ['mode' => 'hidden'];
        }
        $total = (int) Db::value("SELECT COALESCE(SUM(1 + plus_ones + children), 0) FROM rsvp_responses WHERE event_id = ? AND status = 'attending' AND sub_event_scope = 0", [$event['id']]);
        if ($mode === 'total') {
            return ['mode' => 'total', 'total' => $total];
        }
        // Names appear only for attending guests who ticked "show my name".
        $rows = Db::all(
            "SELECT g.first_name, g.last_name FROM rsvp_responses r JOIN event_guests eg ON eg.id = r.event_guest_id JOIN guests g ON g.id = eg.guest_id
             WHERE r.event_id = ? AND r.status = 'attending' AND r.show_name = 1 AND r.sub_event_scope = 0 AND eg.anonymized_at IS NULL ORDER BY r.responded_at LIMIT 100",
            [$event['id']]
        );
        $names = array_map(static fn ($r) => $mode === 'first_names' ? $r['first_name'] : trim($r['first_name'] . ' ' . $r['last_name']), $rows);
        return ['mode' => $mode, 'total' => $total, 'names' => $names];
    }
}
