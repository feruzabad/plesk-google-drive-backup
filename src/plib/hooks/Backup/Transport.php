<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

final class Modules_GdriveBackup_Backup_Transport extends pm_Hook_Backup_Transport
{
    private ?Modules_GdriveBackup_Settings $settings = null;

    private ?Modules_GdriveBackup_Drive_FileSystem $fs = null;

    #[\Override]
    public function init($objectType, $objectId): void
    {
        parent::init($objectType, $objectId);
        /** @var mixed $objectIdValue */
        $objectIdValue = $objectId;
        /** @var mixed $objectTypeValue */
        $objectTypeValue = $objectType;
        $objectIdInt = is_int($objectIdValue)
            ? $objectIdValue
            : (is_numeric($objectIdValue) ? (int)$objectIdValue : null);
        $this->settings = new Modules_GdriveBackup_Settings(
            is_string($objectTypeValue) ? $objectTypeValue : '',
            $objectIdInt
        );
        $this->fs = new Modules_GdriveBackup_Drive_FileSystem($this->settings);
    }

    #[\Override]
    public function isConfigured(): bool
    {
        return $this->settings?->isConfigured() ?? false;
    }

    #[\Override]
    public function getDescription(): string
    {
        $settings = $this->settings;
        if ($settings === null || !$settings->isConfigured()) {
            return $this->message('transport.descriptionShort');
        }

        $isAdmin = false;
        try {
            $client = pm_Session::getClient();
            $isAdmin = (bool)$client->isAdmin();
        } catch (Throwable) {
            $isAdmin = false;
        }

        if (!$isAdmin) {
            return $this->message('transport.descriptionShort');
        }

        return $this->message('transport.description', [
            'path' => $settings->getDisplayPath(),
        ]);
    }

    /**
     * @return CheckResult
     */
    #[\Override]
    public function check(): array
    {
        $fs = $this->fs;
        if ($fs === null) {
            return ['errors' => ['Google Drive transport is not initialized.'], 'warnings' => []];
        }
        try {
            return $fs->check();
        } catch (Throwable $e) {
            return ['errors' => [$e->getMessage()], 'warnings' => []];
        }
    }

    /**
     * @return array{name: string, size: int, isDir: bool, hash?: string}|null
     */
    #[\Override]
    public function stat($path): ?array
    {
        return $this->run(function () use ($path): ?array {
            pm_Log::debug('Google Drive transport: stat ' . $path);
            return $this->fs()->stat($path);
        });
    }

    /**
     * @param string $path
     * @return list<array{name: string, size: int, isDir: bool, hash?: string}>
     */
    #[\Override]
    public function listDir($path): array
    {
        return $this->run(function () use ($path): array {
            pm_Log::debug('Google Drive transport: listDir ' . $path);
            return $this->fs()->listDir($path);
        });
    }

    #[\Override]
    public function createDir($path): void
    {
        $this->run(function () use ($path): void {
            pm_Log::debug('Google Drive transport: createDir ' . $path);
            $this->fs()->createDir($path);
        });
    }

    #[\Override]
    public function deleteFile($path): void
    {
        $this->run(function () use ($path): void {
            pm_Log::debug('Google Drive transport: deleteFile ' . $path);
            $this->fs()->deleteFile($path);
        });
    }

    #[\Override]
    public function deleteDir($path): void
    {
        $this->run(function () use ($path): void {
            pm_Log::debug('Google Drive transport: deleteDir ' . $path);
            $this->fs()->deleteDir($path);
        });
    }

    #[\Override]
    public function openFileRead($path, $offset = 0): Modules_GdriveBackup_Drive_DownloadHandle
    {
        return $this->run(function () use ($path, $offset): Modules_GdriveBackup_Drive_DownloadHandle {
            /** @var mixed $offsetValue */
            $offsetValue = $offset;
            $offsetInt = is_int($offsetValue)
                ? $offsetValue
                : (is_numeric($offsetValue) ? (int)$offsetValue : 0);
            pm_Log::debug('Google Drive transport: openFileRead ' . $path . ' offset ' . $offsetInt);
            return $this->fs()->openFileRead($path, $offsetInt);
        });
    }

    #[\Override]
    public function readFile($fd, $localFile, $size = null): int
    {
        return $this->run(function () use ($fd, $localFile, $size): int {
            if (!$fd instanceof Modules_GdriveBackup_Drive_DownloadHandle) {
                throw new pm_Exception('Invalid Google Drive download handle.');
            }
            /** @var mixed $sizeValue */
            $sizeValue = $size;
            $sizeInt = is_int($sizeValue)
                ? $sizeValue
                : (is_numeric($sizeValue) ? (int)$sizeValue : null);
            return $this->fs()->readFile($fd, $localFile, $sizeInt);
        });
    }

    #[\Override]
    public function openFileWrite($path): Modules_GdriveBackup_Drive_UploadHandle
    {
        return $this->run(function () use ($path): Modules_GdriveBackup_Drive_UploadHandle {
            pm_Log::debug('Google Drive transport: openFileWrite ' . $path);
            return $this->fs()->openFileWrite($path);
        });
    }

    #[\Override]
    public function appendFile($fd, $localFile): int
    {
        return $this->run(function () use ($fd, $localFile): int {
            if (!$fd instanceof Modules_GdriveBackup_Drive_UploadHandle) {
                throw new pm_Exception('Invalid Google Drive upload handle.');
            }
            return $this->fs()->appendFile($fd, $localFile);
        });
    }

    #[\Override]
    public function closeFile($fd): void
    {
        if (!$fd instanceof Modules_GdriveBackup_Drive_UploadHandle
            && !$fd instanceof Modules_GdriveBackup_Drive_DownloadHandle
        ) {
            throw new pm_Exception('Invalid Google Drive file handle.');
        }
        $this->run(function () use ($fd): void {
            $this->fs()->closeFile($fd);
        });
    }

    #[\Override]
    public function getSettingsSubForm(): pm_Form_SubForm
    {
        return new Modules_GdriveBackup_SettingsSubForm([
            'context' => [
                'objectType' => $this->_objectType,
                'objectId' => $this->_objectId,
            ],
        ]);
    }

    #[\Override]
    public function authorize(Zend_Controller_Action $controller): void
    {
        // The extension uses a manually supplied refresh token; no OAuth callback is required.
    }

    /**
     * @return Quota
     */
    #[\Override]
    public function getQuota(): array
    {
        $fs = $this->fs;
        if ($fs === null) {
            return [];
        }
        try {
            return $fs->getQuota();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{type: string, blockSize: int}
     */
    #[\Override]
    public function getHash(): array
    {
        return ['type' => 'md5', 'blockSize' => 0];
    }

    public function getReadBufferSize(): int
    {
        return 16 * 1024 * 1024;
    }

    public function getWriteBufferSize(): int
    {
        return 16 * 1024 * 1024;
    }

    private function fs(): Modules_GdriveBackup_Drive_FileSystem
    {
        return $this->fs ?? throw new pm_Exception('Google Drive transport is not initialized.');
    }

    /**
     * @template T
     * @param callable(): T $callable
     * @return T
     */
    private function run(callable $callable): mixed
    {
        try {
            return $callable();
        } catch (pm_Exception $e) {
            throw $e;
        } catch (Throwable $e) {
            pm_Log::err('Google Drive transport: ' . $e->getMessage());
            throw new pm_Exception($e->getMessage());
        }
    }

    /**
     * @param array<string, string> $params
     */
    private function message(string $key, array $params = []): string
    {
        /** @var mixed $message */
        $message = pm_Locale::lmsg($key, $params);
        return is_string($message) ? $message : $key;
    }
}
