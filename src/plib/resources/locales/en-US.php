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
        'help' => 'Use a Google Cloud OAuth client with the Drive API enabled and the https://www.googleapis.com/auth/drive.file scope. In the OAuth 2.0 Playground, exchange the authorization code and paste the whole token response; the refresh token is taken from it. The folder is created in My Drive and can be moved later.',
        'clientId' => 'Google OAuth client ID',
        'clientIdDesc' => 'The client ID from your Google Cloud OAuth client.',
        'clientSecret' => 'Google OAuth client secret',
        'clientSecretDesc' => 'Leave blank to keep the existing secret. Re-enter it if you change the client ID.',
        'tokenResponse' => 'Token response',
        'tokenResponseDesc' => 'Paste the whole JSON response from Google\'s token exchange (it contains "refresh_token"). A bare refresh token also works. Leave blank to keep the existing token.',
        'folder' => 'Folder',
        'folderDesc' => 'Folder path in My Drive, for example "Plesk Backups" or "Backups/Plesk". It is created automatically if missing.',
    ],

    'error' => [
        'clientChanged' => 'Re-enter the client secret and token response when changing the client ID.',
        'clientSecretRequired' => 'A Google OAuth client secret is required.',
        'refreshTokenRequired' => 'A Google refresh token is required.',
        'accessToken' => 'This looks like an access token (ya29...). Paste the whole token response or the refresh token instead.',
        'tokenResponseInvalid' => 'The token response is not valid JSON. Copy the whole response, from { to }.',
        'tokenResponseNoRefreshToken' => 'The token response has no "refresh_token". Make sure the authorization requested offline access, then exchange a new authorization code.',
        'clientIdWhitespace' => 'The client ID must not contain spaces or newlines.',
        'folderRequired' => 'Enter a folder name or path.',
    ],
];
