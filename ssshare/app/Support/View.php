<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class View
{
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial($layout, ['content' => $content] + $data);
    }

    public static function partial(string $template, array $data = []): string
    {
        $__path = BASE_PATH . '/app/Views/' . $template . '.php';
        if (!is_file($__path)) {
            throw new RuntimeException("View [$template] not found.");
        }
        // Template data may use any name, so only the $__path local can be shadowed.
        unset($data['__path']);
        extract(self::$shared + $data, EXTR_SKIP);
        ob_start();
        try {
            include $__path;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}
