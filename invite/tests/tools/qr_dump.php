<?php

declare(strict_types=1);

// Dev tool: writes SVGs for several payload sizes so an external decoder (OpenCV) can verify the encoder.
// Usage (in container): php tests/tools/qr_dump.php /var/www/html/storage/logs/qr [masks]
// With "masks" every sample is written once per forced mask pattern (0-7).
require __DIR__ . '/../../bootstrap.php';

$dir = $argv[1] ?? '/tmp/qr';
$allMasks = in_array('masks', $argv, true);
array_map('unlink', glob("$dir/*") ?: []);
@mkdir($dir, 0775, true);
$qr = new App\Services\QrService();
$tokens = new App\Services\TokenService();
App\Core\Env::set('APP_KEY', str_repeat('ab', 32));
$samples = [
    'tiny' => 'HI',
    'url' => 'http://localhost:8480/i/abc123def456',
    'guest' => 'http://localhost:8480/g/' . $tokens->make('g', '11111111-2222-4333-8444-555555555555', 1),
    'qrtoken' => 'https://invite.example.com/q/' . $tokens->make('q', '11111111-2222-4333-8444-555555555555', 3),
    'v5' => str_repeat('Invite.com RSVP ', 8),
    'v7' => str_repeat('0123456789abcdef', 8) . str_repeat('-', 20),
    'v10' => str_repeat('The quick brown fox jumps. ', 7) . 'end',
    'unicode' => 'Haldi · हल्दी · مرحبا',
];
$n = 0;
foreach ($samples as $name => $data) {
    foreach (['L', 'M'] as $ecc) {
        foreach ($allMasks ? range(0, 7) : [null] as $mask) {
            $qr->forceMask = $mask;
            $tag = $mask === null ? '' : "_m$mask";
            file_put_contents("$dir/{$name}_{$ecc}{$tag}.svg", $qr->svg($data, $ecc));
            file_put_contents("$dir/{$name}_{$ecc}{$tag}.txt", $data);
            $n++;
        }
    }
}
echo "wrote $n codes\n";
