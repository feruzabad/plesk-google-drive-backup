<?php

declare(strict_types=1);

use Google\Service\Drive;
use Google\Service\Drive\About;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

final class Modules_GdriveBackup_Drive_Gateway
{
    public const string SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const string FOLDER_MIME = 'application/vnd.google-apps.folder';
    private const string API_BASE = 'https://www.googleapis.com/drive/v3';
    private const string UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    private const string FILE_FIELDS = 'id,name,mimeType,size,md5Checksum,parents,modifiedTime,trashed';

    private ?\Google\Client $client = null;

    private ?Drive $drive = null;

    private ?GuzzleClient $http = null;

    public function __construct(
        private readonly Modules_GdriveBackup_Settings $settings,
        private readonly bool $persistTokens = true,
    ) {
    }

    public function getClient(): \Google\Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->settings->isConfigured()) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Google Drive is not configured. Enter the client ID, client secret, refresh token, and folder.'
            );
        }

        $client = new \Google\Client([
            'application_name' => 'Plesk Google Drive Remote Storage',
            'client_id' => $this->settings->getClientId(),
            'client_secret' => $this->settings->getClientSecret(),
            'scopes' => [self::SCOPE],
            'access_type' => 'offline',
        ]);
        $client->setLogger(new NullLogger());

        $this->http = new GuzzleClient([
            'timeout' => 300,
            'connect_timeout' => 20,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
        $client->setHttpClient($this->http);

        $client->setTokenCallback(function (string $cacheKey, string $newAccessToken) use ($client): void {
            $current = $client->getAccessToken();
            $token = is_array($current) ? $current : [];
            $token['access_token'] = $newAccessToken;
            $token['expires_in'] = 3600;
            $token['created'] = time();
            $refresh = $this->settings->getRefreshToken();
            if ($refresh !== null) {
                $token['refresh_token'] = $refresh;
            }
            $normalized = Modules_GdriveBackup_Drive_Token::normalize($token, $refresh);
            if ($normalized === null) {
                return;
            }
            $client->setAccessToken($normalized);
            $this->settings->saveAccessToken($normalized, $this->persistTokens);
        });

        $token = $this->settings->getAccessToken();
        if ($token !== null) {
            $client->setAccessToken($token);
            $this->assertScope($token);
        }
        if ($token === null || $client->isAccessTokenExpired()) {
            $this->refreshToken($client);
        }

        $this->client = $client;

        return $client;
    }

    private function refreshToken(\Google\Client $client): void
    {
        $refreshToken = $this->settings->getRefreshToken();
        if ($refreshToken === null) {
            throw new Modules_GdriveBackup_Drive_Exception('No Google refresh token is configured.');
        }

        try {
            /** @var mixed $rawToken */
            $rawToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable $e) {
            throw $this->translateTokenException($e);
        }

        if (!is_array($rawToken)) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Google did not return an access token. Check the client ID, client secret, and refresh token.'
            );
        }
        if (isset($rawToken['error']) && is_string($rawToken['error'])) {
            $description = isset($rawToken['error_description']) && is_string($rawToken['error_description'])
                ? $rawToken['error_description']
                : '';
            throw $this->tokenError(trim($rawToken['error'] . ' ' . $description));
        }

        $token = Modules_GdriveBackup_Drive_Token::normalize($rawToken, $refreshToken);
        if ($token === null) {
            throw new Modules_GdriveBackup_Drive_Exception(
                'Google did not return an access token. Check the client ID, client secret, and refresh token.'
            );
        }
        $client->setAccessToken($token);
        $this->settings->saveAccessToken($token, $this->persistTokens);
        $this->assertScope($token);
    }

    /**
     * @param OAuthToken $token
     */
    private function assertScope(array $token): void
    {
        if (!isset($token['scope']) || $token['scope'] === '') {
            return;
        }
        $scopes = preg_split('/\s+/', $token['scope']);
        if ($scopes === false) {
            return;
        }
        $allowed = array_any(
            $scopes,
            static fn(string $scope): bool => $scope === self::SCOPE
                || $scope === 'https://www.googleapis.com/auth/drive'
        );
        if ($allowed) {
            return;
        }
        throw new Modules_GdriveBackup_Drive_Exception(
            'The refresh token was not granted the drive.file scope. Generate a new refresh token with https://www.googleapis.com/auth/drive.file.'
        );
    }

    public function getDrive(): Drive
    {
        return $this->drive ??= new Drive($this->getClient());
    }

    private function files(): \Google\Service\Drive\Resource\Files
    {
        $files = $this->getDrive()->files;
        if (!$files instanceof \Google\Service\Drive\Resource\Files) {
            throw new Modules_GdriveBackup_Drive_Exception('Google Drive files resource is not available.');
        }
        return $files;
    }

    private function aboutResource(): \Google\Service\Drive\Resource\About
    {
        $about = $this->getDrive()->about;
        if (!$about instanceof \Google\Service\Drive\Resource\About) {
            throw new Modules_GdriveBackup_Drive_Exception('Google Drive about resource is not available.');
        }
        return $about;
    }

    public function about(): About
    {
        $about = $this->call(fn(): mixed => $this->aboutResource()->get(['fields' => 'user,storageQuota']));
        if (!$about instanceof About) {
            throw new Modules_GdriveBackup_Drive_Exception('Google returned an unexpected Drive account response.');
        }
        return $about;
    }

    public function getAccountEmail(): ?string
    {
        /** @var mixed $user */
        $user = $this->about()->getUser();
        if (!$user instanceof \Google\Service\Drive\User) {
            return null;
        }
        /** @var mixed $email */
        $email = $user->getEmailAddress();
        return is_string($email) ? $email : null;
    }

    /**
     * @return Quota
     */
    public function getQuota(): array
    {
        $about = $this->about();
        /** @var mixed $quota */
        $quota = $about->getStorageQuota();
        if (!$quota instanceof \Google\Service\Drive\AboutStorageQuota) {
            return [];
        }
        /** @var mixed $limit */
        $limit = $quota->getLimit();
        if ($limit === null || !is_numeric($limit) || (int)$limit <= 0) {
            return [];
        }
        /** @var mixed $usage */
        $usage = $quota->getUsage();
        $usageInt = is_numeric($usage) ? (int)$usage : 0;
        return [
            'total' => (int)$limit,
            'free' => max(0, (int)$limit - $usageInt),
        ];
    }

    /**
     * @return DriveFile|null
     */
    public function findFolder(
        ?string $name,
        ?string $parentId = null,
        ?string $propertyKey = null,
        ?string $propertyValue = null,
    ): ?array {
        $query = ["mimeType = '" . self::FOLDER_MIME . "'", 'trashed = false'];
        if ($parentId !== null) {
            $query[] = Modules_GdriveBackup_Drive_Query::parent($parentId);
        }
        if ($name !== null) {
            $query[] = Modules_GdriveBackup_Drive_Query::name($name);
        }
        if ($propertyKey !== null && $propertyValue !== null) {
            $query[] = Modules_GdriveBackup_Drive_Query::appProperty($propertyKey, $propertyValue);
        }

        $files = $this->listFiles(implode(' and ', $query), 100);
        return $files[0] ?? null;
    }

    /**
     * @return list<DriveFile>
     */
    public function findFilesByName(string $name, string $parentId): array
    {
        $query = [
            Modules_GdriveBackup_Drive_Query::name($name),
            Modules_GdriveBackup_Drive_Query::parent($parentId),
            'trashed = false',
        ];
        return $this->listFiles(implode(' and ', $query), 100);
    }

    /**
     * @return list<DriveFile>
     */
    public function listFiles(string $query, int $pageSize = 1000): array
    {
        $files = [];
        $pageToken = null;

        do {
            $params = [
                'q' => $query,
                'pageSize' => $pageSize,
                'fields' => 'nextPageToken,files(' . self::FILE_FIELDS . ')',
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->call(fn(): mixed => $this->files()->listFiles($params));
            if (!$response instanceof FileList) {
                throw new Modules_GdriveBackup_Drive_Exception('Google returned an unexpected Drive file list.');
            }

            /** @var mixed $batch */
            $batch = $response->getFiles();
            if (is_array($batch)) {
                foreach ($batch as $file) {
                    if ($file instanceof DriveFile) {
                        $files[] = $this->fileToArray($file);
                    }
                }
            }
            /** @var mixed $next */
            $next = $response->getNextPageToken();
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null);

        return $files;
    }

    /**
     * @return DriveFile|null
     */
    public function getFile(string $fileId): ?array
    {
        try {
            $file = $this->call(fn(): mixed => $this->files()->get($fileId, [
                'fields' => self::FILE_FIELDS,
                'supportsAllDrives' => true,
            ]));
            if (!$file instanceof DriveFile) {
                return null;
            }
            return $this->fileToArray($file);
        } catch (Modules_GdriveBackup_Drive_Exception $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * @param array<string, string> $appProperties
     * @return DriveFile
     */
    public function createFolder(string $name, string $parentId, array $appProperties = []): array
    {
        $file = new DriveFile();
        $file->setName($name);
        $file->setMimeType(self::FOLDER_MIME);
        $file->setParents([$parentId]);
        if ($appProperties !== []) {
            $file->setAppProperties($appProperties);
        }

        $created = $this->call(fn(): mixed => $this->files()->create($file, [
            'fields' => self::FILE_FIELDS,
            'supportsAllDrives' => true,
        ]));
        if (!$created instanceof DriveFile) {
            throw new Modules_GdriveBackup_Drive_Exception('Google returned an unexpected folder response.');
        }
        return $this->fileToArray($created);
    }

    public function deleteFile(string $fileId): void
    {
        try {
            $this->call(fn(): mixed => $this->files()->delete($fileId, ['supportsAllDrives' => true]));
        } catch (Modules_GdriveBackup_Drive_Exception $e) {
            if ($e->getCode() === 404) {
                return;
            }
            throw $e;
        }
    }

    /**
     * @return DriveFile
     */
    public function renameFile(string $fileId, string $newName): array
    {
        $file = new DriveFile();
        $file->setName($newName);

        $updated = $this->call(fn(): mixed => $this->files()->update($fileId, $file, [
            'fields' => self::FILE_FIELDS,
            'supportsAllDrives' => true,
        ]));
        if (!$updated instanceof DriveFile) {
            throw new Modules_GdriveBackup_Drive_Exception('Google returned an unexpected rename response.');
        }
        return $this->fileToArray($updated);
    }

    /**
     * @param array<string, string> $appProperties
     * @return DriveFile
     */
    public function createEmptyFile(string $name, string $parentId, array $appProperties = []): array
    {
        $file = new DriveFile();
        $file->setName($name);
        $file->setMimeType('application/octet-stream');
        $file->setParents([$parentId]);
        if ($appProperties !== []) {
            $file->setAppProperties($appProperties);
        }

        $created = $this->call(fn(): mixed => $this->files()->create($file, [
            'fields' => self::FILE_FIELDS,
            'supportsAllDrives' => true,
        ]));
        if (!$created instanceof DriveFile) {
            throw new Modules_GdriveBackup_Drive_Exception('Google returned an unexpected empty file response.');
        }
        return $this->fileToArray($created);
    }

    /**
     * @param array<string, string> $appProperties
     */
    public function createResumableSession(string $name, string $parentId, array $appProperties = []): string
    {
        $metadata = [
            'name' => $name,
            'parents' => [$parentId],
            'mimeType' => 'application/octet-stream',
        ];
        if ($appProperties !== []) {
            $metadata['appProperties'] = $appProperties;
        }

        $url = self::UPLOAD_URL . '?uploadType=resumable&supportsAllDrives=true&fields=id,name,size,md5Checksum';
        $request = new Request('POST', $url, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'X-Upload-Content-Type' => 'application/octet-stream',
        ], json_encode($metadata, JSON_THROW_ON_ERROR));

        $response = $this->sendAuthorized($request);
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw $this->httpException($response, 'Could not start a Google Drive upload session.');
        }
        $location = $response->getHeaderLine('Location');
        if ($location === '') {
            throw new Modules_GdriveBackup_Drive_Exception('Google did not return a resumable upload URL.');
        }
        return $location;
    }

    /**
     * @return UploadChunkResult
     */
    public function uploadChunk(
        string $sessionUri,
        string $chunk,
        int $offset,
        ?int $total = null,
        bool $isFinal = false,
    ): array {
        $length = strlen($chunk);
        $last = $offset + $length - 1;
        $totalValue = $isFinal ? (string)$total : '*';

        $request = new Request('PUT', $sessionUri, [
            'Content-Length' => (string)$length,
            'Content-Range' => 'bytes ' . $offset . '-' . $last . '/' . $totalValue,
            'Content-Type' => 'application/octet-stream',
        ], $chunk);

        $response = $this->sendAuthorized($request);
        $status = $response->getStatusCode();

        if ($status === 308) {
            $range = $response->getHeaderLine('Range');
            $received = $offset + $length;
            if (preg_match('/bytes=(\d+)-(\d+)/', $range, $matches) === 1) {
                $received = (int)$matches[2] + 1;
            }
            $location = $response->getHeaderLine('Location');
            return [
                'complete' => false,
                'received' => $received,
                'location' => $location !== '' ? $location : null,
                'status' => $status,
            ];
        }

        if ($status >= 200 && $status < 300) {
            $data = json_decode((string)$response->getBody(), true);
            $file = [];
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    if (is_string($key)) {
                        $file[$key] = $value;
                    }
                }
            }
            return [
                'complete' => true,
                'file' => $file,
                'status' => $status,
            ];
        }

        throw $this->httpException($response, 'Google Drive rejected an upload chunk.');
    }

    public function downloadRange(string $fileId, int $offset, int $length): ResponseInterface
    {
        $end = $offset + $length - 1;
        $url = self::API_BASE . '/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
        $request = new Request('GET', $url, [
            'Range' => 'bytes ' . $offset . '-' . $end,
        ]);

        $response = $this->sendAuthorized($request, ['stream' => true]);
        $status = $response->getStatusCode();
        if ($status >= 400 && $status !== 416) {
            throw $this->httpException($response, 'Could not download the file from Google Drive.');
        }
        return $response;
    }

    /**
     * @param array{stream?: bool} $options
     */
    public function sendAuthorized(RequestInterface $request, array $options = []): ResponseInterface
    {
        $client = $this->getClient();
        if ($client->isAccessTokenExpired()) {
            $this->refreshToken($client);
        }
        $http = $this->http;
        if ($http === null) {
            throw new Modules_GdriveBackup_Drive_Exception('Google HTTP client is not initialized.');
        }

        $request = $this->withAuth($request, $client);
        $response = $http->send($request, $options);

        if ($response->getStatusCode() === 401) {
            $this->refreshToken($client);
            $request = $this->withAuth($request, $client);
            $body = $request->getBody();
            if ($body->isSeekable()) {
                $body->rewind();
            }
            $response = $http->send($request, $options);
        }

        return $response;
    }

    private function withAuth(RequestInterface $request, \Google\Client $client): RequestInterface
    {
        $rawToken = $client->getAccessToken();
        if (is_string($rawToken)) {
            $rawToken = ['access_token' => $rawToken];
        }
        /** @var mixed $rawToken */
        $token = is_array($rawToken) ? Modules_GdriveBackup_Drive_Token::normalize($rawToken) : null;
        if ($token === null) {
            throw new Modules_GdriveBackup_Drive_Exception('No Google access token is available.');
        }
        return $request->withHeader('Authorization', 'Bearer ' . $token['access_token']);
    }

    /**
     * @return DriveFile
     */
    private function fileToArray(DriveFile $file): array
    {
        /** @var mixed $mimeType */
        $mimeType = $file->getMimeType();
        /** @var mixed $size */
        $size = $file->getSize();
        /** @var mixed $hash */
        $hash = $file->getMd5Checksum();
        /** @var mixed $parentsRaw */
        $parentsRaw = $file->getParents();
        $parents = [];
        if (is_array($parentsRaw)) {
            foreach ($parentsRaw as $parent) {
                /** @var mixed $parent */
                if (is_string($parent)) {
                    $parents[] = $parent;
                }
            }
        }
        /** @var mixed $modified */
        $modified = $file->getModifiedTime();
        /** @var mixed $trashed */
        $trashed = $file->getTrashed();
        /** @var mixed $id */
        $id = $file->getId();
        /** @var mixed $name */
        $name = $file->getName();

        return [
            'id' => is_string($id) ? $id : '',
            'name' => is_string($name) ? $name : '',
            'mimeType' => is_string($mimeType) ? $mimeType : null,
            'size' => is_numeric($size) ? (int)$size : 0,
            'isDir' => $mimeType === self::FOLDER_MIME,
            'hash' => is_string($hash) && $hash !== '' ? $hash : null,
            'parents' => $parents,
            'modifiedTime' => is_string($modified) ? $modified : null,
            'trashed' => (bool)$trashed,
        ];
    }

    private function httpException(ResponseInterface $response, string $prefix): Modules_GdriveBackup_Drive_Exception
    {
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $json = json_decode($body, true);
        $message = $prefix . ' HTTP ' . $status . '.';
        $reason = null;

        if (is_array($json)) {
            /** @var mixed $error */
            $error = $json['error'] ?? null;
            if (is_array($error)) {
                /** @var mixed $errorMessage */
                $errorMessage = $error['message'] ?? null;
                if (is_string($errorMessage)) {
                    $message .= ' ' . $errorMessage;
                }
                /** @var mixed $errors */
                $errors = $error['errors'] ?? null;
                if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                    /** @var mixed $reasonValue */
                    $reasonValue = $errors[0]['reason'] ?? null;
                    if (is_string($reasonValue)) {
                        $reason = $reasonValue;
                    }
                }
            }
        }

        $retryable = in_array($status, [429, 500, 502, 503, 504], true)
            || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true);
        return new Modules_GdriveBackup_Drive_Exception($message, $status, $retryable, $reason);
    }

    public function translateException(Throwable $e): Modules_GdriveBackup_Drive_Exception
    {
        if ($e instanceof Modules_GdriveBackup_Drive_Exception) {
            return $e;
        }
        if ($e instanceof \Google\Service\Exception) {
            $code = (int)$e->getCode();
            $message = $e->getMessage();
            $reason = null;
            /** @var mixed $errors */
            $errors = $e->getErrors();
            if (is_array($errors) && isset($errors[0]) && is_array($errors[0])) {
                /** @var mixed $errorMessage */
                $errorMessage = $errors[0]['message'] ?? null;
                if (is_string($errorMessage)) {
                    $message = $errorMessage;
                }
                /** @var mixed $reasonValue */
                $reasonValue = $errors[0]['reason'] ?? null;
                if (is_string($reasonValue)) {
                    $reason = $reasonValue;
                }
            }
            $retryable = in_array($code, [429, 500, 502, 503, 504], true)
                || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true);
            return new Modules_GdriveBackup_Drive_Exception(
                $this->humanize($code, $reason, $message),
                $code,
                $retryable,
                $reason,
                $e
            );
        }
        if ($e instanceof \Google\Exception) {
            return $this->translateTokenException($e);
        }
        if ($e instanceof GuzzleException) {
            return new Modules_GdriveBackup_Drive_Exception(
                'Cannot reach Google Drive: ' . $e->getMessage(),
                0,
                true,
                null,
                $e
            );
        }
        return new Modules_GdriveBackup_Drive_Exception($e->getMessage(), (int)$e->getCode(), false, null, $e);
    }

    private function translateTokenException(Throwable $e): Modules_GdriveBackup_Drive_Exception
    {
        $message = $e->getMessage();
        if ($e instanceof BadResponseException) {
            $response = $e->getResponse();
            $json = json_decode((string)$response->getBody(), true);
            if (is_array($json)) {
                if (isset($json['error_description']) && is_string($json['error_description'])) {
                    $message .= ' ' . $json['error_description'];
                } elseif (isset($json['error']) && is_string($json['error'])) {
                    $message .= ' ' . $json['error'];
                }
            }
        }
        $translated = $this->tokenError($message);
        return new Modules_GdriveBackup_Drive_Exception(
            $translated->getMessage(),
            401,
            false,
            $translated->getReason(),
            $e
        );
    }

    private function tokenError(string $text): Modules_GdriveBackup_Drive_Exception
    {
        if (stripos($text, 'invalid_grant') !== false) {
            return new Modules_GdriveBackup_Drive_Exception(
                'Google rejected the refresh token (invalid_grant). It may be expired, revoked, or issued for a different OAuth client. Generate a new refresh token. If the OAuth app is External + Testing, publish it to Production; Testing tokens expire after 7 days.',
                401,
                false,
                'invalid_grant'
            );
        }
        if (stripos($text, 'invalid_client') !== false) {
            return new Modules_GdriveBackup_Drive_Exception(
                'Google rejected the OAuth client (invalid_client). Check the client ID and client secret.',
                401,
                false,
                'invalid_client'
            );
        }
        return new Modules_GdriveBackup_Drive_Exception('Google authorization failed: ' . $text, 401, false, null);
    }

    private function humanize(int $code, ?string $reason, string $message): string
    {
        if ($code === 401) {
            return 'Google authorization failed. Re-check the refresh token, client ID, and client secret.';
        }
        if ($reason === 'storageQuotaExceeded') {
            return 'Google Drive storage is full. Free space in the Google account and retry.';
        }
        if ($reason === 'insufficientPermissions' || $reason === 'insufficientFilePermissions') {
            return 'The Google account does not have permission to access this Drive folder. Check the account and the drive.file scope.';
        }
        if ($reason === 'accessNotConfigured') {
            return 'The Google Drive API is not enabled in the Google Cloud project. Enable it and retry.';
        }
        if ($reason === 'rateLimitExceeded' || $reason === 'userRateLimitExceeded') {
            return 'Google Drive rate limit reached. Retrying did not succeed; try again later.';
        }
        if ($code === 404) {
            return 'The requested Google Drive item was not found.';
        }
        return 'Google Drive API error' . ($code !== 0 ? ' (HTTP ' . $code . ')' : '') . ': ' . $message;
    }

    /**
     * Run a Drive service call with translation and bounded retries.
     *
     * @param callable(): mixed $callable
     */
    private function call(callable $callable): mixed
    {
        return Modules_GdriveBackup_Drive_Retry::run(function () use ($callable): mixed {
            try {
                return $callable();
            } catch (Throwable $e) {
                throw $this->translateException($e);
            }
        });
    }
}
