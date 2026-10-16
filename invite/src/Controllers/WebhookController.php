<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\MessageService;

/** Inbound provider webhooks (CSRF-exempt; authenticated by a shared secret). */
final class WebhookController extends Controller
{
    public function __construct(private MessageService $messages)
    {
    }

    /**
     * Email provider bounce/complaint notification.
     * POST /webhooks/email-bounce  Authorization: Bearer <BOUNCE_WEBHOOK_SECRET>  {"message_id":"<…>","reason":"mailbox full"}
     */
    public function emailBounce(Request $req): Response
    {
        $secret = (string) env('BOUNCE_WEBHOOK_SECRET', '');
        if ($secret === '' || !hash_equals($secret, (string) $req->bearer())) {
            throw new HttpException(401, 'Invalid webhook secret');
        }
        $mid = $req->string('message_id');
        if ($mid === '') {
            throw new HttpException(422, 'message_id is required');
        }
        $found = $this->messages->recordBounce($mid, $req->string('reason', 'Bounced'));
        return $this->json(['recorded' => $found]);
    }
}
