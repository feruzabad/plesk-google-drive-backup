<?php

declare(strict_types=1);

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

final class Modules_GdriveBackup_SettingsSubForm extends pm_Form_SubForm
{
    /** @var bool */
    protected $_autoInitContext = true;

    /** @var string */
    protected $_objectType = '';

    /** @var int|string|null */
    protected $_objectId = null;

    private ?Modules_GdriveBackup_Settings $settings = null;

    /** @var ValidatedConfig|null */
    private ?array $validated = null;

    private ?string $validatedKey = null;

    #[\Override]
    public function init(): void
    {
        parent::init();

        $settings = $this->settings();

        if (!$settings->isServer()) {
            $this->addElement('SimpleText', 'inherited', [
                'value' => $settings->isConfigured()
                    ? $this->message('form.inherited')
                    : $this->message('form.notConfigured'),
                'placement' => 'prepend',
            ]);
            return;
        }

        if ($settings->isConfigured()) {
            $this->addElement('SimpleText', 'connected', [
                'value' => $this->message('form.connected', [
                    'path' => $settings->getDisplayPath(),
                ]),
                'placement' => 'prepend',
            ]);
        }

        $this->addElement('SimpleText', 'help', [
            'value' => $this->message('form.help'),
            'placement' => 'prepend',
        ]);

        $this->addElement('Text', 'client_id', [
            'label' => $this->message('form.clientId'),
            'value' => $settings->getClientId(),
            'required' => true,
            'description' => $this->message('form.clientIdDesc'),
            'filters' => [['StringTrim']],
        ]);

        $this->addElement('Password', 'client_secret', [
            'label' => $this->message('form.clientSecret'),
            'value' => '',
            'required' => $settings->getClientSecret() === null,
            'description' => $this->message('form.clientSecretDesc'),
            'attribs' => ['autocomplete' => 'off'],
        ]);

        $this->addElement('Password', 'refresh_token', [
            'label' => $this->message('form.refreshToken'),
            'value' => '',
            'required' => $settings->getRefreshToken() === null,
            'description' => $this->message('form.refreshTokenDesc'),
            'attribs' => ['autocomplete' => 'off', 'rows' => 2],
        ]);

        $this->addElement('Text', 'folder', [
            'label' => $this->message('form.folder'),
            'value' => $settings->getFolder() ?? 'Plesk Backups',
            'required' => true,
            'description' => $this->message('form.folderDesc'),
            'filters' => [['StringTrim']],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[\Override]
    public function isValid($data): bool
    {
        if (!parent::isValid($data)) {
            return false;
        }

        $settings = $this->settings();
        if (!$settings->isServer()) {
            return true;
        }

        $clientId = $this->formValue('client_id');
        $clientSecret = $this->formSecret('client_secret');
        $refreshToken = $this->formSecret('refresh_token');
        $folder = $this->formValue('folder');

        $storedClientId = $settings->getClientId();
        if ($storedClientId !== null && $clientId !== '' && $clientId !== $storedClientId) {
            if ($clientSecret === '') {
                $this->elementError('client_secret', $this->message('error.clientChanged'));
            }
            if ($refreshToken === '') {
                $this->elementError('refresh_token', $this->message('error.clientChanged'));
            }
        }

        if ($clientSecret === '') {
            $clientSecret = $settings->getClientSecret() ?? '';
        }
        if ($refreshToken === '') {
            $refreshToken = $settings->getRefreshToken() ?? '';
        }

        if ($clientSecret === '') {
            $this->elementError('client_secret', $this->message('error.clientSecretRequired'));
        }
        if ($refreshToken === '') {
            $this->elementError('refresh_token', $this->message('error.refreshTokenRequired'));
        }
        if (str_starts_with($refreshToken, 'ya29.')) {
            $this->elementError('refresh_token', $this->message('error.accessToken'));
        }
        if (strpbrk($clientId, " \t\r\n") !== false) {
            $this->elementError('client_id', $this->message('error.clientIdWhitespace'));
        }

        try {
            $segments = Modules_GdriveBackup_Drive_Path::normalize($folder);
            if ($segments === []) {
                $this->elementError('folder', $this->message('error.folderRequired'));
            }
        } catch (InvalidArgumentException $e) {
            $this->elementError('folder', $e->getMessage());
        }

        if ($this->elementErrors('client_id') > 0
            || $this->elementErrors('client_secret') > 0
            || $this->elementErrors('refresh_token') > 0
            || $this->elementErrors('folder') > 0
        ) {
            return false;
        }

        $key = sha1(json_encode([$clientId, $clientSecret, $refreshToken, $folder], JSON_THROW_ON_ERROR));
        if ($this->validated !== null && $this->validatedKey === $key) {
            return true;
        }

        $candidate = new Modules_GdriveBackup_Settings($this->_objectType, $this->objectId());
        $candidate->overrideCredentials($clientId, $clientSecret, $refreshToken);
        $candidate->overrideFolder($folder);

        try {
            $gateway = new Modules_GdriveBackup_Drive_Gateway($candidate, false);
            $about = $gateway->about();
            /** @var mixed $user */
            $user = $about->getUser();
            /** @var mixed $email */
            $email = $user instanceof \Google\Service\Drive\User ? $user->getEmailAddress() : null;
            if (!is_string($email)) {
                $email = null;
            }

            $resolver = new Modules_GdriveBackup_Drive_FolderResolver($gateway, $candidate, false);
            $folderId = $resolver->getBaseFolderId($folder);
            $serverFolderId = $resolver->getServerFolderId();

            $this->validated = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $refreshToken,
                'folder' => $folder,
                'folder_id' => $folderId,
                'server_folder_id' => $serverFolderId,
                'account_email' => $email,
            ];
            $this->validatedKey = $key;
        } catch (Throwable $e) {
            $this->elementError('refresh_token', $e->getMessage());
            return false;
        }

        return true;
    }

    #[\Override]
    public function process(): void
    {
        $settings = $this->settings();
        if (!$settings->isServer()) {
            return;
        }

        if ($this->validated !== null) {
            $settings->saveValidated($this->validated);
            return;
        }

        $settings->saveUnvalidated(
            $this->formValue('client_id'),
            $this->formSecret('client_secret') === '' ? null : $this->formSecret('client_secret'),
            $this->formSecret('refresh_token') === '' ? null : $this->formSecret('refresh_token'),
            $this->formValue('folder')
        );
    }

    private function settings(): Modules_GdriveBackup_Settings
    {
        return $this->settings ??= new Modules_GdriveBackup_Settings($this->_objectType, $this->objectId());
    }

    private function objectId(): ?int
    {
        $value = $this->_objectId;
        return is_int($value) ? $value : (is_numeric($value) ? (int)$value : null);
    }

    private function formValue(string $name): string
    {
        $value = $this->getValue($name);
        return is_string($value) ? trim($value) : '';
    }

    private function formSecret(string $name): string
    {
        $value = $this->getValue($name);
        return is_string($value) ? $value : '';
    }

    private function elementErrors(string $name): int
    {
        $element = $this->getElement($name);
        return $element === null ? 0 : count($element->getErrors());
    }

    private function elementError(string $name, string $message): void
    {
        $element = $this->getElement($name);
        if ($element !== null) {
            $element->addError($message);
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
