<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Services\MessageService;

/** Email invitations, reminders and updates, plus delivery visibility. */
final class MessageController extends Controller
{
    public function __construct(private MessageService $messages)
    {
    }

    public function index(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'guests.view');
        $event = $c['event'];
        $kind = in_array($req->query['kind'] ?? '', array_keys(MessageService::KINDS), true) ? $req->query['kind'] : 'invitation';
        $segment = (string) ($req->query['segment'] ?? ($kind === 'reminder' ? 'pending' : 'not_invited'));
        $selected = Session::get('compose_selected_' . $event['uuid'], []);
        $counts = [];
        foreach (array_keys(MessageService::SEGMENTS) as $s) {
            $counts[$s] = $s === 'selected'
                ? count($this->messages->resolveSegment((int) $event['id'], 'selected', $selected)['recipients'])
                : count($this->messages->resolveSegment((int) $event['id'], $s)['recipients']);
        }
        $defaults = [];
        foreach (array_keys(MessageService::KINDS) as $k) {
            $defaults[$k] = $this->messages->defaultTemplate($event, $k);
        }
        return $this->view('messages/index', $c + [
            'kind' => $kind, 'segment' => $segment, 'counts' => $counts, 'defaults' => $defaults, 'selected' => $selected,
            'history' => $this->messages->history((int) $event['id']),
            'problems' => $this->messages->deliveryProblems((int) $event['id']),
            'queued' => (int) Db::value("SELECT COUNT(*) FROM message_recipients mr JOIN messages m ON m.id = mr.message_id WHERE m.event_id = ? AND mr.status = 'queued'", [$event['id']]),
            'waText' => $this->messages->whatsappText($event, null),
            'placeholders' => MessageService::placeholders(), 'active' => 'messages',
            'canSend' => $c['can']['messages.send'],
        ]);
    }

    public function send(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'messages.send');
        $d = $this->validated($req);
        if ($d['segment'] === 'selected') {
            $d['selected'] = Session::get('compose_selected_' . $c['event']['uuid'], []);
        }
        $msg = $this->messages->send($c['user'], $c['event'], $d);
        Session::forget('compose_selected_' . $c['event']['uuid']);
        $skipped = array_sum($msg['skipped']);
        $when = $msg['scheduled_at'] ? ' It will go out at ' . fmt_dt($msg['scheduled_at'], $c['event']['timezone']) . '.' : ' Emails are being sent now.';
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/messages', 'Queued ' . $msg['recipient_count'] . ' email(s).' . $when . ($skipped ? " $skipped guest(s) were skipped (no email address or unsubscribed)." : ''));
    }

    public function test(Request $req, array $p): Response
    {
        $c = $this->eventContext($req, $p['uuid'], 'messages.send');
        $d = $this->validated($req, false);
        if (!$c['user']['email_verified_at']) {
            return $this->redirect('/app/events/' . $c['event']['uuid'] . '/messages', 'Confirm your own email address first.', 'error');
        }
        $this->messages->sendTest($c['user'], $c['event'], $d);
        return $this->redirect('/app/events/' . $c['event']['uuid'] . '/messages?kind=' . $d['kind'], 'Test email sent to ' . $c['user']['email'] . '.');
    }

    /** @return array<string,mixed> */
    private function validated(Request $req, bool $needSegment = true): array
    {
        return Validator::validate($req->all(), [
            'kind' => 'required|in:' . implode(',', array_keys(MessageService::KINDS)),
            'segment' => ($needSegment ? 'required|' : 'nullable|') . 'in:' . implode(',', array_keys(MessageService::SEGMENTS)),
            'subject' => 'required|string|min:2|max:200',
            'body' => 'required|string|min:5|max:5000',
            'scheduled_at' => 'nullable|datetime',
        ]);
    }
}
