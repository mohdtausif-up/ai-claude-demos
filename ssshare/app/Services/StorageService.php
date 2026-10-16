<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Storage\LocalStorage;
use App\Services\Storage\RegionRouter;
use App\Services\Storage\S3Storage;
use App\Services\Storage\StorageDriver;
use RuntimeException;

final class StorageService
{
    private static ?StorageDriver $driver = null;

    /**
     * The storage driver. Object keys for non-default regions start with "r-{region}/" and are
     * routed to that region's bucket, so callers never need to know where data lives.
     */
    public static function driver(): StorageDriver
    {
        if (self::$driver === null) {
            $regions = [];
            foreach (self::regions() as $name => $cfg) {
                $regions[$name] = self::make($cfg);
            }
            self::$driver = new RegionRouter($regions);
        }
        return self::$driver;
    }

    /** @return array<string, array> region name => driver config */
    public static function regions(): array
    {
        $regions = ['default' => ['driver' => config('app.storage.driver')] + (array) config('app.storage.s3')];
        foreach ((array) config('app.storage.regions') as $name => $bucket) {
            if ($bucket) {
                $regions[$name] = ['driver' => 's3', 'bucket' => $bucket] + (array) config('app.storage.s3');
            }
        }
        return $regions;
    }

    private static function make(array $cfg): StorageDriver
    {
        return match ($cfg['driver']) {
            'local' => new LocalStorage(config('app.storage.local_path')),
            's3' => new S3Storage($cfg['endpoint'], $cfg['region'], $cfg['bucket'], $cfg['key'], $cfg['secret'], (bool) $cfg['path_style']),
            default => throw new RuntimeException('Unsupported storage driver: ' . $cfg['driver']),
        };
    }

    /** Swap the driver (tests). */
    public static function use(?StorageDriver $driver): void
    {
        self::$driver = $driver;
    }

    public static function keyFor(string $uploadUuid, int $version, string $name, string $region = 'default'): string
    {
        $key = sprintf('u/%s/%s/%s/v%d/%s', substr($uploadUuid, 0, 2), substr($uploadUuid, 2, 2), $uploadUuid, $version, $name);
        return $region === 'default' ? $key : "r-$region/$key";
    }
}
