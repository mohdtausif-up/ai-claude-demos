<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

final class LocalStorage implements StorageDriver
{
    public function __construct(private readonly string $root)
    {
    }

    public function put(string $key, string $contents): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create storage directory for $key");
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.part';
        if (file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents) || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Unable to write object $key");
        }
    }

    public function get(string $key): string
    {
        $contents = @file_get_contents($this->path($key));
        if ($contents === false) {
            throw new RuntimeException("Object $key not found");
        }
        return $contents;
    }

    public function stream(string $key): void
    {
        $handle = fopen($this->path($key), 'rb');
        if ($handle === false) {
            throw new RuntimeException("Object $key not found");
        }
        while (!feof($handle)) {
            echo fread($handle, 65536);
            flush();
        }
        fclose($handle);
    }

    public function streamRange(string $key, int $start, int $end): void
    {
        $handle = fopen($this->path($key), 'rb');
        if ($handle === false) {
            throw new RuntimeException("Object $key not found");
        }
        fseek($handle, $start);
        $remaining = $end - $start + 1;
        while ($remaining > 0 && !feof($handle)) {
            $chunk = fread($handle, min(65536, $remaining));
            echo $chunk;
            flush();
            $remaining -= strlen($chunk);
        }
        fclose($handle);
    }

    public function size(string $key): int
    {
        $size = @filesize($this->path($key));
        if ($size === false) {
            throw new RuntimeException("Object $key not found");
        }
        return $size;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Unable to delete object $key");
        }
        // Prune now-empty parent directories up to the storage root.
        $dir = dirname($path);
        while ($dir !== $this->root && str_starts_with($dir, $this->root) && is_dir($dir) && count(scandir($dir)) === 2) {
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }

    private function path(string $key): string
    {
        if (!preg_match('#^[A-Za-z0-9/_.-]+$#', $key) || str_contains($key, '..')) {
            throw new RuntimeException('Invalid object key');
        }
        return $this->root . '/' . $key;
    }
}
