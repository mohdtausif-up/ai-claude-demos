<?php

declare(strict_types=1);

namespace App\Core;

/** Server-rendered PHP templates (views/*.php). Templates receive their data as variables plus `$v` (this View). */
final class View
{
    private array $vars = [];
    private ?string $layout;

    /** Data available to every template (e.g. the signed-in user), set by middleware. */
    private static array $shared = [];

    private function __construct(?string $layout)
    {
        $this->layout = $layout;
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $data += self::$shared;
        $v = new self($layout);
        $content = $v->partial($template, $data);
        if ($v->layout === null) {
            return $content;
        }
        return $v->partial($v->layout, $data + ['content' => $content]);
    }

    /** Render a template fragment without a layout. */
    public function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/views/' . $template . '.php';
        $v = $this;
        $render = static function () use ($file, $data, $v, $template): string {
            extract($data, EXTR_SKIP);
            ob_start();
            try {
                // include returns false only when the file cannot be opened (a template may end early with a bare
                // `return;`, which yields null). Saves a stat() per template compared with probing with is_file().
                // No `@`: warnings raised inside a template must stay visible in the logs.
                if ((include $file) === false) {
                    throw new \RuntimeException("View not found: $template");
                }
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return (string) ob_get_clean();
        };
        return $render();
    }

    public function layout(?string $layout): void
    {
        $this->layout = $layout;
    }

    public function set(string $key, mixed $value): void
    {
        $this->vars[$key] = $value;
    }

    public function get(string $key, mixed $default = ''): mixed
    {
        return $this->vars[$key] ?? $default;
    }
}
