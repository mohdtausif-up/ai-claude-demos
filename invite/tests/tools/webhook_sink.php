<?php

// Test receiver for outbound webhooks: php -S 127.0.0.1:8099 tests/tools/webhook_sink.php
// Appends each request as one JSON line to $SINK_FILE and answers with ?status=<code> (default 200).
$file = getenv('SINK_FILE') ?: '/tmp/webhook-sink.jsonl';
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
    }
}
file_put_contents($file, json_encode(['headers' => $headers, 'body' => file_get_contents('php://input')]) . "\n", FILE_APPEND | LOCK_EX);
http_response_code((int) ($_GET['status'] ?? 200));
echo 'ok';