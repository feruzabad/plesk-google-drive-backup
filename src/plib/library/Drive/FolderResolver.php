<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_FolderResolver
{
    private const string PROP_ROOT = 'pleskRoot';
    private const string PROP_SERVER = 'pleskServer';
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
     * Find or create the folder that represents this Plesk server.
     */
    public function getServerFolderId(): string
    {
        $baseId = $this->getBaseFolderId();
        $key = 'server:' . $baseId;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $cachedId = $this->persist ? $this->settings->getServerFolderId() : null;
        if ($cachedId !== null) {
            $meta = $this->getFolderMetaById($cachedId);
            if ($meta !== null) {
                return $this->cache[$key] = $cachedId;
            }
        }

        $host = $this->settings->getHostNamespace();
        $hash = $this->hash($host);
        $meta = $this->gateway->findFolder(null, null, self::PROP_SERVER, $hash);
        if ($meta === null) {
            $meta = $this->gateway->findFolder($host, $baseId);
        }
        if ($meta === null) {
            $meta = $this->gateway->createFolder($host, $baseId, [self::PROP_SERVER => $hash]);
        }

        $id = $meta['id'];
        if ($this->persist) {
            $this->settings->updateConfig(['server_folder_id' => $id]);
        }
        return $this->cache[$key] = $id;
    }

    /**
     * Find or create the folder for the current Plesk object.
     */
    public function getObjectFolderId(): string
    {
        $serverId = $this->getServerFolderId();
        $name = $this->settings->getObjectKey();
        $key = 'object:' . $serverId . ':' . $name;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $hash = $this->hash($this->settings->getHostNamespace() . ':' . $name);
        $meta = $this->gateway->findFolder(null, null, self::PROP_OBJECT, $hash);
        if ($meta === null) {
            $meta = $this->gateway->findFolder($name, $serverId);
        }
        if ($meta === null) {
            $meta = $this->gateway->createFolder($name, $serverId, [self::PROP_OBJECT => $hash]);
        }

        return $this->cache[$key] = $meta['id'];
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
