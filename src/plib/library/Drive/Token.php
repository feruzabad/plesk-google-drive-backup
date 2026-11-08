<?php

declare(strict_types=1);

final class Modules_GdriveBackup_Drive_Token
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $token
     * @return OAuthToken|null
     */
    public static function normalize(array $token, ?string $refreshToken = null): ?array
    {
        if (!isset($token['access_token']) || !is_string($token['access_token']) || $token['access_token'] === '') {
            return null;
        }

        $normalized = ['access_token' => $token['access_token']];
        foreach (['expires_in', 'created'] as $key) {
            if (isset($token[$key]) && is_int($token[$key])) {
                $normalized[$key] = $token[$key];
            }
        }
        foreach (['refresh_token', 'scope', 'token_type', 'error', 'error_description'] as $key) {
            if (isset($token[$key]) && is_string($token[$key])) {
                $normalized[$key] = $token[$key];
            }
        }
        if ($refreshToken !== null && !isset($normalized['refresh_token'])) {
            $normalized['refresh_token'] = $refreshToken;
        }

        /** @var OAuthToken $normalized */
        return $normalized;
    }
}
