<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_FolderResolver
{
    private const string PROP_ROOT = 'pleskRoot';
    private const string PROP_OBJECT = 'pleskObject';

    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly Modules_GdriveBackup_Drive_Gateway $gateway,
        private readonly Modules_GdriveBackup_Settings $settings,
        private readonly bool $persist = true,
    ) {
    }

    /**
     * Find or create the configured folder path in My Drive.
     */
    public function getBaseFolderId(?string $folderPath = null): string
    {
        $folderPath ??= $this->settings->getFolder();
        $segments = Modules_GdriveBackup_Drive_Path::normalize($folderPath);
        if ($segments === []) {
            throw new Modules_GdriveBackup_Drive_Exception('The configured Google Drive folder is empty.');
        }

        $key = 'base:' . implode('/', $segments);
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $cachedId = $this->persist ? $this->settings->getFolderId() : null;
        if ($cachedId !== null) {
            $meta = $this->getFolderMetaById($cachedId);
            if ($meta !== null) {
                return $this->cache[$key] = $cachedId;
            }
        }

        $hash = $this->hash(implode('/', $segments));
        $meta = $this->gateway->findFolder(null, null, self::PROP_ROOT, $hash);
        if ($meta !== null) {
            $id = $meta['id'];
            if ($this->persist) {
                $this->settings->updateConfig(['folder_id' => $id]);
            }
            return $this->cache[$key] = $id;
        }

        $parentId = 'root';
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $meta = $this->gateway->findFolder($segment, $parentId);
            if ($meta !== null) {
                $parentId = $meta['id'];
                continue;
            }
            $properties = $index === $lastIndex ? [self::PROP_ROOT => $hash] : [];
            $created = $this->gateway->createFolder($segment, $parentId, $properties);
            $parentId = $created['id'];
        }

        if ($this->persist) {
            $this->settings->updateConfig(['folder_id' => $parentId]);
        }
        return $this->cache[$key] = $parentId;
    }

    /**
     * Find or create the folder for the current Plesk object, directly under
     * the base folder (for example "Plesk Backups/server" or
     * "Plesk Backups/example.com").
     *
     * The folder is identified by a property derived from the object key
     * ("subscription-<id>"), so a domain rename keeps the same folder and its
     * backups; the folder is then renamed to match. An untagged folder named
     * after the object key (from an older version, or moved by hand) is
     * tagged and renamed.
     */
    public function getObjectFolderId(): string
    {
        $baseId = $this->getBaseFolderId();
        $objectKey = $this->settings->getObjectKey();
        $key = 'object:' . $baseId . ':' . $objectKey;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $name = $this->settings->getObjectFolderName();
        $properties = [self::PROP_OBJECT => $this->hash($this->settings->getHostNamespace() . ':' . $objectKey)];

        $meta = $this->gateway->findFolder(null, $baseId, self::PROP_OBJECT, $properties[self::PROP_OBJECT]);
        if ($meta !== null) {
            if ($meta['name'] !== $name) {
                $this->renameFolder($meta['id'], $name);
            }
            return $this->cache[$key] = $meta['id'];
        }

        $meta = $this->gateway->findFolder($objectKey, $baseId);
        if ($meta !== null) {
            $this->renameFolder($meta['id'], $name, $properties);
            return $this->cache[$key] = $meta['id'];
        }

        $meta = $this->gateway->createFolder($name, $baseId, $properties);
        return $this->cache[$key] = $meta['id'];
    }

    /**
     * A folder that keeps its old name still works, so a failed rename must
     * not fail the backup; the next lookup tries again.
     *
     * @param array<string, string> $appProperties
     */
    private function renameFolder(string $id, string $name, array $appProperties = []): void
    {
        try {
            $this->gateway->renameFile($id, $name, $appProperties);
        } catch (Modules_GdriveBackup_Drive_Exception $e) {
            pm_Log::warn('Could not rename the Google Drive folder ' . $id . ' to "' . $name . '": ' . $e->getMessage());
        }
    }

    /**
     * @return DriveFile|null
     */
    private function getFolderMetaById(string $id): ?array
    {
        try {
            $meta = $this->gateway->getFile($id);
            if ($meta !== null && $meta['isDir'] && !$meta['trashed']) {
                return $meta;
            }
        } catch (Modules_GdriveBackup_Drive_Exception) {
            // Treat a missing/deleted cached folder as a cache miss.
        }
        return null;
    }

    private function hash(string $value): string
    {
        return substr(hash('sha256', $value), 0, 40);
    }
}
