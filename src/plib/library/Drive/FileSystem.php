<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_FileSystem
{
    private const int CHUNK_SIZE = 16_777_216;
    private const int READ_BLOCK_SIZE = 1_048_576;
    private const int MAX_ATTEMPTS = 4;
    private const string TEMP_PREFIX = '.plesk-tmp-';

    private readonly Modules_GdriveBackup_Drive_Gateway $gateway;

    private readonly Modules_GdriveBackup_Drive_FolderResolver $folders;

    /** @var array<string, string|null> */
    private array $pathCache = [];

    /** @var array<string, DriveFile|null> */
    private array $entryCache = [];

    /** @var array<string, list<DriveFile>> */
    private array $listCache = [];

    /** @var DriveFile|null */
    private ?array $rootMeta = null;

    public function __construct(
        private readonly Modules_GdriveBackup_Settings $settings,
        ?Modules_GdriveBackup_Drive_Gateway $gateway = null,
        ?Modules_GdriveBackup_Drive_FolderResolver $folders = null,
    ) {
        $this->gateway = $gateway ?? new Modules_GdriveBackup_Drive_Gateway($settings);
        $this->folders = $folders ?? new Modules_GdriveBackup_Drive_FolderResolver($this->gateway, $settings);
    }

    public function getGateway(): Modules_GdriveBackup_Drive_Gateway
    {
        return $this->gateway;
    }

    private function getObjectRootId(): string
    {
        return $this->folders->getObjectFolderId();
    }

    /**
     * @return DriveFile
     */
    private function getObjectRootMeta(): array
    {
        if ($this->rootMeta !== null) {
            return $this->rootMeta;
        }
        $rootId = $this->getObjectRootId();
        $meta = $this->gateway->getFile($rootId);
        if ($meta === null || !$meta['isDir']) {
            throw new Modules_GdriveBackup_Drive_Exception('The Google Drive object folder no longer exists.');
        }
        $meta['name'] = $this->settings->getObjectKey();
        $meta['size'] = 0;
        return $this->rootMeta = $meta;
    }

    private function resolveFolderId(?string $path, bool $create): ?string
    {
        $segments = Modules_GdriveBackup_Drive_Path::normalize($path);
        if ($segments === []) {
            return $this->getObjectRootId();
        }

        $cacheKey = ($create ? 'c:' : 'r:') . implode('/', $segments);
        if (array_key_exists($cacheKey, $this->pathCache)) {
            return $this->pathCache[$cacheKey];
        }

        return $this->pathCache[$cacheKey] = $this->resolveParentId($segments, $create);
    }

    /**
     * @param list<string> $segments
     */
    private function resolveParentId(array $segments, bool $create): ?string
    {
        $parentId = $this->getObjectRootId();

        foreach ($segments as $segment) {
            $entry = $this->findEntryIn($segment, $parentId);
            if ($entry !== null) {
                if (!$entry['isDir']) {
                    throw new Modules_GdriveBackup_Drive_Exception('A file exists where a folder is required: ' . $segment);
                }
                $parentId = $entry['id'];
                continue;
            }
            if (!$create) {
                return null;
            }
            $created = $this->gateway->createFolder($segment, $parentId);
            $parentId = $created['id'];
        }

        return $parentId;
    }

    /**
     * @return DriveFile|null
     */
    private function findEntryIn(string $name, string $parentId): ?array
    {
        $cacheKey = $parentId . '/' . $name;
        if (array_key_exists($cacheKey, $this->entryCache)) {
            return $this->entryCache[$cacheKey];
        }

        $files = $this->gateway->findFilesByName($name, $parentId);
        $selected = null;
        foreach ($files as $file) {
            if (str_starts_with($file['name'], self::TEMP_PREFIX)) {
                continue;
            }
            if ($selected === null || ($file['modifiedTime'] ?? '') > ($selected['modifiedTime'] ?? '')) {
                $selected = $file;
            }
        }

        return $this->entryCache[$cacheKey] = $selected;
    }

    /**
     * @return DriveFile|null
     */
    private function getPathMeta(?string $path): ?array
    {
        $segments = Modules_GdriveBackup_Drive_Path::normalize($path);
        if ($segments === []) {
            return $this->getObjectRootMeta();
        }

        $cacheKey = 'p:' . implode('/', $segments);
        if (array_key_exists($cacheKey, $this->entryCache)) {
            return $this->entryCache[$cacheKey];
        }

        $parentSegments = $segments;
        array_pop($parentSegments);
        $parentId = $this->resolveParentId($parentSegments, false);
        if ($parentId === null) {
            $this->pathCache[$cacheKey] = null;
            return null;
        }

        $meta = $this->findEntryIn($segments[count($segments) - 1], $parentId);
        $this->entryCache[$cacheKey] = $meta;
        return $meta;
    }

    /**
     * @return array{name: string, size: int, isDir: bool, hash?: string}|null
     */
    public function stat(?string $path): ?array
    {
        $meta = $this->getPathMeta($path);
        if ($meta === null) {
            return null;
        }

        $result = [
            'name' => $meta['name'],
            'size' => $meta['isDir'] ? 0 : $meta['size'],
            'isDir' => $meta['isDir'],
        ];
        if ($meta['hash'] !== null) {
            $result['hash'] = $meta['hash'];
        }
        return $result;
    }

    /**
     * @return list<array{name: string, size: int, isDir: bool, hash?: string}>
     */
    public function listDir(string $path): array
    {
        $folderId = $this->resolveFolderId($path, false);
        if ($folderId === null) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Google Drive folder not found: ' . Modules_GdriveBackup_Drive_Path::normalizeString($path)
            );
        }

        if (isset($this->listCache[$folderId])) {
            $children = $this->listCache[$folderId];
        } else {
            $children = $this->gateway->listFiles(
                Modules_GdriveBackup_Drive_Query::parent($folderId) . ' and trashed = false',
                1000
            );
            $this->listCache[$folderId] = $children;
        }

        /** @var array<string, DriveFile> $byName */
        $byName = [];
        foreach ($children as $child) {
            $name = $child['name'];
            if (str_starts_with($name, self::TEMP_PREFIX)) {
                continue;
            }
            $this->entryCache[$folderId . '/' . $name] = $child;
            if (!isset($byName[$name]) || ($child['modifiedTime'] ?? '') > ($byName[$name]['modifiedTime'] ?? '')) {
                $byName[$name] = $child;
            }
        }
        ksort($byName);

        $result = [];
        foreach ($byName as $child) {
            $item = [
                'name' => $child['name'],
                'size' => $child['isDir'] ? 0 : $child['size'],
                'isDir' => $child['isDir'],
            ];
            if ($child['hash'] !== null) {
                $item['hash'] = $child['hash'];
            }
            $result[] = $item;
        }
        return $result;
    }

    public function createDir(string $path): void
    {
        $folderId = $this->resolveFolderId($path, true);
        if ($folderId === null) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Could not create Google Drive folder: ' . Modules_GdriveBackup_Drive_Path::normalizeString($path)
            );
        }
        $this->clearCaches();
    }

    public function deleteFile(string $path): void
    {
        $segments = Modules_GdriveBackup_Drive_Path::normalize($path);
        if ($segments === []) {
            throw new Modules_GdriveBackup_Drive_Exception('Cannot delete the Google Drive object root as a file.');
        }
        $parentSegments = $segments;
        array_pop($parentSegments);
        $parentId = $this->resolveParentId($parentSegments, false);
        if ($parentId === null) {
            return;
        }
        $name = $segments[count($segments) - 1];
        $files = $this->gateway->findFilesByName($name, $parentId);
        $deleted = false;
        foreach ($files as $file) {
            if ($file['isDir']) {
                continue;
            }
            $this->gateway->deleteFile($file['id']);
            $deleted = true;
        }
        if ($deleted) {
            $this->clearCaches();
        }
    }

    public function deleteDir(string $path): void
    {
        $segments = Modules_GdriveBackup_Drive_Path::normalize($path);
        $folderId = $segments === [] ? $this->getObjectRootId() : $this->resolveFolderId($path, false);
        if ($folderId === null) {
            return;
        }

        $baseId = $this->folders->getBaseFolderId();
        $serverId = $this->folders->getServerFolderId();
        if ($folderId === $baseId || $folderId === $serverId) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Refusing to delete the configured Google Drive base or server folder.'
            );
        }

        $this->gateway->deleteFile($folderId);
        $this->clearCaches();
    }

    public function openFileWrite(string $path): Modules_GdriveBackup_Drive_UploadHandle
    {
        $segments = Modules_GdriveBackup_Drive_Path::normalize($path);
        if ($segments === []) {
            throw new Modules_GdriveBackup_Drive_Exception('Cannot open the Google Drive object root as a file.');
        }
        $name = $segments[count($segments) - 1];
        $parentSegments = $segments;
        array_pop($parentSegments);
        $parentId = $this->resolveParentId($parentSegments, true);
        if ($parentId === null) {
            throw new Modules_GdriveBackup_Drive_Exception('Could not resolve the Google Drive parent folder.');
        }

        $existing = $this->gateway->findFilesByName($name, $parentId);
        $existingIds = [];
        foreach ($existing as $file) {
            if ($file['isDir']) {
                throw new Modules_GdriveBackup_Drive_Exception('A folder exists where a backup file is expected: ' . $name);
            }
            $existingIds[] = $file['id'];
        }

        $tempName = self::TEMP_PREFIX . bin2hex(random_bytes(8));
        $sessionUri = $this->gateway->createResumableSession($tempName, $parentId, ['pleskTemp' => '1']);
        return new Modules_GdriveBackup_Drive_UploadHandle($sessionUri, $tempName, $name, $parentId, $existingIds);
    }

    public function appendFile(Modules_GdriveBackup_Drive_UploadHandle $handle, string $localFile): int
    {
        $fp = @fopen($localFile, 'rb');
        if ($fp === false) {
            throw new Modules_GdriveBackup_Drive_Exception('Cannot read the local backup chunk: ' . $localFile);
        }

        $written = 0;
        try {
            while (!feof($fp)) {
                $block = fread($fp, 8 * 1024 * 1024);
                if ($block === false) {
                    throw new Modules_GdriveBackup_Drive_Exception('Failed to read the local backup chunk.');
                }
                if ($block === '') {
                    break;
                }
                $handle->pending .= $block;
                $written += strlen($block);
                $this->flushPending($handle);
            }
        } finally {
            fclose($fp);
        }

        $handle->total = $handle->uploaded + strlen($handle->pending);
        return $written;
    }

    private function flushPending(Modules_GdriveBackup_Drive_UploadHandle $handle): void
    {
        while (strlen($handle->pending) > self::CHUNK_SIZE) {
            $chunk = substr($handle->pending, 0, self::CHUNK_SIZE);
            $handle->pending = substr($handle->pending, self::CHUNK_SIZE);
            $this->uploadChunkWithRetry($handle, $chunk, false, null);
        }
        $handle->total = $handle->uploaded + strlen($handle->pending);
    }

    /**
     * @return UploadChunkResult
     */
    private function uploadChunkWithRetry(
        Modules_GdriveBackup_Drive_UploadHandle $handle,
        string $chunk,
        bool $isFinal,
        ?int $total,
    ): array {
        $offset = $handle->uploaded;
        $attempt = 0;

        while (true) {
            try {
                $result = $this->gateway->uploadChunk(
                    $handle->sessionUri,
                    $chunk,
                    $offset,
                    $isFinal ? $total : null,
                    $isFinal
                );

                if (isset($result['location']) && $result['location'] !== '') {
                    $handle->sessionUri = $result['location'];
                }

                if ($result['complete']) {
                    if (!$isFinal) {
                        throw new Modules_GdriveBackup_Drive_Exception(
                            'Google Drive closed the upload before all backup data was sent.'
                        );
                    }
                    $file = isset($result['file']) ? $result['file'] : [];
                    $fileId = $file['id'] ?? null;
                    if (is_string($fileId)) {
                        $handle->fileId = $fileId;
                    }
                    $md5 = $file['md5Checksum'] ?? null;
                    if (is_string($md5)) {
                        $handle->md5 = $md5;
                    }
                    $handle->uploaded = $offset + strlen($chunk);
                    return $result;
                }

                $received = isset($result['received']) ? $result['received'] : 0;
                $sentEnd = $offset + strlen($chunk);
                if ($received >= $sentEnd) {
                    $handle->uploaded = $sentEnd;
                    return $result;
                }
                if ($received > $offset) {
                    $chunk = substr($chunk, $received - $offset);
                    $offset = $received;
                    $handle->uploaded = $offset;
                    continue;
                }

                $attempt++;
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new Modules_GdriveBackup_Drive_Exception(
                        'Google Drive did not acknowledge the upload chunk.',
                        0,
                        true
                    );
                }
                $this->sleepBeforeRetry($attempt);
            } catch (Modules_GdriveBackup_Drive_Exception $e) {
                $attempt++;
                if (!$e->isRetryable() || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->sleepBeforeRetry($attempt);
            }
        }
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        $delay = min(30, 1 << min(5, $attempt));
        $delay += random_int(0, 1000) / 1000;
        usleep((int)($delay * 1_000_000));
    }

    public function closeFile(Modules_GdriveBackup_Drive_UploadHandle|Modules_GdriveBackup_Drive_DownloadHandle $handle): void
    {
        if ($handle instanceof Modules_GdriveBackup_Drive_DownloadHandle) {
            $handle->closed = true;
            return;
        }
        if ($handle->closed) {
            return;
        }

        if ($handle->uploaded === 0 && $handle->pending === '' && $handle->total === 0) {
            $created = $this->gateway->createEmptyFile($handle->finalName, $handle->parentId);
            $handle->fileId = $created['id'];
            foreach ($handle->existingIds as $id) {
                if ($id !== $handle->fileId) {
                    $this->gateway->deleteFile($id);
                }
            }
            $handle->closed = true;
            $this->clearCaches();
            return;
        }

        if ($handle->pending === '') {
            throw new Modules_GdriveBackup_Drive_Exception('The Google Drive upload has no final chunk to send.');
        }

        $total = $handle->uploaded + strlen($handle->pending);
        $chunk = $handle->pending;
        $handle->pending = '';
        $this->uploadChunkWithRetry($handle, $chunk, true, $total);

        $fileId = $handle->fileId;
        if ($fileId === null) {
            throw new Modules_GdriveBackup_Drive_Exception('Google Drive did not return the completed file ID.');
        }

        $this->gateway->renameFile($fileId, $handle->finalName);
        foreach ($handle->existingIds as $id) {
            if ($id !== $fileId) {
                $this->gateway->deleteFile($id);
            }
        }

        $handle->closed = true;
        $this->clearCaches();
    }

    public function openFileRead(string $path, int $offset = 0): Modules_GdriveBackup_Drive_DownloadHandle
    {
        $meta = $this->getPathMeta($path);
        if ($meta === null) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Google Drive file not found: ' . Modules_GdriveBackup_Drive_Path::normalizeString($path)
            );
        }
        if ($meta['isDir']) {
            throw new Modules_GdriveBackup_Drive_Exception('Cannot read a Google Drive folder as a file.');
        }
        return new Modules_GdriveBackup_Drive_DownloadHandle($meta['id'], max(0, $offset), $meta['size']);
    }

    public function readFile(
        Modules_GdriveBackup_Drive_DownloadHandle $handle,
        string $localFile,
        ?int $size = null,
    ): int {
        $remaining = $handle->remaining;
        if ($remaining <= 0) {
            file_put_contents($localFile, '');
            return 0;
        }

        $want = $size === null ? $remaining : min($size, $remaining);
        if ($want <= 0) {
            file_put_contents($localFile, '');
            return 0;
        }

        $totalWritten = 0;
        $attempt = 0;

        while ($totalWritten < $want) {
            $offset = $handle->offset + $totalWritten;
            $length = $want - $totalWritten;
            try {
                $response = $this->gateway->downloadRange($handle->fileId, $offset, $length);
                $status = $response->getStatusCode();
                if ($status === 416) {
                    break;
                }
                if ($status !== 200 && $status !== 206) {
                    throw new Modules_GdriveBackup_Drive_Exception(
                        'Unexpected Google Drive download response (HTTP ' . $status . ').',
                        $status
                    );
                }

                $body = $response->getBody();
                $skip = $status === 200 && $offset > 0 ? $offset : 0;
                while ($skip > 0) {
                    $discarded = $body->read(min(self::READ_BLOCK_SIZE, $skip));
                    if ($discarded === '') {
                        break;
                    }
                    $skip -= strlen($discarded);
                }

                $mode = $totalWritten === 0 ? 'wb' : 'ab';
                $fp = @fopen($localFile, $mode);
                if ($fp === false) {
                    throw new Modules_GdriveBackup_Drive_Exception('Cannot write the local backup file: ' . $localFile);
                }

                $copied = 0;
                try {
                    while ($copied < $length) {
                        $block = $body->read(min(self::READ_BLOCK_SIZE, $length - $copied));
                        if ($block === '') {
                            break;
                        }
                        $n = fwrite($fp, $block);
                        if ($n === false) {
                            throw new Modules_GdriveBackup_Drive_Exception('Failed to write the local backup file.');
                        }
                        $copied += $n;
                    }
                } finally {
                    fclose($fp);
                }

                if ($copied === 0) {
                    break;
                }
                $totalWritten += $copied;
                $attempt = 0;
            } catch (Modules_GdriveBackup_Drive_Exception $e) {
                $attempt++;
                if (!$e->isRetryable() || $attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                $this->sleepBeforeRetry($attempt);
            }
        }

        $handle->advance($totalWritten);
        return $totalWritten;
    }

    /**
     * @return Quota
     */
    public function getQuota(): array
    {
        try {
            return $this->gateway->getQuota();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return CheckResult
     */
    public function check(): array
    {
        $errors = [];
        $warnings = [];

        if (!$this->settings->isConfigured()) {
            return [
                'errors' => ['Google Drive is not configured. Open Remote Storage Settings and enter the OAuth credentials and folder.'],
                'warnings' => [],
            ];
        }

        try {
            $this->gateway->about();
            $this->folders->getBaseFolderId();
            $this->folders->getServerFolderId();
            $this->folders->getObjectFolderId();

            $quota = $this->gateway->getQuota();
            if ($quota !== [] && $quota['free'] < 1_073_741_824) {
                $warnings[] = 'Google Drive has less than 1 GiB of free space.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function clearCaches(): void
    {
        $this->pathCache = [];
        $this->entryCache = [];
        $this->listCache = [];
        $this->rootMeta = null;
    }
}
