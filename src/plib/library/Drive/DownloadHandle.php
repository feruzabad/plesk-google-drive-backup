<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_DownloadHandle
{
    public bool $closed = false;

    public int $remaining {
        get => max(0, $this->size - $this->offset);
    }

    public function __construct(
        public readonly string $fileId,
        public private(set) int $offset,
        public readonly int $size,
    ) {
    }

    public function advance(int $bytes): void
    {
        $this->offset += $bytes;
    }
}
