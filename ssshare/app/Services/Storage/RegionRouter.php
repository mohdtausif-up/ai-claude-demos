<?php

declare(strict_types=1);

namespace App\Services\Storage;

use RuntimeException;

/** Sends "r-{region}/..." keys to that region's driver and everything else to the default. */
final class RegionRouter implements StorageDriver
{
    /** @param array<string, StorageDriver> $regions */
    public function __construct(private readonly array $regions)
    {
    }

    /** @return array{0:StorageDriver, 1:string} */
    private function route(string $key): array
    {
        if (preg_match('#^r-([a-z0-9]+)/(.+)$#', $key, $m)) {
            $driver = $this->regions[$m[1]] ?? throw new RuntimeException("Storage region {$m[1]} is not configured");
            return [$driver, $m[2]];
        }
        return [$this->regions['default'], $key];
    }

    public function put(string $key, string $contents): void
    {
        [$d, $k] = $this->route($key);
        $d->put($k, $contents);
    }

    public function get(string $key): string
    {
        [$d, $k] = $this->route($key);
        return $d->get($k);
    }

    public function stream(string $key): void
    {
        [$d, $k] = $this->route($key);
        $d->stream($k);
    }

    public function streamRange(string $key, int $start, int $end): void
    {
        [$d, $k] = $this->route($key);
        $d->streamRange($k, $start, $end);
    }

    public function size(string $key): int
    {
        [$d, $k] = $this->route($key);
        return $d->size($k);
    }

    public function exists(string $key): bool
    {
        [$d, $k] = $this->route($key);
        return $d->exists($k);
    }

    public function delete(string $key): void
    {
        [$d, $k] = $this->route($key);
        $d->delete($k);
    }

    public function regionNames(): array
    {
        return array_keys($this->regions);
    }
}
