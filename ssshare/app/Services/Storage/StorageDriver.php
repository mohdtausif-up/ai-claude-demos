<?php

declare(strict_types=1);

namespace App\Services\Storage;

/** Private object storage. Implementations: local disk (dev), S3-compatible (production). */
interface StorageDriver
{
    public function put(string $key, string $contents): void;

    public function get(string $key): string;

    /** Writes the object to the output buffer without loading it fully into memory. */
    public function stream(string $key): void;

    /** Streams bytes $start..$end inclusive (HTTP range requests for video). */
    public function streamRange(string $key, int $start, int $end): void;

    public function size(string $key): int;

    public function exists(string $key): bool;

    /** Idempotent: deleting a missing object is not an error. */
    public function delete(string $key): void;
}
