<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_UploadHandle
{
    /** @var list<string> */
    public array $existingIds;

    public string $pending = '';
    public int $uploaded = 0;
    public int $total = 0;
    public bool $closed = false;
    public ?string $fileId = null;
    public ?string $md5 = null;

    /**
     * @param list<string> $existingIds
     */
    public function __construct(
        public string $sessionUri,
        public string $tempName,
        public string $finalName,
        public string $parentId,
        array $existingIds,
    ) {
        $this->existingIds = array_values(array_filter($existingIds));
    }
}
