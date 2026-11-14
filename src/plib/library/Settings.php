<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Settings
{
    public const string CONFIG_KEY = 'gdrive_cfg';
    public const string CLIENT_SECRET_KEY = 'gdrive_client_secret';
    public const string REFRESH_TOKEN_KEY = 'gdrive_refresh_token';
    public const string ACCESS_TOKEN_KEY = 'gdrive_access_token';

    private readonly Modules_GdriveBackup_Drive_ObjectType $objectType;

    private readonly ?int $objectId;

    /** @var SettingsConfig */
    private array $config = [];

    private ?string $clientSecret = null;

    private ?string $refreshToken = null;

    /** @var OAuthToken|null */
    private ?array $accessToken = null;

    /** @var SettingsConfig */
    private array $overrides = [];

    public function __construct(string $objectType, ?int $objectId)
    {
        $this->objectType = Modules_GdriveBackup_Drive_ObjectType::fromPlesk($objectType);
        $this->objectId = $objectId;
        $this->load();
    }

    private function load(): void
    {
        $raw = $this->setting(self::CONFIG_KEY);
        if ($raw !== null && json_validate($raw)) {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                $config = [];
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && is_string($value)) {
                        $config[$key] = $value;
                    }
                }
                $this->config = $config;
            }
        }

        $this->clientSecret = $this->decryptedSetting(self::CLIENT_SECRET_KEY);
        $this->refreshToken = $this->decryptedSetting(self::REFRESH_TOKEN_KEY);

        $tokenRaw = $this->decryptedSetting(self::ACCESS_TOKEN_KEY);
        if ($tokenRaw !== null && json_validate($tokenRaw)) {
            try {
                $token = json_decode($tokenRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $token = null;
            }
            if (is_array($token)) {
                $this->accessToken = Modules_GdriveBackup_Drive_Token::normalize($token);
            }
        }
    }

    private function setting(string $name): ?string
    {
        $value = pm_Settings::get($name, null);
        return is_string($value) ? $value : null;
    }

    private function decryptedSetting(string $name): ?string
    {
        try {
            $value = pm_Settings::getDecrypted($name, null);
            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable $e) {
            pm_Log::debug('Google Drive settings: cannot decrypt ' . $name . ': ' . $e->getMessage());
            return null;
        }
    }

    public function isServer(): bool
    {
        return $this->objectType === Modules_GdriveBackup_Drive_ObjectType::Server;
    }

    public function getObjectType(): Modules_GdriveBackup_Drive_ObjectType
    {
        return $this->objectType;
    }

    public function getObjectId(): ?int
    {
        return $this->objectId;
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== null
            && $this->getClientSecret() !== null
            && $this->getRefreshToken() !== null
            && $this->getFolder() !== null;
    }

    public function getClientId(): ?string
    {
        return $this->overrides['client_id'] ?? $this->config['client_id'] ?? null;
    }

    public function getClientSecret(): ?string
    {
        return $this->overrides['client_secret'] ?? $this->clientSecret;
    }

    public function getRefreshToken(): ?string
    {
        return $this->overrides['refresh_token'] ?? $this->refreshToken;
    }

    public function getFolder(): ?string
    {
        return $this->overrides['folder'] ?? $this->config['folder'] ?? null;
    }

    public function getFolderId(): ?string
    {
        return $this->config['folder_id'] ?? null;
    }

    public function getServerFolderId(): ?string
    {
        return $this->config['server_folder_id'] ?? null;
    }

    public function getAccountEmail(): ?string
    {
        return $this->config['account_email'] ?? null;
    }

    /**
     * @return OAuthToken|null
     */
    public function getAccessToken(): ?array
    {
        // Candidate credentials must not reuse an access token from the saved configuration.
        if ($this->overrides !== []) {
            return null;
        }
        return $this->accessToken;
    }

    /**
     * @param OAuthToken $token
     */
    public function saveAccessToken(array $token, bool $persist = true): void
    {
        $this->accessToken = $token;
        if ($persist) {
            pm_Settings::setEncrypted(self::ACCESS_TOKEN_KEY, json_encode($token, JSON_THROW_ON_ERROR));
        }
    }

    public function clearAccessToken(bool $persist = true): void
    {
        $this->accessToken = null;
        if ($persist) {
            pm_Settings::set(self::ACCESS_TOKEN_KEY, null);
        }
    }

    public function overrideCredentials(
        string $clientId,
        #[\SensitiveParameter] string $clientSecret,
        #[\SensitiveParameter] string $refreshToken,
    ): void {
        $this->overrides['client_id'] = $clientId;
        $this->overrides['client_secret'] = $clientSecret;
        $this->overrides['refresh_token'] = $refreshToken;
        $this->accessToken = null;
    }

    public function overrideFolder(string $folder): void
    {
        $this->overrides['folder'] = $folder;
    }

    /**
     * @param ValidatedConfig $data
     */
    public function saveValidated(#[\SensitiveParameter] array $data): void
    {
        $config = [
            'client_id' => $data['client_id'],
            'folder' => $data['folder'],
            'folder_id' => $data['folder_id'],
            'server_folder_id' => $data['server_folder_id'],
        ];
        if ($data['account_email'] !== null) {
            $config['account_email'] = $data['account_email'];
        }
        pm_Settings::set(self::CONFIG_KEY, json_encode($config, JSON_THROW_ON_ERROR));
        pm_Settings::setEncrypted(self::CLIENT_SECRET_KEY, $data['client_secret']);
        pm_Settings::setEncrypted(self::REFRESH_TOKEN_KEY, $data['refresh_token']);

        $this->config = $config;
        $this->clientSecret = $data['client_secret'];
        $this->refreshToken = $data['refresh_token'];
        $this->overrides = [];
        $this->clearAccessToken();
    }

    /**
     * Save fields without live validation. Used only as a safety net if Plesk
     * calls process() without isValid().
     */
    public function saveUnvalidated(
        string $clientId,
        #[\SensitiveParameter] ?string $clientSecret,
        #[\SensitiveParameter] ?string $refreshToken,
        string $folder,
    ): void {
        $config = [
            'client_id' => $clientId,
            'folder' => $folder,
        ];
        pm_Settings::set(self::CONFIG_KEY, json_encode($config, JSON_THROW_ON_ERROR));
        if ($clientSecret !== null) {
            pm_Settings::setEncrypted(self::CLIENT_SECRET_KEY, $clientSecret);
            $this->clientSecret = $clientSecret;
        }
        if ($refreshToken !== null) {
            pm_Settings::setEncrypted(self::REFRESH_TOKEN_KEY, $refreshToken);
            $this->refreshToken = $refreshToken;
        }

        $this->config = $config;
        $this->overrides = [];
        $this->clearAccessToken();
    }

    /**
     * @param SettingsConfig $values
     */
    public function updateConfig(array $values): void
    {
        $this->config = array_merge($this->config, $values);
        pm_Settings::set(self::CONFIG_KEY, json_encode($this->config, JSON_THROW_ON_ERROR));
    }

    public function getHostNamespace(): string
    {
        $host = gethostname();
        if ($host === false) {
            $host = php_uname('n');
        }
        $host = trim($host);
        $host = preg_replace('/[\\\\\/]+/', '-', $host);
        if ($host === null || $host === '') {
            $host = 'plesk-server';
        }
        return $host;
    }

    public function getObjectKey(): string
    {
        return $this->objectType->objectKey($this->objectId);
    }

    public function getDisplayPath(): string
    {
        $parts = [$this->getFolder(), $this->getHostNamespace(), $this->getObjectKey()];
        $parts = array_filter(
            $parts,
            static fn(?string $part): bool => $part !== null && $part !== ''
        );
        return implode('/', $parts);
    }
}
