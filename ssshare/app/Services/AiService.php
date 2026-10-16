<?php

declare(strict_types=1);

namespace App\Services;

use Anthropic\Client;
use App\Support\Database;
use App\Support\HttpException;
use App\Support\Settings;
use Throwable;

/**
 * Optional AI features (Claude via the official Anthropic SDK): smart summaries and
 * AI-assisted redaction (finding names that pattern matching cannot).
 *
 * Content is only sent when ALL of these hold: the `ai_features` flag is on, an API key is
 * configured, and the owner has explicitly opted in (personal consent, or their workspace
 * has enabled AI). Guests, encrypted shares and opted-out users are never processed.
 */
final class AiService
{
    /** @var null|callable(array $content, array $schema): array test double */
    private static $fake = null;

    public static function fake(?callable $responder): void
    {
        self::$fake = $responder;
    }

    public static function configured(): bool
    {
        return self::$fake !== null
            || (config('app.ai.api_key') !== '' && class_exists(Client::class));
    }

    public static function allowedFor(?array $user, ?array $upload = null): bool
    {
        if ($user === null || !Settings::feature('ai_features') || !self::configured()) {
            return false;
        }
        if ($upload !== null && $upload['content_type'] === 'encrypted') {
            return false;
        }
        $workspaceId = $upload['workspace_id'] ?? $user['current_workspace_id'] ?? null;
        if ($workspaceId !== null) {
            return (int) Database::value('SELECT ai_enabled FROM workspaces WHERE id = ?', [$workspaceId]) === 1;
        }
        return $user['ai_consent_at'] !== null;
    }

    /** A one-or-two sentence summary of a text or image share, stored on the upload. */
    public function summarize(array $upload, array $user): string
    {
        if (!self::allowedFor($user, $upload)) {
            throw HttpException::forbidden('AI features are not enabled for this share.');
        }
        $file = UploadService::primaryFile((int) $upload['id']) ?? throw HttpException::notFound();
        $bytes = StorageService::driver()->get($file['object_key']);
        $instruction = 'Summarise this shared ' . ($upload['content_type'] === 'text' ? 'text' : 'screenshot')
            . ' in at most two short, neutral sentences so the owner can recognise it later. '
            . 'Do not repeat secrets, credentials or personal contact details; describe them generically instead.';

        $content = $upload['content_type'] === 'text'
            ? [['type' => 'text', 'text' => $instruction . "\n\n<shared_text>\n" . mb_substr($bytes, 0, 60000) . "\n</shared_text>"]]
            : [self::imageBlock($bytes), ['type' => 'text', 'text' => $instruction]];

        $result = $this->call($content, [
            'type' => 'object',
            'properties' => ['summary' => ['type' => 'string']],
            'required' => ['summary'],
            'additionalProperties' => false,
        ]);
        $summary = mb_substr(trim((string) ($result['summary'] ?? '')), 0, 600);
        Database::update('uploads', ['summary' => $summary, 'summary_generated_at' => db_time()], 'id = ?', [$upload['id']]);
        AuditLogService::record('ai.summary_generated', 'upload', $upload['uuid']);
        return $summary;
    }

    /**
     * Names of people visible in an image's OCR text (e.g. notification banners, chat
     * headers), returned as redaction suggestion boxes. Always reviewed by the user.
     */
    public function suggestNameRedactions(string $imageBytes, array $user): array
    {
        if (!self::allowedFor($user)) {
            return [];
        }
        $ocr = (new OcrService())->recognize($imageBytes);
        if ($ocr['lines'] === []) {
            return [];
        }
        $text = implode("\n", array_column($ocr['lines'], 'text'));
        $result = $this->call([[
            'type' => 'text',
            'text' => "The following lines were read from a screenshot by OCR. List every personal name of a real person that appears "
                . "(senders, contacts, people in notifications or chat headers). Copy each name exactly as it appears. "
                . "Do not include company, product or place names.\n\n<ocr_lines>\n" . mb_substr($text, 0, 20000) . "\n</ocr_lines>",
        ]], [
            'type' => 'object',
            'properties' => ['names' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['names'],
            'additionalProperties' => false,
        ]);

        $W = max(1, $ocr['width']);
        $H = max(1, $ocr['height']);
        $boxes = [];
        foreach (array_unique(array_filter(array_map('trim', (array) ($result['names'] ?? [])))) as $name) {
            $parts = preg_split('/\s+/', mb_strtolower($name));
            foreach ($ocr['lines'] as $line) {
                $words = $line['words'];
                for ($i = 0; $i + count($parts) <= count($words); $i++) {
                    $slice = array_slice($words, $i, count($parts));
                    $matched = true;
                    foreach ($parts as $k => $part) {
                        if (trim(mb_strtolower($slice[$k]['text']), '.,:;!?()') !== $part) {
                            $matched = false;
                            break;
                        }
                    }
                    if ($matched) {
                        $x1 = min(array_column($slice, 'x'));
                        $y1 = min(array_column($slice, 'y'));
                        $x2 = max(array_map(static fn ($w) => $w['x'] + $w['w'], $slice));
                        $y2 = max(array_map(static fn ($w) => $w['y'] + $w['h'], $slice));
                        $boxes[] = [
                            'kind' => 'name', 'label' => 'Name (AI suggestion)', 'preview' => RedactionService::mask($name),
                            'x' => max(0, ($x1 - 4) / $W), 'y' => max(0, ($y1 - 3) / $H),
                            'w' => min(1, ($x2 - $x1 + 8) / $W), 'h' => min(1, ($y2 - $y1 + 6) / $H),
                        ];
                    }
                }
            }
        }
        return $boxes;
    }

    /** @return array decoded structured output */
    private function call(array $content, array $schema): array
    {
        if (self::$fake !== null) {
            return (self::$fake)($content, $schema);
        }
        try {
            $client = new Client(apiKey: (string) config('app.ai.api_key'));
            $message = $client->beta->messages->create(
                maxTokens: 2000,
                messages: [['role' => 'user', 'content' => $content]],
                model: (string) config('app.ai.model'),
                outputConfig: [
                    'effort' => 'low',
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                // Server-side fallback: if the model declines, the request is retried on a suitable model.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (Throwable $e) {
            error_log('AI request failed: ' . $e->getMessage());
            throw new HttpException(503, 'ai_unavailable', 'The AI service is unavailable right now. Try again later.');
        }
        if ($message->stopReason === 'refusal') {
            throw new HttpException(422, 'ai_declined', 'The AI declined to process this content.');
        }
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $decoded = json_decode($block->text, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        throw new HttpException(502, 'ai_bad_response', 'The AI returned an unexpected response.');
    }

    /** Image content block; AVIF and very large images are converted to a bounded PNG first. */
    private static function imageBlock(string $bytes): array
    {
        $type = ImageProcessingService::detectType($bytes);
        $info = @getimagesizefromstring($bytes);
        $tooBig = $info !== false && max($info[0], $info[1]) > 1568;
        if ($type === 'avif' || $tooBig || strlen($bytes) > 4_500_000) {
            $im = imagecreatefromstring($bytes);
            if ($tooBig) {
                $scale = 1568 / max(imagesx($im), imagesy($im));
                $im = imagescale($im, (int) (imagesx($im) * $scale), (int) (imagesy($im) * $scale));
            }
            ob_start();
            imagepng($im, null, 6);
            $bytes = (string) ob_get_clean();
            $type = 'png';
        }
        return ['type' => 'image', 'source' => [
            'type' => 'base64',
            'mediaType' => ImageProcessingService::TYPES[$type]['mime'],
            'data' => base64_encode($bytes),
        ]];
    }
}
