<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Lang;
use App\Core\ValidationException;
use App\Repositories\GuestRepository;

/**
 * Orchestrates a guest-initiated RSVP, shared by the web form and the public JSON API:
 * guest creation (public link flow), main response + per-function answers in one transaction, confirmation email.
 */
final class GuestRsvpService
{
    public function __construct(
        private RsvpService $rsvp,
        private GuestService $guestService,
        private GuestRepository $guests,
        private LinkService $links
    ) {
    }

    /**
     * Respond as an already-known guest (personal link).
     * @param array{status:string,plus_ones?:int,children?:int,comment?:?string,show_name?:bool} $data
     * @return array{response:array,changed:bool,created:bool,status:string,waitlisted:bool}
     */
    public function respond(array $event, array $settings, array $eg, array $data, ?array $answers, ?array $companions, ?array $functions): array
    {
        $plus = (int) ($data['plus_ones'] ?? 0);
        $kids = (int) ($data['children'] ?? 0);
        // One transaction: the main RSVP and the per-function answers succeed or fail together.
        $result = Db::transaction(function () use ($event, $settings, $eg, $data, $answers, $companions, $functions, $plus, $kids) {
            $r = $this->rsvp->submit($event, $settings, $eg, [
                'status' => $data['status'], 'plus_ones' => $plus, 'children' => $kids,
                'companions' => $companions ?? [], 'answers' => $answers ?? [],
                'comment' => $data['comment'] ?? null, 'show_name' => !empty($data['show_name']),
            ], 'guest');
            $this->rsvp->submitSubEvents($event, $settings, $eg, $data['status'], $plus, $kids, $functions ?? []);
            return $r;
        });
        if ($result['changed']) {
            $guest = Db::one('SELECT * FROM guests WHERE id = ?', [$eg['guest_id']]);
            $this->rsvp->sendConfirmation($event, $eg, $guest, $result['response']);
        }
        return $result;
    }

    /**
     * Respond via the public link: creates the guest record, unless that email already responded
     * (then we re-send that guest's own link rather than let a stranger overwrite their answer).
     * @param array{name:string,email?:?string,phone?:?string,status:string,plus_ones?:int,children?:int,comment?:?string,show_name?:bool} $data
     * @return array{existing:bool,eg:?array,token:?string,status:?string}
     */
    public function respondNew(array $event, array $settings, array $data, ?array $answers, ?array $companions, ?array $functions): array
    {
        if (!empty($data['email']) && $settings['collect_email'] && ($existing = $this->guests->findByEmailInEvent((int) $event['id'], $data['email']))) {
            $resp = Db::one('SELECT * FROM rsvp_responses WHERE event_guest_id = ? AND sub_event_scope = 0', [$existing['id']]);
            // Re-send the guest's own link (their RSVP, or a prompt to respond if the host added them but they have not yet).
            $this->rsvp->sendConfirmation($event, $existing, $existing, $resp ?: ['status' => 'pending', 'plus_ones' => 0, 'children' => 0]);
            return ['existing' => true, 'eg' => null, 'token' => null, 'status' => null];
        }
        [$first, $last] = array_pad(preg_split('/\s+/', trim($data['name']), 2) ?: [], 2, null);
        $eg = $this->guestService->add($event, [
            'first_name' => $first, 'last_name' => $last,
            'email' => $settings['collect_email'] ? ($data['email'] ?? null) : null,
            'phone' => $settings['collect_phone'] ? ($data['phone'] ?? null) : null,
            'language' => Lang::locale(),
        ], 'public_link')['eg'];
        try {
            $r = $this->respond($event, $settings, $eg, $data, $answers, $companions, $functions);
        } catch (ValidationException $e) {
            // Do not leave a guest record behind for an RSVP that was rejected (e.g. over the plus-one limit).
            Db::exec('DELETE FROM event_guests WHERE id = ?', [$eg['id']]);
            throw $e;
        }
        return ['existing' => false, 'eg' => $eg, 'token' => $this->links->guestToken($eg), 'status' => $r['status']];
    }
}
