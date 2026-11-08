<?php

$messages = [
    'transport' => [
        'description' => 'Google Drive: %%path%%',
        'descriptionShort' => 'Google Drive (managed by administrator)',
    ],

    'form' => [
        'connected' => 'Connected: %%path%%',
        'inherited' => 'Google Drive is configured server-wide by the administrator. Manual subscription backups can use it.',
        'notConfigured' => 'Google Drive remote storage has not been configured by the administrator.',
        'help' => 'Use a Google Cloud OAuth client with the Drive API enabled and the https://www.googleapis.com/auth/drive.file scope. Paste a refresh token, not an access token. The folder is created in My Drive and can be moved later.',
        'clientId' => 'Google OAuth client ID',
        'clientIdDesc' => 'The client ID from your Google Cloud OAuth client.',
        'clientSecret' => 'Google OAuth client secret',
        'clientSecretDesc' => 'Leave blank to keep the existing secret. Re-enter it if you change the client ID.',
        'refreshToken' => 'Refresh token',
        'refreshTokenDesc' => 'Leave blank to keep the existing token. Must be a refresh token authorized with the drive.file scope, not an access token.',
        'folder' => 'Folder',
        'folderDesc' => 'Folder path in My Drive, for example "Plesk Backups" or "Backups/Plesk". It is created automatically if missing.',
    ],

    'error' => [
        'clientChanged' => 'Re-enter the client secret and refresh token when changing the client ID.',
        'clientSecretRequired' => 'A Google OAuth client secret is required.',
        'refreshTokenRequired' => 'A Google refresh token is required.',
        'accessToken' => 'This looks like an access token (ya29...). Paste a refresh token instead.',
        'clientIdWhitespace' => 'The client ID must not contain spaces or newlines.',
        'folderRequired' => 'Enter a folder name or path.',
    ],
];
