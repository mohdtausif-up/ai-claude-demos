<?php

declare(strict_types=1);

namespace App\Services;

/** Streaming store-only ZIP writer (photos are already compressed). No ext-zip needed. */
final class ZipWriter
{
    private int $offset = 0;
    /** @var list<array{name:string,crc:int,size:int,offset:int}> */
    private array $central = [];
    /** @var callable */
    private $out;

    public function __construct(callable $out)
    {
        $this->out = $out;
    }

    private function emit(string $bytes): void
    {
        ($this->out)($bytes);
        $this->offset += strlen($bytes);
    }

    public function addFile(string $name, string $path): void
    {
        $data = (string) file_get_contents($path);
        $name = preg_replace('#[\\\\/:*?"<>|\x00-\x1f]#', '_', $name) ?? 'file';
        $crc = crc32($data);
        $this->central[] = ['name' => $name, 'crc' => $crc, 'size' => strlen($data), 'offset' => $this->offset];
        $this->emit("PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, 0, 0, 0x21, $crc, strlen($data), strlen($data), strlen($name), 0) . $name);
        $this->emit($data);
    }

    public function finish(): void
    {
        $start = $this->offset;
        foreach ($this->central as $f) {
            $this->emit("PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, 0, 0x21, $f['crc'], $f['size'], $f['size'], strlen($f['name']), 0, 0, 0, 0, 0, $f['offset']) . $f['name']);
        }
        $size = $this->offset - $start;
        $n = count($this->central);
        $this->emit("PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, $size, $start, 0));
    }
}