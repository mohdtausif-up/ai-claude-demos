<?php

declare(strict_types=1);

use App\Support\Config;
use App\Support\Csrf;

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function base_path(string $path = ''): string
{
    return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** HTML-escape for output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

function url(string $path = ''): string
{
    return config('app.url') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = base_path('public/assets/' . $path);
    $version = is_file($file) ? substr(md5((string) filemtime($file)), 0, 8) : '0';
    return '/assets/' . $path . '?v=' . $version;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function now(): DateTimeImmutable
{
    return \App\Support\Clock::now();
}

/** MySQL DATETIME string in UTC. */
function db_time(?DateTimeInterface $time = null): string
{
    return ($time ?? now())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function format_bytes(int|float $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $bytes : rtrim(rtrim(number_format($bytes, 1), '0'), '.')) . ' ' . $units[$i];
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Keyed hash of an IP. Raw IP addresses are never persisted. */
function ip_hash(?string $ip = null): string
{
    return hash_hmac('sha256', 'ip|' . ($ip ?? client_ip()), config('app.key'));
}
